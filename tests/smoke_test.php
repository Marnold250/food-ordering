<?php
declare(strict_types=1);

// End-to-end smoke test for the dine-in QR flow.
// Usage: php tests/smoke_test.php http://localhost/food-ordering
// Needs the demo data from database/food_ordering.sql (table T1, kitchen@demo.com / password).

if (PHP_SAPI !== 'cli') {
    exit;
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is required.\n");
    exit(1);
}

$base = rtrim($argv[1] ?? 'http://localhost/food-ordering', '/');
$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function client(): array
{
    return ['cookies' => tempnam(sys_get_temp_dir(), 'smoke')];
}

function request(array $client, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $client['cookies'],
        CURLOPT_COOKIEFILE => $client['cookies'],
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $result = ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'body' => $body];
    curl_close($ch);
    return $result;
}

function csrf(string $html): string
{
    return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

$guest = client();
$home = request($guest, $base . '/index.php');
check($home['status'] === 200, 'Home page loads');

$invalid = request($guest, $base . '/index.php?table=NOPE');
check(str_contains($invalid['body'], 'not active'), 'Unknown table QR is rejected');

$menu = request($guest, $base . '/index.php?table=T1');
check(str_contains($menu['body'], 'Table T1'), 'Scanning table T1 opens the menu with the table banner');

preg_match('/name="item_id" value="(\d+)"/', $menu['body'], $item);
check(!empty($item[1]), 'Menu lists an item that can be added to the cart');
request($guest, $base . '/actions.php', ['csrf' => csrf($menu['body']), 'action' => 'add_cart', 'item_id' => $item[1] ?? 0, 'quantity' => 2]);

$checkout = request($guest, $base . '/index.php?page=checkout');
check(str_contains($checkout['body'], 'value="dine_in"') && !str_contains($checkout['body'], 'name="address"'), 'Guest checkout offers dine-in without a delivery address');

$placed = request($guest, $base . '/actions.php', [
    'csrf' => csrf($checkout['body']), 'action' => 'place_order', 'order_type' => 'delivery',
    'name' => 'Smoke Test', 'phone' => '0780000000', 'payment_method' => 'card', 'notes' => 'smoke test',
]);
check(str_contains($placed['url'], 'page=order-success'), 'Dine-in order placed as a guest');
preg_match('/[?&]id=(\d+)/', $placed['url'], $order);
$orderId = (int) ($order[1] ?? 0);

$track = request($guest, $base . '/index.php?page=track&id=' . $orderId);
check(str_contains($track['body'], 'Ready to serve'), 'Guest can track the dine-in order');

$other = request(client(), $base . '/index.php?page=track&id=' . $orderId);
check(!str_contains($other['body'], 'Ready to serve'), 'Another visitor cannot see the order');

$kitchen = client();
$login = request($kitchen, $base . '/index.php?page=login');
request($kitchen, $base . '/actions.php', ['csrf' => csrf($login['body']), 'action' => 'login', 'email' => 'kitchen@demo.com', 'password' => 'password']);
$ticket = request($kitchen, $base . '/print_ticket.php?id=' . $orderId);
check(str_contains($ticket['body'], 'TABLE T1') && str_contains($ticket['body'], 'Dine-in · Table T1'), 'Kitchen ticket shows the table number');

$dashboard = request($kitchen, $base . '/dashboard.php?page=orders');
check(str_contains($dashboard['body'], 'Dine-in · Table T1'), 'Kitchen order list shows the table');

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : $failures . ' check(s) failed.') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
