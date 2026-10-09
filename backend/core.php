<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $value): string
{
    return '₹' . number_format((float) $value, 0);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    $sessionToken = $_SESSION['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('Your session expired. Please go back and try again.');
    }
}

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function logged_in(): bool
{
    return user() !== null;
}

function has_role(array|string $roles): bool
{
    return logged_in() && in_array(user()['role'], (array) $roles, true);
}

function require_login(array|string|null $roles = null): void
{
    if (!logged_in()) {
        flash('error', 'Please sign in to continue.');
        redirect('index.php?page=login');
    }
    if ($roles !== null && !has_role($roles)) {
        $isCustomer = has_role('customer');
        flash('error', $isCustomer ? 'Customer accounts do not have admin access.' : 'Your account does not have permission to access this page.');
        redirect($isCustomer ? 'index.php?page=account' : 'dashboard.php');
    }
}

function redirect(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function setting(string $key, string $default = ''): string
{
    static $settings = null;
    if ($settings === null) {
        try {
            $settings = query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {
            $settings = [];
        }
    }
    return (string) ($settings[$key] ?? $default);
}

function cart(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return array_sum(array_map(static fn ($item) => (int) $item['quantity'], cart()));
}

function cart_totals(): array
{
    $subtotal = 0.0;
    foreach (cart() as $item) {
        $subtotal += (float) $item['price'] * (int) $item['quantity'];
    }
    $discount = 0.0;
    if (!empty($_SESSION['coupon']) && $subtotal > 0) {
        $coupon = query('SELECT * FROM coupons WHERE code=? AND status=1 AND valid_until>=CURDATE() AND used_count<usage_limit', [$_SESSION['coupon']])->fetch();
        if ($coupon && $subtotal >= (float) $coupon['minimum_order']) {
            $discount = $coupon['type'] === 'percent'
                ? $subtotal * ((float) $coupon['value'] / 100)
                : (float) $coupon['value'];
            if ($coupon['max_discount'] !== null) {
                $discount = min($discount, (float) $coupon['max_discount']);
            }
            $discount = round(min($discount, $subtotal), 2);
            $_SESSION['discount'] = $discount;
        } else {
            unset($_SESSION['coupon'], $_SESSION['discount']);
        }
    }
    $delivery = dine_in_table() || $subtotal >= (float) setting('free_delivery_threshold', '499') || $subtotal === 0.0 ? 0.0 : (float) setting('delivery_fee', '49');
    $tax = round(($subtotal - $discount) * ((float) setting('tax_rate', '5') / 100), 2);
    return compact('subtotal', 'discount', 'delivery', 'tax') + [
        'total' => max(0, $subtotal - $discount + $delivery + $tax),
    ];
}

function order_number(): string
{
    return 'FF' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function status_meta(string $status): array
{
    return match ($status) {
        'pending' => ['Pending', 'warning', 'bi-hourglass-split'],
        'confirmed' => ['Confirmed', 'info', 'bi-check-circle'],
        'preparing' => ['Preparing', 'primary', 'bi-fire'],
        'ready' => ['Ready', 'success', 'bi-bag-check'],
        'out_for_delivery' => ['On the way', 'primary', 'bi-scooter'],
        'delivered' => ['Delivered', 'success', 'bi-check2-all'],
        'cancelled' => ['Cancelled', 'danger', 'bi-x-circle'],
        default => [ucwords(str_replace('_', ' ', $status)), 'secondary', 'bi-circle'],
    };
}

function food_image(string $image): string
{
    if (str_starts_with($image, 'http')) {
        return $image;
    }
    return base_url($image ?: 'frontend/assets/images/food-placeholder.svg');
}

function store_uploaded_image(array $file, string $existing = ''): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Image upload failed or exceeds the 2 MB limit.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG, WebP or GIF image.');
    }
    $directory = dirname(__DIR__) . '/uploads/menu';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the upload directory.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    return 'uploads/menu/' . $name;
}

function public_url(string $path = ''): string
{
    $appUrl = rtrim(env('APP_URL'), '/');
    if ($appUrl === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $appUrl = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(base_url(), '/');
    }
    return $appUrl . '/' . ltrim($path, '/');
}

function table_order_url(string $tableNumber): string
{
    return public_url('index.php?table=' . rawurlencode($tableNumber));
}

function dine_in_table(): ?array
{
    return $_SESSION['dine_in_table'] ?? null;
}

function order_type_label(array $order): string
{
    return match ($order['order_type']) {
        'dine_in' => 'Dine-in' . (!empty($order['table_number']) ? ' · Table ' . $order['table_number'] : ''),
        'pickup' => 'Pickup',
        default => 'Delivery',
    };
}

function can_view_order(array $order): bool
{
    if (has_role('customer') && (int) $order['user_id'] === (int) user()['id']) {
        return true;
    }
    return in_array((int) $order['id'], $_SESSION['guest_orders'] ?? [], true);
}

function release_table_if_idle(?string $tableNumber): void
{
    if (!$tableNumber) {
        return;
    }
    $open = (int) query('SELECT COUNT(*) FROM orders WHERE table_number=? AND order_type="dine_in" AND status NOT IN ("delivered","cancelled")', [$tableNumber])->fetchColumn();
    if ($open === 0) {
        query('UPDATE restaurant_tables SET status="free" WHERE table_number=? AND status="occupied"', [$tableNumber]);
    }
}

function page_title(string $title = ''): string
{
    return $title ? $title . ' · ' . APP_NAME : APP_NAME . ' · Flavours delivered fast';
}

function mail_send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $host = env('MAIL_HOST');
    $port = (int) env('MAIL_PORT', '587');
    $username = env('MAIL_USERNAME');
    $password = env('MAIL_PASSWORD');
    $encryption = env('MAIL_ENCRYPTION', 'tls');
    $fromAddress = env('MAIL_FROM_ADDRESS', 'noreply@cafe.test');
    $fromName = env('MAIL_FROM_NAME', APP_NAME);

    if (!$host || !$username || !$password) {
        error_log("Mail not configured: missing SMTP credentials");
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'From: ' . $fromName . ' <' . $fromAddress . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Reply-To: ' . $fromAddress,
    ];

    if ($textBody) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="__BOUNDARY__"';
        $message = "--__BOUNDARY__\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $textBody . "\r\n";
        $message .= "--__BOUNDARY__\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $message .= $htmlBody . "\r\n";
        $message .= "--__BOUNDARY__--";
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $message = $htmlBody;
    }
    $message = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message));

    $smtp = @fsockopen(($encryption === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
    if (!$smtp) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($smtp, 10);

    $read = function ($expect = '') use ($smtp) {
        $response = '';
        while (($line = fgets($smtp, 512)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        if ($expect && !preg_match("/^$expect/", $response)) {
            error_log("SMTP unexpected response: $response");
            return false;
        }
        return true;
    };

    $write = function ($cmd) use ($smtp) {
        fwrite($smtp, $cmd . "\r\n");
    };

    if (!$read('220')) return false;
    $write('EHLO ' . gethostname());
    if (!$read('250')) return false;

    if ($encryption === 'tls') {
        $write('STARTTLS');
        if (!$read('220')) return false;
        stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write('EHLO ' . gethostname());
        if (!$read('250')) return false;
    }

    $write('AUTH LOGIN');
    if (!$read('334')) return false;
    $write(base64_encode($username));
    if (!$read('334')) return false;
    $write(base64_encode($password));
    if (!$read('235')) return false;

    $write('MAIL FROM:<' . $fromAddress . '>');
    if (!$read('250')) return false;
    $write('RCPT TO:<' . $to . '>');
    if (!$read('250')) return false;
    $write('DATA');
    if (!$read('354')) return false;
    $write(implode("\r\n", $headers));
    $write('');
    $write($message);
    $write('.');
    if (!$read('250')) return false;
    $write('QUIT');
    fclose($smtp);
    return true;
}

function mail_queue(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    try {
        query(
            'INSERT INTO email_queue (to_email, subject, html_body, text_body, attempts) VALUES (?,?,?,?,0)',
            [$to, $subject, $htmlBody, $textBody]
        );
    } catch (Throwable) {
    }
}

function send_order_confirmation(array $order): void
{
    $subject = 'Order Confirmation - ' . $order['order_number'];
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
    $html .= '<h2 style="color:#f4511e;">Order Confirmed!</h2>';
    $html .= '<p>Hi ' . e($order['customer_name']) . ',</p>';
    $html .= '<p>Thank you for ordering from ' . APP_NAME . '. Your order <strong>' . e($order['order_number']) . '</strong> has been received.</p>';
    $html .= '<table style="width:100%;border-collapse:collapse;">';
    $html .= '<tr style="background:#f4511e;color:white;"><th style="padding:10px;text-align:left;">Item</th><th style="padding:10px;text-align:right;">Qty</th><th style="padding:10px;text-align:right;">Price</th></tr>';
    $items = query('SELECT * FROM order_items WHERE order_id=?', [$order['id']])->fetchAll();
    foreach ($items as $item) {
        $html .= '<tr style="border-bottom:1px solid #eee;"><td style="padding:10px;">' . e($item['item_name']) . '</td><td style="padding:10px;text-align:right;">' . (int)$item['quantity'] . '</td><td style="padding:10px;text-align:right;">' . money($item['item_price'] * $item['quantity']) . '</td></tr>';
    }
    $html .= '<tr><td colspan="2" style="padding:10px;text-align:right;"><strong>Total</strong></td><td style="padding:10px;text-align:right;"><strong>' . money($order['total']) . '</strong></td></tr>';
    $html .= '</table>';
    $html .= '<p>Estimated delivery: ' . (int)$order['estimated_minutes'] . ' minutes.</p>';
    $html .= '<p><a href="' . e(public_url('index.php?page=track&id=' . $order['id'])) . '" style="background:#f4511e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;">Track Order</a></p>';
    $html .= '<hr><small>If you didn\'t place this order, please contact us.</small></div>';

    $email = (string) ($order['customer_email'] ?? '');
    if ($email !== '' && !mail_send($email, $subject, $html)) {
        mail_queue($email, $subject, $html);
    }
}

function send_order_status_update(array $order, string $oldStatus, string $newStatus): void
{
    $labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'ready' => 'Ready for pickup/delivery',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
    $subject = 'Order Update - ' . $order['order_number'];
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
    $html .= '<h2 style="color:#f4511e;">Order Status Update</h2>';
    $html .= '<p>Hi ' . e($order['customer_name']) . ',</p>';
    $html .= '<p>Your order <strong>' . e($order['order_number']) . '</strong> status has changed:</p>';
    $html .= '<p style="font-size:18px;font-weight:bold;color:#f4511e;">' . ($labels[$newStatus] ?? $newStatus) . '</p>';
    $html .= '<p><a href="' . e(public_url('index.php?page=track&id=' . $order['id'])) . '" style="background:#f4511e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;">Track Order</a></p>';
    $html .= '<hr><small>' . APP_NAME . ' - Flavours delivered fast</small></div>';

    $email = ($order['user_id'] ? query('SELECT email FROM users WHERE id=?', [$order['user_id']])->fetchColumn() : false) ?: ($order['customer_email'] ?? '');
    if ($email && !mail_send($email, $subject, $html)) {
        mail_queue($email, $subject, $html);
    }
}

function send_password_reset(string $email, string $token): void
{
    $subject = 'Password Reset - ' . APP_NAME;
    $resetUrl = e(public_url('index.php?page=reset-password&token=' . $token));
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
    $html .= '<h2 style="color:#f4511e;">Reset Your Password</h2>';
    $html .= '<p>You requested a password reset. Click the button below to set a new password:</p>';
    $html .= '<p style="text-align:center;margin:30px 0;"><a href="' . $resetUrl . '" style="background:#f4511e;color:white;padding:12px 30px;text-decoration:none;border-radius:4px;">Reset Password</a></p>';
    $html .= '<p>This link expires in 1 hour. If you didn\'t request this, please ignore this email.</p>';
    $html .= '<hr><small>' . APP_NAME . ' - Flavours delivered fast</small></div>';

    if (!mail_send($email, $subject, $html)) {
        mail_queue($email, $subject, $html);
    }
}

function send_welcome_email(string $email, string $name): void
{
    $subject = 'Welcome to ' . APP_NAME . '!';
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
    $html .= '<h2 style="color:#f4511e;">Welcome to ' . APP_NAME . '!</h2>';
    $html .= '<p>Hi ' . e($name) . ',</p>';
    $html .= '<p>Thanks for joining us. Use code <strong>WELCOME100</strong> for ₹100 off your first order (min ₹299).</p>';
    $html .= '<p><a href="' . e(public_url('index.php?page=menu')) . '" style="background:#f4511e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;">Explore Menu</a></p>';
    $html .= '<hr><small>' . APP_NAME . ' - Flavours delivered fast</small></div>';

    if (!mail_send($email, $subject, $html)) {
        mail_queue($email, $subject, $html);
    }
}

function send_contact_notification(string $name, string $email, string $message): void
{
    $subject = 'New Contact Form Message - ' . APP_NAME;
    $to = env('MAIL_FROM_ADDRESS');
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
    $html .= '<h2 style="color:#f4511e;">New Contact Message</h2>';
    $html .= '<p><strong>Name:</strong> ' . e($name) . '</p>';
    $html .= '<p><strong>Email:</strong> ' . e($email) . '</p>';
    $html .= '<p><strong>Message:</strong></p><p>' . nl2br(e($message)) . '</p>';
    $html .= '</div>';

    if (!mail_send($to, $subject, $html)) {
        mail_queue($to, $subject, $html);
    }
}
