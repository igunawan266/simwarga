# Arsitektur — Virtual AI Office

Platform SaaS multi-tenant berbentuk **gedung perkantoran virtual 3D**. Gedung adalah
navigasi utama aplikasi: setiap lantai disewa satu tenant, setiap tenant punya kantor
terisolasi berisi ruangan dan karyawan **Agent AI**.

## 1. Gambaran sistem

```
Browser (desktop prioritas, responsif)
 ├─ HTML/CSS HUD (glassmorphism, navy/cyan/silver)
 └─ Three.js + GSAP scene  ──fetch JSON + CSRF──►  CodeIgniter 4 (PHP 8.2+)
                                                    ├─ Filters: csrf · auth · tenant · role · throttle
                                                    ├─ Controllers (Web, Api/V1, Webhooks*, Admin*)
                                                    ├─ Services (Building, Office, Auth, Audit, AI*, Billing*)
                                                    ├─ Models (TenantScopedModel untuk data tenant)
                                                    └─ Libraries (Tenancy, Auth, Payment/Tripay*, AI*)
                                                          │
                                       MySQL/MariaDB ◄────┤──► Redis (queue/session, Phase 3)
                                                          └──► Tripay API*, LLM API*
(* = phase berikutnya)
```

## 2. Struktur folder

```
virtual-ai-office/
├── app/
│   ├── Config/            Routes, Filters (alias auth/tenant/role/throttle), Services (tenantContext, currentUser, auditLogger)
│   ├── Controllers/
│   │   ├── PortalController.php        halaman portal 3D
│   │   └── Api/V1/                      REST JSON: Building, Session, Office
│   ├── Database/
│   │   ├── BaseMigration.php           helper kolom (id, fk, money, json, timestamps)
│   │   ├── Migrations/                 6 migrasi per domain (26 tabel)
│   │   └── Seeds/                      CoreSeeder (wajib) + DemoSeeder (non-production)
│   ├── Exceptions/                     TenantContextException
│   ├── Filters/                        AuthFilter, TenantFilter, RoleFilter, ThrottleFilter
│   ├── Helpers/office_helper.php       asset_path()
│   ├── Libraries/
│   │   ├── Auth/CurrentUser.php        user terautentikasi per request
│   │   └── Tenancy/TenantContext.php   tenant terverifikasi per request
│   ├── Models/                         TenantScopedModel + model per tabel
│   ├── Services/                       AuthService, BuildingService, OfficeService, AuditService
│   └── Views/                          layouts/app.php, portal/building.php
├── public/assets/
│   ├── css/app.css
│   ├── vendor/ (three r170, gsap 3.12 — di-host lokal, tanpa CDN)
│   └── js/office3d/
│       ├── main.js                     state machine aplikasi
│       ├── core/      Stage (renderer+loop), CameraDirector (GSAP), api.js, config.js, textures.js
│       ├── building/  Building (gedung, lantai, kota, lobby, atap), Elevator
│       ├── office/    OfficeInterior (lazy), AgentCrowd (instanced), Furniture
│       └── ui/        Hud (DOM overlay)
├── tests/  Feature/TenantIsolationTest.php, Unit/TenantScopedModelTest.php
└── docs/   ARCHITECTURE.md, DATABASE.md, ROADMAP.md
```

Modul phase berikutnya mengikuti pola yang sama:
`Libraries/Payment/Tripay/` (client + signature), `Services/BillingService`,
`Controllers/Webhooks/TripayController`, `Libraries/AI/` (provider, orchestrator, tools),
`Commands/` (worker queue, cron expire/renewal), `Controllers/Admin/` (Super Admin).

## 3. Multi-tenancy & isolasi data

Isolasi ditegakkan di **server**, berlapis:

1. **Session hanya menyimpan id** (`user_id`, `active_tenant_id`). Peran tidak pernah disimpan
   di session — dibaca ulang dari DB setiap request, sehingga pencabutan akses langsung berlaku.
2. **`TenantFilter`** memverifikasi bahwa user adalah anggota aktif tenant tersebut
   (`tenant_members` + tenant berstatus `active`), lalu mengisi `TenantContext`.
   Tenant id **tidak pernah** diambil dari URL, body, atau header.
3. **`TenantScopedModel`** — base model semua tabel milik tenant. Setiap `find/findAll/first/
   update/delete/countAllResults` otomatis diberi `WHERE tenant_id = <context>`; setiap insert
   di-stamp `tenant_id`; update tidak bisa memindahkan baris ke tenant lain. Tanpa context,
   query **gagal keras** (`TenantContextException`) — bukan mengembalikan data semua tenant.
   Akses lintas-tenant (Super Admin, cron, webhook) harus eksplisit lewat `withoutTenantScope()`.
