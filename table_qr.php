<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/core.php';
require_login(['admin']);

$tableId = (int) ($_GET['id'] ?? 0);
$tables = $tableId
    ? query('SELECT rt.*, b.name branch_name FROM restaurant_tables rt LEFT JOIN branches b ON b.id=rt.branch_id WHERE rt.id=?', [$tableId])->fetchAll()
    : query('SELECT rt.*, b.name branch_name FROM restaurant_tables rt LEFT JOIN branches b ON b.id=rt.branch_id WHERE rt.status!="maintenance" ORDER BY rt.table_number')->fetchAll();
if (!$tables) {
    http_response_code(404);
    exit('Table not found');
}
$host = strtolower((string) parse_url(public_url(), PHP_URL_HOST));
$localOnly = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Table QR codes - <?= e(setting('restaurant_name', APP_NAME)) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; margin: 0; padding: 24px; background: #f5f5f5; color: #111; }
        .toolbar { max-width: 960px; margin: 0 auto 20px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .toolbar button, .toolbar a { background: #f4511e; color: #fff; border: 0; padding: 10px 18px; border-radius: 999px; font-weight: 600; cursor: pointer; text-decoration: none; }
        .toolbar a { background: #1f2937; }
        .warning { max-width: 960px; margin: 0 auto 20px; background: #fff7ed; border: 1px solid #fdba74; padding: 12px 16px; border-radius: 10px; }
        .grid { max-width: 960px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
        .card { background: #fff; border-radius: 16px; padding: 24px; text-align: center; border: 2px solid #111; page-break-inside: avoid; }
        .card h2 { margin: 0 0 4px; font-size: 14px; letter-spacing: 3px; color: #f4511e; }
        .card .table { font-size: 40px; font-weight: 800; margin: 0; }
        .card .qr { display: flex; justify-content: center; margin: 14px 0; }
        .card .qr svg { width: 200px; height: 200px; }
        .card p { margin: 4px 0; font-size: 14px; }
        .card small { display: block; word-break: break-all; color: #666; font-size: 10px; margin-top: 8px; }
        @media print { body { background: #fff; padding: 0; } .toolbar, .warning { display: none; } .grid { gap: 12px; } }
    </style>
</head>
<body>
<div class="toolbar">
    <a href="dashboard.php?page=tables">&larr; Back to tables</a>
    <button type="button" onclick="window.print()">Print QR codes</button>
</div>
<?php if ($localOnly): ?>
<div class="warning"><b>Phones can't open "<?= e($host) ?>".</b> Set <code>APP_URL</code> in <code>.env</code> to your computer's Wi-Fi address (for example <code>http://192.168.1.20/food-ordering</code>), or open this page using that address, before printing.</div>
<?php endif; ?>
<div class="grid">
    <?php foreach ($tables as $table): $url = table_order_url($table['table_number']); ?>
    <div class="card">
        <h2>SCAN TO ORDER</h2>
        <p class="table">Table <?= e($table['table_number']) ?></p>
        <div class="qr" data-qr="<?= e($url) ?>"></div>
        <p><?= e(setting('restaurant_name', APP_NAME)) ?><?= $table['branch_name'] ? ' · ' . e($table['branch_name']) : '' ?></p>
        <small><?= e($url) ?></small>
    </div>
    <?php endforeach; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
    document.querySelectorAll('[data-qr]').forEach(function (box) {
        var qr = qrcode(0, 'M');
        qr.addData(box.dataset.qr);
        qr.make();
        box.innerHTML = qr.createSvgTag(6, 2);
    });
</script>
</body>
</html>
