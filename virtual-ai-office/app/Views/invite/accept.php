<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$roleLabel = ['tenant_owner' => 'Owner', 'tenant_admin' => 'Admin', 'tenant_staff' => 'Staff'];
$err       = static fn (string $k) => isset($errors[$k]) ? '<div class="form-error">' . esc($errors[$k]) . '</div>' : '';
?>
<main class="page-center">
    <div class="page-backdrop" aria-hidden="true"></div>
    <?php if ($invite === null): ?>
        <section class="modal__card glass">
            <div class="modal__eyebrow">Undangan</div>
            <h2>Tautan tidak berlaku</h2>
            <p class="muted">Undangan ini tidak ditemukan, sudah digunakan, atau sudah kedaluwarsa. Minta pengirim undangan untuk mengirim ulang tautan baru.</p>
            <a class="btn btn--block" href="<?= esc(asset_path('/')) ?>">Ke gedung Virtual AI Office</a>
        </section>
    <?php else: ?>
        <form class="modal__card glass" method="post" action="<?= esc(asset_path('invite/' . $token)) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="modal__eyebrow">Undangan tim</div>
            <h2>Bergabung dengan <?= esc($invite['company_name']) ?></h2>
            <p class="muted">
                Anda diundang sebagai <b><?= esc($roleLabel[$invite['role']] ?? $invite['role']) ?></b>
                dengan email <b><?= esc($invite['email']) ?></b>.
                Undangan berlaku sampai <?= esc(date('d M Y H:i', strtotime($invite['invite_expires_at']))) ?>.
            </p>
            <?= $err('form') ?>
            <?php if ($invite['is_new_user']): ?>
                <label>Nama lengkap<input name="name" required maxlength="150" autocomplete="name" value="<?= esc($name) ?>"></label>
                <?= $err('name') ?>
                <label>Buat password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
                <?= $err('password') ?>
                <label>Ulangi password<input type="password" name="password_confirm" required autocomplete="new-password"></label>
                <?= $err('password_confirm') ?>
            <?php else: ?>
                <p class="muted">Email ini sudah memiliki akun. Masukkan password akun Anda untuk menerima undangan.</p>
                <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
                <?= $err('password') ?>
            <?php endif ?>
            <button class="btn btn--primary btn--block" type="submit">Terima undangan &amp; masuk</button>
        </form>
    <?php endif ?>
</main>
<?= $this->endSection() ?>
