<?= $this->extend('layouts/app') ?>

<?= $this->section('head') ?>
<script type="importmap">
{
  "imports": {
    "three": "<?= asset_path('assets/vendor/three/three.module.min.js') ?>",
    "three/addons/": "<?= asset_path('assets/vendor/three/addons/') ?>"
  }
}
</script>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div id="app" class="app" data-mode="explore">
    <canvas id="scene" aria-label="Visualisasi 3D gedung Virtual AI Office"></canvas>

    <!-- Loader -->
    <div id="loader" class="loader">
        <div class="loader__logo"><span class="logo-mark"></span>VIRTUAL AI OFFICE</div>
        <div class="loader__bar"><span id="loader-progress"></span></div>
        <div class="loader__text" id="loader-text">Menyiapkan gedung…</div>
    </div>

    <!-- Top bar -->
    <header class="topbar glass">
        <div class="brand">
            <span class="logo-mark"></span>
            <div>
                <div class="brand__name">VIRTUAL AI OFFICE</div>
                <div class="brand__sub" id="building-name">—</div>
            </div>
        </div>
        <div class="breadcrumb" id="breadcrumb">Explore Building</div>
        <div class="session" id="session">
            <button class="btn btn--primary" data-action="open-login">Login</button>
        </div>
    </header>

    <!-- Floor navigation -->
    <aside class="floor-nav glass" id="floor-nav" aria-label="Floor Navigation">
        <div class="panel-title">Floor Navigation <button class="icon-btn md-only" data-action="toggle-floors" aria-label="Tutup">×</button></div>
        <ol class="floor-list" id="floor-list"></ol>
    </aside>

    <!-- Info / detail panel -->
    <aside class="info-panel glass" id="info-panel" hidden>
        <button class="icon-btn info-panel__close" data-action="close-panel" aria-label="Tutup panel">×</button>
        <div id="info-content"></div>
    </aside>

    <!-- Office header (office mode) -->
    <section class="office-hud glass" id="office-hud" hidden>
        <div class="office-hud__head">
            <span class="tenant-dot" id="office-color"></span>
            <div>
                <div class="office-hud__name" id="office-name">—</div>
                <div class="office-hud__meta"><span id="office-meta">—</span> <span class="badge" id="office-plan">—</span></div>
            </div>
        </div>
        <div class="stat-row" id="office-stats"></div>
        <div class="room-chips" id="room-chips"></div>
        <div class="btn-row office-hud__team">
            <button class="btn" data-action="tasks">Task</button>
            <button class="btn" data-action="team">Tim &amp; Akses</button>
            <button class="btn" data-action="knowledge" data-owner-only>Knowledge</button>
        </div>
    </section>

    <!-- Hover tooltip -->
    <div class="tooltip" id="tooltip" hidden></div>

    <!-- Mode bar -->
    <nav class="modebar glass" aria-label="Navigasi utama">
        <button class="mode-btn is-active" data-action="explore"><span class="ico ico--orbit"></span>Explore Building</button>
        <button class="mode-btn" data-action="enter-office"><span class="ico ico--door"></span>Enter My Office</button>
        <button class="mode-btn" data-action="lobby"><span class="ico ico--lobby"></span>Back to Lobby</button>
        <button class="mode-btn md-only" data-action="toggle-floors"><span class="ico ico--floors"></span>Floors</button>
    </nav>

    <!-- Status legend -->
    <div class="legend glass" id="legend" hidden>
        <span><i style="--c:#22d3ee"></i>Online</span>
        <span><i style="--c:#38bdf8"></i>Working</span>
        <span><i style="--c:#94a3b8"></i>Idle</span>
        <span><i style="--c:#fbbf24"></i>Waiting</span>
        <span><i style="--c:#f87171"></i>Error</span>
        <span><i style="--c:#475569"></i>Offline</span>
    </div>

    <!-- Login modal -->
    <div class="modal" id="login-modal" hidden>
        <form class="modal__card glass" id="login-form" novalidate>
            <button type="button" class="icon-btn modal__close" data-action="close-login" aria-label="Tutup">×</button>
            <div class="modal__eyebrow">Tenant Access</div>
            <h2>Masuk ke kantor Anda</h2>
            <p class="muted">Setelah login, elevator akan membawa Anda ke lantai kantor tenant Anda.</p>
            <label>Email<input type="email" name="email" autocomplete="username" required maxlength="191"></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required minlength="8"></label>
            <div class="form-error" id="login-error" hidden></div>
            <button class="btn btn--primary btn--block" type="submit">Login &amp; naik elevator</button>
            <?php if (ENVIRONMENT !== 'production'): ?>
                <p class="demo-hint">Demo: <code>owner@demo.test</code> / <code>DemoOffice#2026</code></p>
            <?php endif ?>
        </form>
    </div>

    <div class="toasts" id="toasts" aria-live="polite"></div>
    <div class="webgl-error" id="webgl-error" hidden>
        <h2>WebGL tidak tersedia</h2>
        <p>Browser atau perangkat ini tidak mendukung WebGL. Gunakan Chrome, Edge, Firefox atau Safari versi terbaru.</p>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_path('assets/vendor/gsap/gsap.min.js') ?>"></script>
<script type="module" src="<?= asset_path('assets/js/office3d/main.js') ?>"></script>
<?= $this->endSection() ?>
