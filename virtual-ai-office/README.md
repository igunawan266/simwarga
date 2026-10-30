# Virtual AI Office

Gedung perkantoran virtual 3D berbasis web — platform SaaS multi-tenant tempat perusahaan
menyewa kantor virtual dan mempekerjakan **Agent AI** sebagai karyawan digital.

**Stack:** CodeIgniter 4.7 · PHP 8.2+ (kompatibel 8.5) · MySQL/MariaDB · Three.js r170 · GSAP 3 · Vanilla JS/CSS

> Status saat ini: **Phase 1 (visual prototype) selesai** + fondasi Phase 2
> (auth, isolasi multi-tenant, RBAC, skema database lengkap). Lihat [docs/ROADMAP.md](docs/ROADMAP.md).

## Menjalankan secara lokal

```bash
cd virtual-ai-office
composer install
cp .env.example .env
```

Untuk mencoba cepat tanpa MySQL, ubah di `.env`:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'
database.default.DBDriver = SQLite3
database.default.database = office.db
database.default.foreignKeys = true
```

```bash
php spark migrate
php spark db:seed DatabaseSeeder
php spark serve
```

Buka http://localhost:8080.

Di server production, jadwalkan pelepasan reservasi yang tidak dibayar (setiap 5 menit):

```bash
php spark office:expire-reservations
```

### Mengaktifkan pembayaran Tripay

1. Isi `tripay.merchantCode`, `tripay.apiKey`, `tripay.privateKey` (dan `tripay.mode`) di `.env`.
2. Di dashboard Tripay, set **Callback URL** ke `https://domain-anda/webhooks/tripay` (harus bisa diakses publik).
3. Sinkronkan kanal pembayaran (ulangi harian lewat cron):

```bash
php spark tripay:sync-channels
```

4. Mode sandbox: lakukan pembayaran uji, lalu tandai lunas lewat simulator di dashboard sandbox Tripay —
   callback akan mengaktifkan langganan tenant secara otomatis.

### Akun demo (dibuat oleh `DemoSeeder`, tidak pernah di production)

| Email | Password | Peran |
|---|---|---|
| `owner@demo.test` | `DemoOffice#2026` | Tenant Owner — Nusantara Digital Group, lantai 7 (kantor lengkap, 16 agent) |
| `staff@demo.test` | `DemoOffice#2026` | Tenant Staff — Nusantara Digital Group |
| `owner2@demo.test` | `DemoOffice#2026` | Tenant Owner — Arunika Logistics, lantai 3 |
| `admin@demo.test` | `DemoOffice#2026` | Super Admin (belum punya console; Phase 5) |

Data demo memakai langganan **trial**; tidak ada invoice atau pembayaran palsu yang di-seed.

## Yang bisa dicoba

1. **Explore Building** — putar/zoom gedung, hover lantai untuk melihat tenant, klik untuk detail.
2. **Back to Lobby** — masuk lobby: resepsionis AI dan building directory dari database.
3. **Login** → elevator turun, pintu terbuka, naik ke lantai tenant, lantai di atasnya di-fade,
   kamera masuk ke denah kantor.
4. Klik ruangan (di scene atau daftar) → detail ruangan, agent, task (data dari API ter-scope tenant).
5. Klik robot agent → profil agent. Meeting Room → **Simulasikan rapat** (simulasi visual).
6. Ruangan yang tidak termasuk paket tampil **terkunci** (mis. login `owner2@demo.test`, paket Business).
7. Login sebagai `admin@demo.test` → klik lantai mana pun untuk panel **Super Admin**
   (sewakan/kosongkan kantor, buat tenant, status tenant, harga kantor, undang Owner) dan tombol **Kelola Paket**.
8. Di kantor, tombol **Tim & Akses** → undang anggota, salin tautan, buka di browser lain untuk
   bergabung — anggota baru langsung dibawa elevator ke kantornya.
