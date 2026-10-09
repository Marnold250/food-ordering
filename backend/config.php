<?php
declare(strict_types=1);

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, '"\'');
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$secure = (bool) ($_ENV['SESSION_SECURE'] ?? false);
$httponly = (bool) ($_ENV['SESSION_HTTPONLY'] ?? true);
$samesite = $_ENV['SESSION_SAMESITE'] ?? 'lax';
$lifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 7200);

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => $httponly,
    'samesite' => $samesite,
]);
session_start();

const APP_NAME = 'Cafe';
const DB_HOST = '127.0.0.1';
const DB_NAME = 'food_ordering';
const DB_USER = 'root';
const DB_PASS = 'mahoro@250';

function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? getenv($key) ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        http_response_code(500);
        exit(
            '<div style="font-family:Arial;max-width:680px;margin:60px auto;padding:28px;border:1px solid #fecaca;border-radius:16px">'
            . '<h2 style="color:#dc2626">Database connection failed</h2>'
            . '<p>Start Apache and MySQL in XAMPP, then import <code>database/food_ordering.sql</code> in phpMyAdmin.</p>'
            . '<small>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</small></div>'
        );
    }

    return $pdo;
}

function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = $script === '/' ? '' : rtrim($script, '/');
    return $base . '/' . ltrim($path, '/');
}