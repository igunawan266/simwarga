<?php
/**
 * @var array      $invoice
 * @var array      $tenant
 * @var array|null $payment
 * @var array      $seller
 * @var list<array{description: string, amount: int}> $lines
 */
$rp     = static fn ($n) => 'Rp ' . number_format((int) $n, 0, ',', '.');
$labels = ['unpaid' => 'BELUM DIBAYAR', 'paid' => 'LUNAS', 'expired' => 'KEDALUWARSA', 'refunded' => 'DIREFUND', 'failed' => 'GAGAL', 'cancelled' => 'DIBATALKAN'];
$colors = ['paid' => '#059669', 'unpaid' => '#b45309', 'refunded' => '#7c3aed'];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?= esc($invoice['invoice_number']) ?></title>
<style>
    @page { margin: 32px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0b1f3a; }
    .head { border-bottom: 3px solid #22d3ee; padding-bottom: 14px; margin-bottom: 18px; }
    .brand { font-size: 18px; font-weight: bold; letter-spacing: 2px; color: #0b1f3a; }
    .muted { color: #64748b; }
    .title { font-size: 22px; font-weight: bold; text-align: right; }
    .status { display: inline-block; padding: 4px 10px; border: 2px solid <?= $colors[$invoice['status']] ?? '#64748b' ?>; color: <?= $colors[$invoice['status']] ?? '#64748b' ?>; font-weight: bold; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .items { margin-top: 18px; }
    .items th { background: #0b1f3a; color: #fff; text-align: left; padding: 8px; font-size: 10px; letter-spacing: 1px; }
    .items td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; }
    .total td { font-weight: bold; font-size: 13px; border-top: 2px solid #0b1f3a; border-bottom: none; }
    .box { margin-top: 18px; padding: 10px 12px; background: #f1f5f9; }
    .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="brand"><?= esc($seller['name']) ?></div>
            <?php if ($seller['address']): ?><div class="muted"><?= nl2br(esc($seller['address'])) ?></div><?php endif ?>
            <?php if ($seller['email']): ?><div class="muted"><?= esc($seller['email']) ?></div><?php endif ?>
        </td>
        <td class="title">INVOICE<br><span class="status"><?= esc($labels[$invoice['status']] ?? strtoupper($invoice['status'])) ?></span></td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td width="55%">
            <div class="muted">Ditagihkan kepada</div>
            <strong><?= esc($tenant['company_name']) ?></strong><br>
            <?php if ($tenant['contact_email']): ?><?= esc($tenant['contact_email']) ?><br><?php endif ?>
            <?php if ($tenant['contact_phone']): ?><?= esc($tenant['contact_phone']) ?><?php endif ?>
        </td>
        <td>
            <table>
                <tr><td class="muted">Nomor</td><td class="num"><strong><?= esc($invoice['invoice_number']) ?></strong></td></tr>
                <tr><td class="muted">Tanggal</td><td class="num"><?= esc(date('d M Y', strtotime($invoice['created_at']))) ?></td></tr>
                <tr><td class="muted">Jatuh tempo</td><td class="num"><?= esc(date('d M Y H:i', strtotime($invoice['due_at']))) ?></td></tr>
                <tr><td class="muted">Jenis</td><td class="num"><?= $invoice['kind'] === 'renewal' ? 'Perpanjangan' : 'Langganan baru' ?></td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="items">
    <thead><tr><th>DESKRIPSI</th><th class="num">JUMLAH</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $line): ?>
        <tr><td><?= esc($line['description']) ?></td><td class="num"><?= $rp($line['amount']) ?></td></tr>
    <?php endforeach ?>
    <tr><td class="muted">Subtotal</td><td class="num"><?= $rp($invoice['subtotal']) ?></td></tr>
    <?php if ((int) $invoice['fee'] > 0): ?>
        <tr><td class="muted">Biaya kanal pembayaran</td><td class="num"><?= $rp($invoice['fee']) ?></td></tr>
    <?php endif ?>
    <tr class="total"><td>Total</td><td class="num"><?= $rp($invoice['total']) ?></td></tr>
    </tbody>
</table>

<div class="box">
    <?php if ($payment): ?>
        Dibayar melalui <strong><?= esc($payment['method']) ?></strong>
        pada <?= esc(date('d M Y H:i', strtotime($payment['paid_at']))) ?> ·
        Referensi Tripay <?= esc($payment['provider_reference']) ?> · Ref. merchant <?= esc($payment['merchant_ref']) ?>
    <?php elseif ($invoice['status'] === 'unpaid'): ?>
        Bayar melalui menu <strong>Tagihan</strong> di portal Virtual AI Office (Virtual Account, QRIS, E-Wallet via Tripay).
    <?php else: ?>
        Invoice ini tidak dapat dibayar lagi.
    <?php endif ?>
</div>

<div class="foot">Dokumen ini dibuat otomatis oleh sistem <?= esc($seller['name']) ?> dan sah tanpa tanda tangan.</div>
</body>
</html>
