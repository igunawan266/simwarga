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
- ✅ Skema database lengkap (26 tabel) + seeder
- ✅ Login/logout (session, CSRF, rate limit, audit)
- ✅ Multi-tenant: TenantFilter + TenantScopedModel + tes isolasi
- ✅ RBAC filter (`role:`)
- ⬜ Registrasi tenant & undangan anggota
- ⬜ CRUD tenant / lantai / ruangan (Super Admin)
- ⬜ Penegakan limit paket saat membuat agent/ruangan

## PHASE 3 – AI Engine ⬜
- ⬜ CRUD Agent (create/edit/delete/activate/deactivate/assign room)
- ⬜ Provider LLM + tool calling, knowledge base
- ⬜ Queue worker (Redis), task assignment, delegasi Manager → agent
- ⬜ Agent-to-agent conversation, execution history, token usage & limit
- ⬜ Status agent live di scene 3D (polling/SSE)

## PHASE 4 – Payment (Tripay) ⬜
- ⬜ Konfigurasi merchant (sandbox/production) dari `.env`
- ⬜ Sinkron kanal pembayaran, buat invoice & transaksi
- ⬜ Webhook: signature, verifikasi nominal/ref, idempotensi, transaksi DB, aktivasi langganan
- ⬜ Invoice PDF, expired/failed, refund tracking, renewal, notifikasi, rekonsiliasi (cron)

## PHASE 5 – Admin & Reporting ⬜
- ⬜ Dashboard Super Admin (+ gedung 3D: klik lantai → tenant → profil/agent/langganan)
- ⬜ Dashboard tenant (billing, invoice, pengaturan kantor)
- ⬜ Analytics penggunaan AI, viewer audit log

## PHASE 6 – Optimization ⬜
- ⬜ Security audit, CSP, penetration test
- ⬜ Kompresi aset (glTF/Draco bila memakai model 3D), CDN
- ⬜ E2E test otomatis, load test, deployment production
