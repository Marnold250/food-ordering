<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/core.php';
require_login(['admin', 'kitchen', 'staff']);

$orderId = (int) ($_GET['id'] ?? 0);
$order = query('SELECT * FROM orders WHERE id=?', [$orderId])->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found');
}
$items = query('SELECT * FROM order_items WHERE order_id=?', [$orderId])->fetchAll();
$restaurantName = setting('restaurant_name');
$restaurantPhone = setting('restaurant_phone');
$restaurantAddress = setting('restaurant_address');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Kitchen Ticket - <?= e($order['order_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 20px; background: #fff; color: #000; }
        .ticket { max-width: 320px; margin: 0 auto; border: 2px dashed #000; padding: 15px; }
        .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 18px; font-weight: bold; }
        .header .subtitle { font-size: 11px; margin-top: 2px; }
        .info { display: flex; justify-content: space-between; margin: 8px 0; font-size: 11px; }
        .info .label { font-weight: bold; }
        .items { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0; }
        .item { margin: 8px 0; }
        .item .name { font-weight: bold; }
        .item .details { font-size: 10px; }
        .item .qty { float: right; }
        .instructions { font-size: 10px; color: #666; margin-left: 10px; font-style: italic; }
        .footer { text-align: center; margin-top: 15px; font-size: 10px; }
        .qr-placeholder { text-align: center; margin: 10px 0; }
        @media print { body { padding: 0; } .ticket { border: none; max-width: 100%; } .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="no-print" style="text-align:center;margin-bottom:15px;">
    <button onclick="window.print()" style="padding:10px 20px;font-size:14px;background:#f4511e;color:#fff;border:none;border-radius:4px;cursor:pointer;">Print Ticket</button>
    <a href="dashboard.php?page=orders" style="margin-left:10px;padding:10px 20px;font-size:14px;background:#6c757d;color:#fff;border:none;border-radius:4px;text-decoration:none;">Back</a>
</div>
<div class="ticket">
    <div class="header">
        <h1><?= e($restaurantName) ?></h1>
        <div class="subtitle">KITCHEN TICKET</div>
        <div class="subtitle"><?= e($restaurantPhone) ?> | <?= e($restaurantAddress) ?></div>
    </div>
    <div class="info"><span class="label">Order #:</span><span><?= e($order['order_number']) ?></span></div>
    <div class="info"><span class="label">Type:</span><span><?= e(ucfirst($order['order_type'])) ?></span></div>
    <div class="info"><span class="label">Status:</span><span><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span></div>
    <div class="info"><span class="label">Time:</span><span><?= date('d M Y H:i', strtotime($order['created_at'])) ?></span></div>
    <?php if ($order['table_number']): ?>
    <div class="info"><span class="label">Table:</span><span><?= e($order['table_number']) ?></span></div>
    <?php endif; ?>
    <div class="info"><span class="label">Customer:</span><span><?= e($order['customer_name']) ?></span></div>
    <div class="info"><span class="label">Phone:</span><span><?= e($order['customer_phone']) ?></span></div>
    <?php if ($order['order_type'] === 'delivery'): ?>
    <div class="info"><span class="label">Address:</span><span><?= e($order['delivery_address']) ?></span></div>
    <?php endif; ?>
    <div class="items">
        <?php foreach ($items as $item): ?>
        <div class="item">
            <div class="name"><?= e($item['item_name']) ?> <span class="qty"><?= (int)$item['quantity'] ?>x</span></div>
            <?php if (!empty($item['instructions'])): ?>
            <div class="instructions">Note: <?= e($item['instructions']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="footer">
        <div>Items: <?= count($items) ?> | Total Qty: <?= array_sum(array_column($items, 'quantity')) ?></div>
        <div>Printed: <?= date('d M Y H:i:s') ?></div>
        <div class="qr-placeholder">[ QR CODE: ORDER <?= e($order['order_number']) ?> ]</div>
        <div style="margin-top:10px;">--- CUT HERE ---</div>
    </div>
</div>
<script>
    window.onload = function() { window.print(); };
</script>
</body>
</html>