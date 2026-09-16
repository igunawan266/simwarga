# Panduan Instalasi SIMWarga di cPanel

Panduan lengkap instalasi SIMWarga untuk hosting dengan cPanel (misal: Niagahoster, Hostinger, DomaiNesia, dll).

## 📋 Daftar Isi
1. [Persyaratan](#persyaratan)
2. [Langkah 1: Login cPanel](#langkah-1-login-cpanel)
3. [Langkah 2: Buat Database](#langkah-2-buat-database)
4. [Langkah 3: Upload Project](#langkah-3-upload-project)
5. [Langkah 4: Konfigurasi PHP](#langkah-4-konfigurasi-php)
6. [Langkah 5: Konfigurasi .env](#langkah-5-konfigurasi-env)
7. [Langkah 6: Run Migrations](#langkah-6-run-migrations)
8. [Langkah 7: Setup SSL](#langkah-7-setup-ssl)
9. [Langkah 8: Cron Jobs](#langkah-8-cron-jobs)
10. [Langkah 9: Optimasi](#langkah-9-optimasi)
11. [Troubleshooting](#troubleshooting)

---

## ✅ Persyaratan

### Paket Hosting yang Dibutuhkan
- **PHP**: 8.2 atau lebih tinggi (minimal)
- **Database**: MySQL 8.0+ atau MariaDB 10.5+
- **Disk Space**: 5GB minimum
- **RAM**: Tidak terbatas (shared hosting)
- **File Manager**: cPanel (sudah built-in)
- **SSH Access**: Recommended (untuk development)
- **Composer**: Pre-installed atau bisa install manual

### Sebelum Mulai
- Paket hosting dengan cPanel sudah aktif
- Domain sudah pointing ke hosting
- Email untuk akses cPanel
- Password cPanel (atau reset jika lupa)
- File project SIMWarga (dari GitHub)

---

## 🔐 Langkah 1: Login cPanel

### 1.1 Akses cPanel

**Metode A: Langsung ke cPanel**
```
URL: https://yourdomain.com:2083
atau
URL: https://your-server-ip:2083
```

**Metode B: Melalui Hosting Provider**
- Login ke member area hosting Anda
- Cari menu "cPanel" atau "Control Panel"
- Klik "Go to cPanel" atau "Manage Hosting"

### 1.2 Login dengan Credentials
```
Username: username_cpanel Anda
Password: password_cpanel Anda
```

### 1.3 Interface cPanel
Anda akan melihat dashboard dengan berbagai menu:
- **File Manager** - Manage file
- **Databases** - Create database
- **MySQL Databases** - Database management
- **Mail** - Email settings
- **SSL/TLS** - SSL management
- **Cron Jobs** - Task scheduling
- **Addon Domains** - Add domain

---

## 🗄️ Langkah 2: Buat Database

### 2.1 Navigasi ke Database
1. Di cPanel, cari **"MySQL Databases"** atau **"Databases"**
2. Klik menu tersebut

### 2.2 Buat Database Baru

**Step 1: Create New Database**
```
1. Scroll ke "Create New Database"
2. Pada field "New Database Name", isi: simwarga_db
   (atau: username_simwarga)
   
3. Klik tombol "Create Database"
4. Tunggu notifikasi "Database created successfully!"
```

### 2.3 Buat Database User

**Step 2: Create Database User**
```
1. Scroll ke "Create New MySQL User"
2. Isi Username: simwarga_user (atau: username_user)
3. Isi Password: GeneratePassword (klik tombol random generator)
4. Copy password ke tempat aman!
5. Klik "Create MySQL User"
6. Tunggu notifikasi success
```

### 2.4 Assign User ke Database

**Step 3: Add User to Database**
```
1. Scroll ke "Add User to Database"
2. Pilih User: simwarga_user (dari dropdown)
3. Pilih Database: simwarga_db (dari dropdown)
4. Klik "Add"
5. Di popup, check semua privileges:
   ✓ ALL PRIVILEGES
6. Klik "Make Changes"
7. Selesai!
```

### 2.5 Catat Info Database
```
Database Name: username_simwarga_db (atau simwarga_db)
Database User: username_simwarga_user (atau simwarga_user)
Database Password: [yang Anda generate tadi]
Database Host: localhost (biasanya localhost di shared hosting)
```

---

## 📤 Langkah 3: Upload Project

### 3.1 Persiapan File Project

**Dari Local Computer (Windows/Mac/Linux):**

```bash
# 1. Clone repository
git clone https://github.com/igunawan266/simwarga.git

# 2. Hapus folder .git (opsional, untuk mengurangi ukuran)
cd simwarga
rm -rf .git

# 3. Zip project
# Windows: Right-click → Send to → Compressed (zipped) folder
# Mac: Right-click → Compress
# Linux: zip -r simwarga.zip simwarga/
```

### 3.2 Upload via File Manager (Easy Method)

**Step 1: Buka File Manager**
```
Di cPanel → File Manager
```

**Step 2: Navigasi ke Public Directory**
```
Klik: public_html
(atau folder domain jika menggunakan addon domain)
```

**Step 3: Upload File**
```
1. Klik tombol "Upload" di toolbar
2. Pilih file simwarga.zip
3. Tunggu upload selesai
4. Klik kanan file → "Extract"
5. Pilih folder simwarga (yang baru terekstrak)
6. Tunggu extraction selesai
```

**Step 4: Verifikasi File**
```
Pastikan struktur folder:
public_html/
├── simwarga/
│   ├── app/
│   ├── database/
│   ├── public/
│   ├── routes/
│   ├── storage/
│   ├── .env
│   ├── artisan
│   ├── composer.json
│   └── ... (file lainnya)
```

### 3.3 Upload via FTP (Alternative Method)

Jika File Manager tidak responsif:

**Gunakan FTP Client:**
- **Windows**: FileZilla, WinSCP
- **Mac**: Cyberduck, Transmit
- **Linux**: FileZilla, Nautilus

**FTP Settings:**
```
Host: ftp.yourdomain.com
Username: cpanel_username
Password: cpanel_password
Port: 21
Protokol: FTP

Atau SFTP (lebih aman):
Port: 22
Protokol: SFTP
```

**Upload Steps:**
```
1. Connect ke FTP
2. Navigasi ke public_html
3. Upload simwarga.zip
4. Extract di server
5. Disconnect
```

### 3.4 Setup Document Root (Addon Domain)

Jika menggunakan addon domain untuk SIMWarga:

**Di cPanel → Addon Domains:**
```
1. Klik "Create an Addon Domain"
2. Domain Name: simwarga.yourdomain.com
3. Subdomain: simwarga
4. Document Root: public_html/simwarga/public
   (PENTING: Point ke folder 'public', bukan root!)
5. Klik "Add Domain"
```

Jika menggunakan main domain:
```
Public Root sudah: public_html/simwarga/public
```

---

## ⚙️ Langkah 4: Konfigurasi PHP

### 4.1 Pilih PHP Version

**Di cPanel:**
```
1. Cari "Select PHP Version" atau "PHP Selector"
2. Klik menu tersebut
3. Pilih PHP 8.2 atau lebih tinggi
4. Klik "Set as default"
```

### 4.2 Install PHP Extensions

**Di PHP Selector:**
```
1. Klik tab "Extensions"
2. Pastikan extensions berikut di-check ✓:

Required:
✓ bcmath
✓ curl
✓ fileinfo
✓ gd
✓ intl
✓ json
✓ mbstring
✓ openssl
✓ pdo
✓ pdo_mysql
✓ tokenizer
✓ xml
✓ zip

Optional (untuk performance):
✓ redis (jika tersedia)
✓ opcache
✓ apcu

3. Klik "Save" di bawah
4. Tunggu kompilasi selesai (5-10 menit)
```

### 4.3 Konfigurasi PHP.ini

**Di PHP Configuration:**
```
1. Klik "PHP Configuration" atau "PHP.ini Editor"
2. Ubah nilai berikut:

memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
max_input_time = 300
default_charset = "utf-8"

3. Klik "Save"
```

---

## 📝 Langkah 5: Konfigurasi .env

### 5.1 Buka .env File

**Via File Manager:**
```
1. File Manager → public_html/simwarga/
2. Cari file .env
3. Right-click → "Edit"
```

**Via Text Editor (jika tidak visible):**
```
1. File Manager → Settings (top right)
2. Check "Show Hidden Files"
3. Sekarang .env akan visible
4. Edit seperti biasa
```

### 5.2 Edit .env Configuration

```env
# Application
APP_NAME=SIMWarga
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database - GANTI DENGAN INFO DATABASE ANDA
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=username_simwarga_db
DB_USERNAME=username_simwarga_user
DB_PASSWORD=password_database_anda_tadi

# Cache & Session - Gunakan file jika tidak ada Redis
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_DRIVER=database

# Mail - Gunakan Mailtrap atau Gmail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="SIMWarga"
```

### 5.3 Save .env File
```
1. Selesai edit
2. Klik "Save" atau "Save Changes"
3. File sudah ter-update
```

### 5.4 Verifikasi File Permissions

**Via File Manager:**
```
1. Klik kanan file .env
2. Klik "Change Permissions"
3. Set permission: 644
   (Atau: rw- r-- r--)
4. Klik "Change Permissions"
```

---

## 🚀 Langkah 6: Run Migrations

### 6.1 Gunakan SSH (Recommended)

**Buka Terminal:**
```
Windows: PuTTY atau Windows Terminal
Mac/Linux: Terminal atau iTerm2

# Connect via SSH
ssh username@yourdomain.com
# atau
ssh -p 22 username@yourdomain.com

# Input password (sama dengan cPanel password)
```

**Di SSH Console:**
```bash
# Navigate ke project
cd public_html/simwarga

# Clear cache & config
php artisan config:clear
php artisan cache:clear

# Generate APP_KEY (jika belum)
php artisan key:generate

# Run migrations
php artisan migrate

# (Optional) Seed default data
php artisan db:seed

# Clear cache lagi
php artisan optimize
```

### 6.2 Alternatif: Gunakan cPanel Terminal

**Di cPanel:**
```
1. Cari "Terminal" atau "SSH Terminal"
2. Klik untuk membuka terminal
3. Ikuti perintah SSH di atas
```

### 6.3 Alternatif: Gunakan Artisan Online Tool

Jika tidak ada SSH access:

**Create artisan.php:**
```php
<?php
// Letakkan di public_html/simwarga/public/artisan.php

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$app = require_once $basePath . '/bootstrap/app.php';

$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$status = $kernel->handle(
    $input = new Symfony\Component\Console\Input\ArgvInput,
    new Symfony\Component\Console\Output\ConsoleOutput
);

$kernel->terminate($input, $status);
exit($status);
?>
```

**Akses di browser:**
```
https://yourdomain.com/artisan.php migrate
https://yourdomain.com/artisan.php db:seed
```

**PENTING:** Hapus file ini setelah selesai!
```
File Manager → Delete artisan.php
```

---

## 🔒 Langkah 7: Setup SSL

### 7.1 Gunakan AutoSSL (Gratis dari cPanel)

**Di cPanel:**
```
1. Cari "AutoSSL" atau "SSL/TLS"
2. Klik "Manage AutoSSL"
3. Pilih domain Anda
4. Klik "Check AutoSSL Status"
5. Jika available, klik "Install"
6. Tunggu instalasi (biasanya 5-15 menit)
7. Verifikasi: Buka https://yourdomain.com
```

### 7.2 Manual Setup Jika AutoSSL Gagal

**Di cPanel → SSL/TLS Status:**
```
1. Klik "Manage SSL sites"
2. Pilih domain Anda
3. Pastikan certificate terpasang
4. Status: "SSL is installed"
```

### 7.3 Force HTTPS

**Via .htaccess:**
```
1. File Manager → public_html/simwarga/public/
2. Create file: .htaccess
3. Paste code berikut:

# Force HTTPS
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>

# Laravel rewrite rules
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php/$1 [L]
</IfModule>
```

### 7.4 Konfigurasi HTTPS di .env
```env
APP_URL=https://yourdomain.com
SESSION_SECURE_COOKIES=true
```

---

## ⏰ Langkah 8: Cron Jobs

### 8.1 Setup Queue Processing (Optional)

**Di cPanel → Cron Jobs:**
```
1. Klik "Cron Jobs"
2. Klik "Add New Cron Job"
3. Isi form:

Command:
/usr/bin/php /home/username/public_html/simwarga/artisan schedule:run >> /dev/null 2>&1

Minute: */1 (setiap menit)
Hour: * (setiap jam)
Day: * (setiap hari)
Month: * (setiap bulan)
Weekday: * (setiap hari)

4. Klik "Add New Cron Job"
```

### 8.2 Setup Backup Harian (Optional)

**Add Cron Job untuk Backup:**
```
Command:
/usr/bin/php /home/username/public_html/simwarga/artisan backup:run

Minute: 0 (jam 00:00)
Hour: 2 (pukul 2 pagi)
Day: * (setiap hari)
Month: *
Weekday: *
```

### 8.3 Verify Cron Jobs

```
1. Di Cron Jobs list, cek apakah jobs sudah ter-list
2. Klik "Edit" untuk modify atau "Delete" untuk remove
```

---

## ⚡ Langkah 9: Optimasi

### 9.1 Fix Directory Permissions

**Via Terminal (SSH):**
```bash
cd ~/public_html/simwarga

# Set directory permissions
find . -type d -exec chmod 755 {} \;

# Set file permissions
find . -type f -exec chmod 644 {} \;

# Special permissions
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
chmod 644 .env
```

**Via File Manager (jika tidak ada SSH):**
```
1. Klik file/folder
2. Right-click → Change Permissions
3. Set permission:
   - Folder: 755
   - File: 644
   - storage/: 755
   - bootstrap/cache/: 755
```

### 9.2 Enable Gzip Compression

**Edit .htaccess di public_html/simwarga/public/:**
```apache
# Gzip compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE text/javascript
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
</IfModule>

# Browser caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

### 9.3 Optimize Database

**Via SSH atau Artisan:**
```bash
# Optimize database tables
php artisan tinker

DB::connection()->statement("OPTIMIZE TABLE `users`");
DB::connection()->statement("OPTIMIZE TABLE `warga_profiles`");
DB::connection()->statement("OPTIMIZE TABLE `letter_requests`");

exit
```

### 9.4 Clear Laravel Cache

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan cache:clear
```

---

## 🐛 Troubleshooting

### Problem: 500 Internal Server Error

**Solution:**
```
1. Check error log:
   cPanel → Error Log atau
   tail -f ~/public_html/simwarga/storage/logs/laravel.log

2. Common causes:
   - Database connection error (check .env)
   - PHP memory limit (edit php.ini)
   - Missing PHP extensions
   - File permissions

3. Fix:
   - Verify DB credentials
   - Increase memory_limit ke 256M
   - Check PHP extensions
   - Run: chmod -R 755 storage/
```

### Problem: "Class not found" atau "Application Expired"

**Solution:**
```
1. Via SSH:
   cd ~/public_html/simwarga
   php artisan key:generate
   php artisan config:clear
   php artisan cache:clear

2. Via File Manager:
   Delete: storage/framework/cache/
   Delete: bootstrap/cache/
   Re-run artisan commands
```

### Problem: Database Connection Error

**Solution:**
```
1. Verify database exists:
   cPanel → MySQL Databases
   Cek apakah database & user ada

2. Verify credentials di .env:
   DB_HOST=localhost
   DB_DATABASE=username_simwarga_db
   DB_USERNAME=username_simwarga_user
   DB_PASSWORD=correct_password

3. Test connection:
   PHP Artisan tinker
   DB::connection()->getPdo();

4. If still fails:
   - Check database host (bisa jadi bukan localhost)
   - Contact hosting provider
```

### Problem: File Upload Limit

**Solution:**
```
Edit PHP Configuration:
upload_max_filesize = 100M
post_max_size = 100M

Via cPanel:
1. Select PHP Version → PHP Configuration
2. Find settings di atas
3. Change value
4. Save
```

### Problem: HTTPS Redirect Loop

**Solution:**
```
1. Edit .env:
   APP_URL=https://yourdomain.com

2. Edit .htaccess, pastikan tidak ada double redirect

3. Clear browser cache (Ctrl+Shift+Delete)

4. Jika masih loop, disable redirect di .htaccess temporary
```

### Problem: Composer Dependencies Error

**Solution:**
```
1. Check Composer version:
   php -v
   composer --version

2. Update Composer:
   composer self-update

3. Clear cache:
   composer clear-cache

4. Reinstall dependencies:
   cd ~/public_html/simwarga
   composer install --no-dev --optimize-autoloader
```

### Problem: Email Not Sending

**Solution:**
```
1. Test SMTP credentials di .env:
   MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD

2. Common issue: Gmail requires App Password (not regular password)
   - Enable 2FA di Google Account
   - Go: https://myaccount.google.com/apppasswords
   - Generate app password
   - Use di MAIL_PASSWORD

3. Alternative: Gunakan Mailtrap:
   - SignUp: https://mailtrap.io
   - Get credentials
   - Update .env

4. Test via Artisan:
   php artisan tinker
   Mail::raw('Test', function($m) { $m->to('test@example.com'); });
```

### Problem: Slow Loading Performance

**Solution:**
```
1. Enable caching:
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache

2. Optimize database:
   php artisan migrate:fresh (HATI-HATI: menghapus semua data!)
   atau
   php artisan tinker
   DB::connection()->statement("OPTIMIZE TABLE `warga_profiles`");

3. Check slow queries:
   - Enable MySQL slow query log
   - Check: /var/log/mysql/slow.log

4. Upgrade hosting:
   - If resource limit reached
   - Contact hosting provider
```

---

## 📋 Checklist Setup

- [ ] cPanel login berhasil
- [ ] Database & user dibuat
- [ ] Project di-upload ke public_html
- [ ] PHP version 8.2+ terpilih
- [ ] PHP extensions installed
- [ ] .env file dikonfigurasi dengan benar
- [ ] Database migrations berjalan
- [ ] SSL certificate ter-install
- [ ] HTTPS force-redirect active
- [ ] Cron jobs configured
- [ ] File permissions correct (755/644)
- [ ] Gzip compression enabled
- [ ] Email testing successful
- [ ] Application berjalan di https://yourdomain.com
- [ ] Backup strategy implemented

---

## 🔗 Provider-Specific Links

### Niagahoster
- cPanel URL: https://ngh.niagahoster.co.id:2083
- Tutorial: https://kb.niagahoster.co.id

### Hostinger
- cPanel URL: https://hpanel.hostinger.co.id
- Tutorial: https://www.hostinger.co.id/help

### DomaiNesia
- cPanel URL: https://panel.domainesia.com
- Tutorial: https://domainesia.com/tutorial

### MasterWeb
- cPanel URL: https://cPanel.masterweb.id:2083
- Tutorial: https://support.masterweb.id

---

## 📞 Dukungan Teknis

Jika mengalami masalah:

1. **Check Error Logs:**
   - cPanel → Error Log
   - ~/public_html/simwarga/storage/logs/laravel.log

2. **Contact Hosting Provider Support:**
   - Siapkan error log
   - Jelaskan problem dengan detail
   - Minta bantuan PHP/database settings

3. **Check SIMWarga Documentation:**
   - QUICK_REFERENCE.md
   - HOSTING_INSTALLATION.md
   - README.md

4. **GitHub Issues:**
   - https://github.com/igunawan266/simwarga/issues

---

## ⏱️ Estimasi Waktu Setup

| Tahap | Waktu |
|-------|-------|
| Database & User | 5 menit |
| Upload Project | 10-15 menit |
| PHP Configuration | 5 menit |
| .env Configuration | 5 menit |
| Database Migrations | 2 menit |
| SSL Setup | 5-15 menit |
| Testing | 10 menit |
| **Total** | **45-60 menit** |

---

## 📊 Performance Tips

1. **Use SSD Hosting** - Lebih cepat dari HDD
2. **Enable Compression** - .htaccess gzip settings
3. **Cache Strategically** - Laravel config/route caching
4. **Optimize Database** - Regular OPTIMIZE queries
5. **Monitor Resource Usage** - cPanel Resource Usage
6. **Upgrade if Needed** - Dari paket basic ke premium

---

**Last Updated:** September 2026  
**Version:** 1.0.0  
**Compatible with:** Any cPanel Hosting  
**Status:** Production Ready

Untuk bantuan lebih lanjut, hubungi support hosting Anda atau tim SIMWarga di GitHub.
