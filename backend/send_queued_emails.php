<?php
declare(strict_types=1);

// Retries emails that failed to send. Run from the command line, e.g. every 5 minutes:
//   C:\xampp\php\php.exe C:\xampp\htdocs\food-ordering\backend\send_queued_emails.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/core.php';

$maxAttempts = 5;
$emails = query('SELECT * FROM email_queue WHERE sent_at IS NULL AND attempts < ? ORDER BY id LIMIT 50', [$maxAttempts])->fetchAll();
$sent = 0;
foreach ($emails as $email) {
    if (mail_send($email['to_email'], $email['subject'], $email['html_body'], (string) ($email['text_body'] ?? ''))) {
        query('UPDATE email_queue SET sent_at=NOW(), attempts=attempts+1 WHERE id=?', [$email['id']]);
        $sent++;
    } else {
        query('UPDATE email_queue SET attempts=attempts+1 WHERE id=?', [$email['id']]);
    }
}
echo 'Sent ' . $sent . ' of ' . count($emails) . ' queued email(s).' . PHP_EOL;
