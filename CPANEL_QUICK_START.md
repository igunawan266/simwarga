# cPanel Quick Start - SIMWarga (5 Menit Setup)

Panduan tercepat instalasi SIMWarga di cPanel dalam **5 langkah utama**.

---

## 🚀 5-Langkah Setup Cepat

### ✅ LANGKAH 1: Database (2 menit)

**Di cPanel → MySQL Databases:**

```
1. Create New Database:
   Database Name: simwarga_db
   
2. Create New MySQL User:
   Username: simwarga_user
   Password: [GENERATE RANDOM]
   Copy password!

3. Add User to Database:
   User: simwarga_user
   Database: simwarga_db
   ALL PRIVILEGES ✓
```

**SIMPAN INFO INI:**
```
Database: simwarga_db
User: simwarga_user
Password: [your_password]
Host: localhost
```

---

### ✅ LANGKAH 2: Upload Project (2 menit)

**Di cPanel → File Manager:**

```
1. Navigate ke: public_html
2. Upload: simwarga.zip
3. Extract: Klik kanan → Extract
4. Verifikasi folder sudah ada: simwarga/
```

---

### ✅ LANGKAH 3: PHP Setup (30 detik)

**Di cPanel → Select PHP Version:**

```
1. Pilih: PHP 8.2 atau lebih tinggi
2. Extensions ✓: 
   - bcmath ✓
   - curl ✓
   - gd ✓
   - mbstring ✓
   - pdo_mysql ✓
   - xml ✓
   - zip ✓
3. Save
```

---

### ✅ LANGKAH 4: Konfigurasi .env (1 menit)

**Di File Manager → public_html/simwarga/:**

```
1. Edit file: .env
2. Cari & update:

APP_URL=https://yourdomain.com
APP_DEBUG=false
APP_ENV=production

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=simwarga_db
DB_USERNAME=simwarga_user
DB_PASSWORD=[your_password]

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io (atau gmail)
MAIL_PORT=2525 (atau 587)
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password

3. Save
```

---

### ✅ LANGKAH 5: Database Migrations (30 detik)

**Option A: Via Terminal (Recommended)**

Jika ada SSH access:
```bash
cd ~/public_html/simwarga
php artisan migrate
php artisan optimize
```

**Option B: Via cPanel Terminal**

Di cPanel → Terminal (jika available):
```
[Sama seperti di atas]
```

**Option C: Manual (jika tidak ada terminal)**

Skip, database akan auto-migrate first time

---

## ✨ Selesai! Test di Browser

```
https://yourdomain.com

Jika ada error:
1. Check: cPanel → Error Log
2. Check: storage/logs/laravel.log
3. Fix permissions:
   chmod -R 755 storage/
   chmod -R 755 bootstrap/cache/
```

---

## 🔐 Setup SSL (1 menit - optional tapi recommended)

**Di cPanel → AutoSSL:**

```
1. Click "Manage AutoSSL"
2. Select domain Anda
3. Click "Install"
4. Tunggu sampai status berubah ke "Installed"
5. Test: https://yourdomain.com
```

---

## 🆘 Quick Troubleshooting

| Problem | Quick Fix |
|---------|-----------|
| **500 Error** | Check error log, fix permissions (755) |
| **Database Error** | Verify DB credentials di .env, test connection |
| **Class not found** | `php artisan config:clear` |
| **Email error** | Test SMTP credentials, use Mailtrap |
| **Slow loading** | `php artisan config:cache` |

---

## 📋 Checklist Final

- [ ] Database created & user assigned
- [ ] Project uploaded & extracted
- [ ] PHP 8.2+ selected with extensions
- [ ] .env file configured with DB credentials
- [ ] Database migrations runned
- [ ] SSL installed (optional)
- [ ] Application accessible at https://yourdomain.com
- [ ] Email tested & working
- [ ] Backup strategy setup

---

## 🌍 Provider Links

| Provider | cPanel URL |
|----------|-----------|
| **Niagahoster** | https://ngh.niagahoster.co.id:2083 |
| **Hostinger** | https://hpanel.hostinger.co.id |
| **DomaiNesia** | https://panel.domainesia.com |
| **MasterWeb** | https://cPanel.masterweb.id:2083 |

---

## ⏱️ Total Setup Time

```
Database Setup      : 2 min
Upload Project      : 2 min
PHP Configuration   : 30 sec
.env Configuration  : 1 min
Database Migration  : 30 sec
SSL Setup (optional): 1 min
Testing             : 1 min
────────────────────────────
TOTAL              : ~7-8 minutes
```

---

## 📞 Bantuan Lebih Lanjut

**Sudah selesai setup tapi masih ada masalah?**

1. **Lihat dokumentasi lengkap:**
   - CPANEL_INSTALLATION.md (detail)
   - HOSTING_INSTALLATION.md (advanced)
   - QUICK_REFERENCE.md (command reference)

2. **Contact Support:**
   - Hosting provider support (database, PHP, SSL)
   - GitHub Issues: https://github.com/igunawan266/simwarga

3. **Check Logs:**
   ```
   cPanel → Error Log
   storage/logs/laravel.log
   ```

---

**Selamat! SIMWarga siap digunakan! 🎉**

Setup cPanel paling simpel untuk SIMWarga di Indonesia.

---

**Last Updated:** September 2026  
**Setup Time:** 5-8 minutes  
**Difficulty:** Easy ⭐☆☆☆☆