9. Tanpa login, klik lantai kosong → **Sewa lantai ini** → pilih paket & isi data → kantor di-reserve,
   invoice dibuat, panel **Tagihan** terbuka → **Bayar via Tripay** → pilih kanal → instruksi bayar.
   Setelah callback PAID terverifikasi, elevator otomatis membawa Anda ke kantor.

Tombol fitur fase berikutnya ditampilkan non-aktif dengan label **PHASE n / PROTOTYPE**.

## Tes

```bash
vendor/bin/phpunit
```

`tests/Feature/TenantIsolationTest.php` dan `tests/Unit/TenantScopedModelTest.php` membuktikan
tenant tidak bisa membaca/mengubah data tenant lain (URL dimanipulasi, session dipalsukan,
update `tenant_id`, delete lintas tenant).

## Dokumentasi

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — arsitektur, struktur folder, multi-tenancy, keamanan, 3D
- [docs/DATABASE.md](docs/DATABASE.md) — skema 26 tabel + ERD
- [docs/ROADMAP.md](docs/ROADMAP.md) — status per phase

## API (v1)

| Method | Endpoint | Akses |
|---|---|---|
| GET | `/api/v1/building` | publik — directory gedung (field aman saja) |
| GET | `/api/v1/session` | publik — status login |
| POST | `/api/v1/session/login` | publik, CSRF, 5/menit |
| POST | `/api/v1/session/logout` | CSRF |
| GET | `/api/v1/office` | auth + tenant — kantor, ruangan, agent, langganan |
| GET | `/api/v1/office/rooms/{id}` | auth + tenant — detail ruangan (404 untuk milik tenant lain) |
| GET | `/api/v1/plans` | publik — paket aktif |
| POST | `/api/v1/register` | publik/login, CSRF, 5 per 10 menit — daftar tenant + reserve lantai + invoice |
| GET | `/api/v1/office/billing` | owner/admin — langganan, reservasi, invoice |
| GET | `/api/v1/office/invoices/{id}/channels` | owner/admin — kanal + perkiraan biaya |
| POST | `/api/v1/office/invoices/{id}/pay` | owner/admin — buat transaksi Tripay (`method`) |
| GET | `/api/v1/office/invoices/{id}/payments` | owner/admin — status pembayaran (polling) |
| POST | `/webhooks/tripay` | Tripay — callback ber-signature HMAC (tanpa CSRF/session) |
| GET / POST | `/api/v1/admin/payment-channels[/sync]` | super_admin — status gateway, sinkron kanal |
| GET | `/api/v1/office/members` | auth + tenant — daftar anggota |
| POST | `/api/v1/office/members` | owner/admin — undang (`email`, `role`) → `invite_url` |
| PATCH / DELETE | `/api/v1/office/members/{id}` | owner/admin — ubah peran/status, hapus |
| POST | `/api/v1/office/members/{id}/reinvite` | owner/admin — tautan undangan baru |
| GET / POST | `/invite/{token}` | publik (rate limit) — halaman terima undangan |
| GET | `/api/v1/admin/floors` | super_admin — lantai, kantor, penyewa |
| PATCH | `/api/v1/admin/offices/{id}` | super_admin — harga / status kantor |
| POST | `/api/v1/admin/offices/{id}/assign` | super_admin — sewakan kantor ke tenant (`tenant_id`) |
| POST | `/api/v1/admin/offices/{id}/release` | super_admin — kosongkan kantor |
| GET/POST | `/api/v1/admin/tenants` | super_admin — daftar / buat tenant |
| GET/PATCH | `/api/v1/admin/tenants/{id}` | super_admin — detail / ubah tenant |
| GET/POST | `/api/v1/admin/plans` | super_admin — daftar / buat paket |
| PATCH | `/api/v1/admin/plans/{id}` | super_admin — ubah harga & limit paket |
| POST / PATCH / DELETE | `/api/v1/admin/tenants/{id}/members[/{member}]` | super_admin — kelola anggota tenant mana pun |
