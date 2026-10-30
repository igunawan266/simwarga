# Roadmap

Status: ✅ selesai · 🟡 fondasi tersedia · ⬜ belum

## PHASE 1 – Visual Prototype ✅
- ✅ Gedung bertingkat 3D (12 lantai + lobby + crown/antena), fasad kaca, kota sekitar
- ✅ Elevator kaca eksterior yang bergerak (idle) + pintu geser + kamera mengikuti
- ✅ Lobby utama: resepsionis AI, building directory (data live), signage
- ✅ Floor Navigation, hover/klik lantai, Explore Building / Enter My Office / Back to Lobby
- ✅ Contoh kantor tenant (lantai 7) dengan Main Lobby, Waiting Lounge, Pantry, Meeting Room,
  CEO Office + 5 ruang operasional, semua bisa diklik
- ✅ Agent AI sebagai karakter robot (instanced), warna visor = status, jalan ke pantry, simulasi rapat (visual)

## PHASE 2 – Core SaaS 🟡
- ✅ Skema database lengkap (27 tabel) + seeder
- ✅ Login/logout (session, CSRF, rate limit, audit)
- ✅ Multi-tenant: TenantFilter + TenantScopedModel + tes isolasi
- ✅ RBAC filter (`role:`)
- ✅ **2.1 Super Admin: gedung, tenant & paket** — klik lantai di gedung 3D → detail tenant
  (status, paket, ruangan, agent, anggota); buat tenant; sewakan / kosongkan kantor (atomik,
  provisioning ruangan sesuai paket, cek batas `max_offices`); harga & status kantor;
  edit semua harga/limit paket; semua aksi tercatat di `audit_logs`
- ✅ **2.2 Anggota tenant** — panel *Tim & Akses* di kantor 3D dan di panel Super Admin;
  undangan via tautan sekali pakai (token acak 256-bit, hanya hash SHA-256 disimpan, berlaku 7 hari,
  bisa diterbitkan ulang); halaman `/invite/{token}` untuk user baru (buat password) maupun user lama
  (konfirmasi password); peran Owner/Admin/Staff, nonaktifkan, hapus; tenant selalu punya ≥1 Owner
  aktif; tidak bisa mengubah keanggotaan sendiri; akses dicabut seketika
- ✅ **2.3 Registrasi tenant mandiri** — klik lantai kosong → *Sewa lantai ini* → pilih paket
  (paket kontrak/Enterprise tidak bisa dipesan mandiri), data perusahaan & akun Owner (atau akun yang
  sedang login). Satu transaksi: tenant `pending`, Owner, kantor **reserved** (klaim atomik),
  langganan `pending` dengan snapshot harga/limit, **invoice `unpaid`** (`INV-YYYYMMDD-XXXXXX`),
  ruangan sesuai paket. Panel **Tagihan** (owner/admin). Reservasi kedaluwarsa dilepas oleh
  `php spark office:expire-reservations` (cron 5 menit): kantor tersedia lagi, langganan `cancelled`,
  invoice `expired`. Tidak ada pembayaran yang disimulasikan.
- ⬜ 2.4 Penegakan limit paket saat membuat agent/ruangan (bersama Phase 3)

## PHASE 3 – AI Engine 🟡
- ✅ **3.1 Agent, task & multi-agent** — CRUD agent (buat/edit/hapus/aktifkan/nonaktifkan, ruangan,
  model, effort, manager, batas token) dengan **batas `max_agents` paket di server**; system prompt hanya
  untuk Owner/Admin. Task masuk antrean DB, diproses `php spark agents:work` (klaim atomik, beberapa
  worker aman, prioritas, retry backoff untuk 429/5xx/jaringan). Claude via **SDK PHP resmi**
  (`claude-opus-5-5` default, adaptive thinking, `effort` per agent, refusal fallback server-side).
  **Multi-agent**: Manager memakai tool `delegate_tasks` (strict) → sub-tugas untuk anggota timnya →
  setelah semua selesai Manager menyusun laporan akhir. Token per task/agent/tenant + kuota bulanan
  agent & paket, log eksekusi, percakapan agent↔agent, status robot 3D live, panel Task Monitoring.
- ⬜ 3.2 Knowledge base (unggah dokumen, dipakai agent sebagai konteks)
- ⬜ 3.3 Tools & integrasi (HTTP/webhook tool per tenant, WhatsApp, CRM) + persetujuan manusia
- ⬜ 3.4 Streaming progres task (SSE) & dashboard penggunaan AI

## PHASE 4 – Payment (Tripay) 🟡
- ✅ **4.1 Pembayaran & aktivasi** — konfigurasi merchant sandbox/production dari `.env`;
  sinkron kanal (`php spark tripay:sync-channels` / tombol Super Admin); pilih kanal → transaksi
  *closed payment* (signature `HMAC-SHA256(merchantCode+merchantRef+amount)`), instruksi bayar
  (VA, QR, checkout); webhook `POST /webhooks/tripay`: log mentah, signature `X-Callback-Signature`,
  cocokkan `merchant_ref` + `reference` + nominal, verifikasi ulang ke `transaction/detail`,
  idempoten (update bersyarat + `event_hash`), satu transaksi DB → payment PAID, invoice paid,
  langganan aktif (+durasi), kantor occupied, tenant active; status EXPIRED/FAILED/REFUND dicatat;
  pembayaran yang telat setelah reservasi lepas mengklaim ulang kantor bila masih kosong;
  redirect browser tidak mengaktifkan apa pun
- ✅ **4.2 Siklus langganan & billing** — `php spark office:subscriptions` (per jam): invoice
  perpanjangan otomatis `renewal_days_before` hari sebelum berakhir (harga paket terkini), `past_due`
  (akses tetap selama `grace_period_days`), lalu `expired` (akses diblokir, data & kantor tetap);
  tenant bisa *Perpanjang langganan* kapan saja; pembayaran saat `past_due` melanjutkan dari tanggal
  berakhir lama. **Invoice PDF** (dompdf, tanpa resource eksternal). **Rekonsiliasi**
  `php spark tripay:reconcile` (15 menit) memulihkan callback yang hilang lewat jalur idempoten yang
  sama. **Notifikasi in-app** (lonceng; item billing hanya untuk Owner/Admin) + email bila SMTP diisi.
- ⬜ Alur refund & tinjauan pembayaran oleh Super Admin (dipindah ke Phase 5)

## PHASE 5 – Admin & Reporting ⬜
- ⬜ Dashboard Super Admin (+ gedung 3D: klik lantai → tenant → profil/agent/langganan)
- ⬜ Dashboard tenant (billing, invoice, pengaturan kantor)
- ⬜ Analytics penggunaan AI, viewer audit log

## PHASE 6 – Optimization ⬜
- ⬜ Security audit, CSP, penetration test
- ⬜ Kompresi aset (glTF/Draco bila memakai model 3D), CDN
- ⬜ E2E test otomatis, load test, deployment production