4. Endpoint mengembalikan **404** (bukan 403) untuk resource tenant lain agar keberadaannya
   tidak bocor.
5. Kolom sensitif (system prompt, API config) tidak pernah dikirim ke browser
   (`AiAgentModel::publicColumns()`).

Diverifikasi oleh `tests/Feature/TenantIsolationTest.php` dan `tests/Unit/TenantScopedModelTest.php`.

## 4. RBAC

| Role | Scope | Sumber |
|---|---|---|
| `super_admin` | platform | `users.role_id` |
| `tenant_owner`, `tenant_admin`, `tenant_staff` | tenant | `tenant_members.role_id` |
| `ai_agent` | tenant | dipakai saat agent bertindak (audit `agent_id`) |

Pemakaian di route: `['filter' => ['auth', 'tenant', 'role:tenant_owner,tenant_admin']]`
atau `role:super_admin`.

## 5. Keamanan

- CSRF (session token, header `X-CSRF-TOKEN`) global untuk semua request non-GET,
  kecuali `webhooks/*` yang diautentikasi signature HMAC.
- Rate limit: `throttle:api,120,60` untuk API, `throttle:login,5,60` untuk login.
- Password `password_hash()` + rehash otomatis; respons login tidak membedakan email salah
  vs password salah (timing disamakan); `session()->regenerate()` saat login.
- Secret (DB, encryption key, Tripay, LLM) hanya di `.env`. Secret milik tenant disimpan
  terenkripsi di `tenant_settings` (`is_encrypted`).
- `audit_logs` append-only: login, login gagal, logout (fase berikut: CRUD agent, pembayaran).
- `SecureHeaders` filter, `invalidchars` filter, auto-routing dimatikan.

## 6. Arsitektur 3D

**State machine** (`main.js`): `explore → lobby → elevator → office → room`.

| Komponen | Teknik performa |
|---|---|
| Stage | pixel ratio dibatasi (1.75, 1.25 di perangkat lemah), loop **berhenti** saat tab tersembunyi, ~30 fps setelah 20 detik tanpa interaksi |
| Building | material & geometri dibagi, kota = 1 `InstancedMesh`, interior low-detail semua lantai = 3 `InstancedMesh`, kolom fasad = 1 `InstancedMesh` |
| LOD | interior low-detail disembunyikan saat kamera jauh; interior detail hanya dibangun untuk lantai tenant saat masuk |
| Lazy loading | `OfficeInterior` dan `AgentCrowd` di-`import()` dinamis saat elevator tiba |
| AgentCrowd | semua agent = 3 draw call (badan, kepala, visor) berapa pun jumlahnya; warna visor = status live |
| Cutaway | lantai di atas kantor tenant di-fade agar interior terlihat ("dollhouse") |
| GSAP | `lagSmoothing(0)` agar urutan sinematik selesai tepat waktu di GPU lambat |

Alur Enter My Office: login → `GET /api/v1/office` (cek langganan) → kamera ke lobby elevator →
kabin turun → pintu buka/tutup → kamera mengikuti kabin naik → pintu terbuka → lantai atas di-fade
→ interior dibangun → kamera masuk ke tampilan denah → klik ruangan → `GET /office/rooms/{id}`.

## 7. Desain modul berikutnya (ringkas)

**AI Engine (Phase 3)** — `agent_tasks` dengan `parent_task_id` untuk delegasi Manager → agent lain;
worker CLI (`php spark agents:work`) mengambil task `queued` dari Redis/DB, memanggil LLM dengan
tool calling, menulis `agent_logs` + `agent_conversations`, menambah token usage dan menegakkan
`token_limit_monthly` serta kuota paket. Status agent di 3D di-polling/SSE.

**Payment Tripay (Phase 4)** — `invoices` → `payments` (satu baris per upaya bayar, `merchant_ref` unik).
Callback: simpan mentah di `payment_callbacks` → validasi signature HMAC-SHA256 dengan Private Key
sesuai dokumentasi resmi Tripay → cocokkan `merchant_ref`, nominal, status → dalam **satu transaksi DB**
dengan `SELECT … FOR UPDATE` pada payment: tandai `PAID`, invoice `paid`, aktifkan/perpanjang
`tenant_subscriptions`. `event_hash` unik + cek status saat ini = **idempotent** (callback ganda tidak
memperpanjang dua kali). Redirect browser tidak pernah mengaktifkan langganan. Detail endpoint,
parameter dan format signature wajib dicek ulang ke dokumentasi Tripay saat implementasi.
