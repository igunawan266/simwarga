# Panduan Instalasi Hosting SIMWarga

## 📋 Daftar Isi
1. [Persyaratan Server](#persyaratan-server)
2. [Setup Server](#setup-server)
3. [Instalasi Database](#instalasi-database)
4. [Instalasi PHP & Laravel](#instalasi-php--laravel)
5. [Konfigurasi Web Server](#konfigurasi-web-server)
6. [SSL/HTTPS Setup](#sslhttps-setup)
7. [Konfigurasi Email](#konfigurasi-email)
8. [Backup & Restore](#backup--restore)
9. [Monitoring & Maintenance](#monitoring--maintenance)
10. [Troubleshooting](#troubleshooting)

---

## 🖥️ Persyaratan Server

### Minimum Requirements
- **OS**: Ubuntu 20.04 LTS atau CentOS 8+
- **RAM**: 2GB (development), 4GB (production)
- **Storage**: 20GB (dapat disesuaikan)
- **Processor**: 2 Core minimum
- **Bandwidth**: Unlimited

### Recommended Specifications (Production)
- **OS**: Ubuntu 22.04 LTS
- **RAM**: 8GB
- **Storage**: 100GB SSD
- **Processor**: 4 Core
- **Database**: PostgreSQL 15 atau MySQL 8

### Software Requirements
- PHP 8.2 atau lebih tinggi
- Laravel 11
- Composer
- Redis (untuk cache & queue)
- PostgreSQL 15 atau MySQL 8
- Nginx atau Apache
- Git

---

## 🔧 Setup Server

### 1. Update System

```bash
# Update package manager
sudo apt update
sudo apt upgrade -y

# Install essential tools
sudo apt install -y curl wget git vim nano htop
sudo apt install -y build-essential libssl-dev libffi-dev
```

### 2. Create Application User

```bash
# Create user untuk menjalankan aplikasi (bukan root)
sudo useradd -m -s /bin/bash simwarga
sudo usermod -aG sudo simwarga

# Switch ke user baru
sudo su - simwarga
```

### 3. Konfigurasi Firewall (UFW)

```bash
sudo ufw enable
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw allow 3306/tcp  # MySQL (jika akses eksternal diperlukan)
sudo ufw allow 5432/tcp  # PostgreSQL (jika akses eksternal diperlukan)
```

### 4. Setup SSH Key Authentication

```bash
# Generate SSH key di local machine
ssh-keygen -t rsa -b 4096 -f ~/.ssh/simwarga_key

# Copy public key ke server
ssh-copy-id -i ~/.ssh/simwarga_key.pub simwarga@server_ip

# Login dengan SSH key
ssh -i ~/.ssh/simwarga_key simwarga@server_ip
```

---

## 📦 Instalasi Database

### Pilihan A: PostgreSQL (Recommended)

#### Install PostgreSQL 15

```bash
# Add PostgreSQL repository
sudo apt install -y postgresql-common postgresql-15 postgresql-contrib-15

# Start PostgreSQL service
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Check status
sudo systemctl status postgresql
```

#### Setup Database & User

```bash
# Login sebagai postgres user
sudo -u postgres psql

# Create database
CREATE DATABASE simwarga_db;

# Create user
CREATE USER simwarga_user WITH PASSWORD 'your_secure_password_here';

# Grant privileges
ALTER ROLE simwarga_user SET client_encoding TO 'utf8';
ALTER ROLE simwarga_user SET default_transaction_isolation TO 'read committed';
ALTER ROLE simwarga_user SET default_transaction_deferrable TO on;
ALTER ROLE simwarga_user SET default_time_zone TO 'UTC';

# Grant all privileges
GRANT ALL PRIVILEGES ON DATABASE simwarga_db TO simwarga_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO simwarga_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO simwarga_user;

# Exit psql
\q
```

#### Test Connection

```bash
# Test koneksi dari user simwarga
psql -h localhost -U simwarga_user -d simwarga_db -W

# Type password dan test query
SELECT version();
\q
```

---

### Pilihan B: MySQL 8

#### Install MySQL 8

```bash
# Install MySQL
sudo apt install -y mysql-server mysql-client

# Run MySQL setup wizard (optional)
sudo mysql_secure_installation

# Start MySQL service
sudo systemctl start mysql
sudo systemctl enable mysql

# Check status
sudo systemctl status mysql
```

#### Setup Database & User

```bash
# Login ke MySQL
sudo mysql -u root

# Create database
CREATE DATABASE simwarga_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Create user
CREATE USER 'simwarga_user'@'localhost' IDENTIFIED BY 'your_secure_password_here';
CREATE USER 'simwarga_user'@'%' IDENTIFIED BY 'your_secure_password_here';

# Grant privileges
GRANT ALL PRIVILEGES ON simwarga_db.* TO 'simwarga_user'@'localhost';
GRANT ALL PRIVILEGES ON simwarga_db.* TO 'simwarga_user'@'%';

FLUSH PRIVILEGES;

# Exit MySQL
EXIT;
```

#### Test Connection

```bash
# Test koneksi
mysql -h localhost -u simwarga_user -p simwarga_db

# Type password dan test query
SELECT VERSION();
EXIT;
```

---

## 🐘 Instalasi PHP & Laravel

### 1. Install PHP 8.2 & Extensions

```bash
# Add PHP repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP dan extensions yang diperlukan
sudo apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-common
sudo apt install -y php8.2-pgsql php8.2-mysql php8.2-mbstring
sudo apt install -y php8.2-xml php8.2-gd php8.2-curl php8.2-zip
sudo apt install -y php8.2-bcmath php8.2-intl php8.2-redis

# Start PHP-FPM service
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm

# Check status
sudo systemctl status php8.2-fpm
```

### 2. Install Composer

```bash
# Download Composer
curl -sS https://getcomposer.org/installer | php

# Move ke /usr/local/bin
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Verify installation
composer --version
```

### 3. Clone & Setup Laravel Project

```bash
# Navigate ke aplikasi directory
cd /var/www

# Clone repository (ganti dengan URL repo Anda)
sudo git clone https://github.com/igunawan266/simwarga.git
cd simwarga

# Change ownership ke user simwarga
sudo chown -R simwarga:simwarga /var/www/simwarga
sudo chmod -R 755 /var/www/simwarga
sudo chmod -R 755 /var/www/simwarga/storage
sudo chmod -R 755 /var/www/simwarga/bootstrap/cache
```

### 4. Install Laravel Dependencies

```bash
# Login sebagai simwarga user
sudo su - simwarga

# Navigate ke project
cd /var/www/simwarga

# Install dependencies dengan composer
composer install --optimize-autoloader --no-dev

# Generate APP_KEY
php artisan key:generate

# Create .env file
cp .env.example .env

# Edit .env (lihat bagian konfigurasi di bawah)
nano .env
```

### 5. Konfigurasi .env File

```bash
nano .env
```

**Edit nilai berikut:**

```env
# Application
APP_NAME=SIMWarga
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:xxxxx (sudah di-generate)
APP_URL=https://yourdomain.com

# Database (PostgreSQL)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=simwarga_db
DB_USERNAME=simwarga_user
DB_PASSWORD=your_secure_password_here

# Atau Database (MySQL)
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=simwarga_db
# DB_USERNAME=simwarga_user
# DB_PASSWORD=your_secure_password_here

# Cache & Session
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_DRIVER=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@simwarga.local
MAIL_FROM_NAME="SIMWarga"

# File Upload
FILESYSTEM_DISK=local
```

### 6. Install Redis

```bash
# Install Redis
sudo apt install -y redis-server

# Start Redis service
sudo systemctl start redis-server
sudo systemctl enable redis-server

# Verify installation
redis-cli ping
# Output: PONG
```

### 7. Run Database Migrations

```bash
# Pastikan masih logged in sebagai simwarga user
cd /var/www/simwarga

# Run migrations
php artisan migrate

# Seed default roles (optional)
php artisan db:seed

# Clear cache
php artisan config:cache
php artisan cache:clear
```

---

## 🌐 Konfigurasi Web Server

### Pilihan A: Nginx (Recommended)

#### Install Nginx

```bash
sudo apt install -y nginx
sudo systemctl start nginx
sudo systemctl enable nginx
```

#### Konfigurasi Nginx Virtual Host

```bash
# Create nginx configuration file
sudo nano /etc/nginx/sites-available/simwarga.conf
```

**Paste konfigurasi berikut:**

```nginx
# Redirect HTTP ke HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name yourdomain.com www.yourdomain.com;
    
    return 301 https://$server_name$request_uri;
}

# HTTPS Server Block
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;

    # SSL certificates (disetup di bagian SSL)
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    # SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Root directory
    root /var/www/simwarga/public;

    # Index files
    index index.php index.html index.htm;

    # Charset
    charset utf-8;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1000;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss;

    # Logs
    access_log /var/log/nginx/simwarga_access.log;
    error_log /var/log/nginx/simwarga_error.log;

    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # PHP-FPM configuration
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Laravel public folder
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

#### Enable Virtual Host

```bash
# Create symlink ke sites-enabled
sudo ln -s /etc/nginx/sites-available/simwarga.conf /etc/nginx/sites-enabled/

# Test konfigurasi Nginx
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

---

### Pilihan B: Apache

#### Install Apache & PHP Module

```bash
sudo apt install -y apache2 libapache2-mod-php8.2
sudo a2enmod rewrite
sudo a2enmod ssl
sudo systemctl restart apache2
```

#### Konfigurasi Apache Virtual Host

```bash
# Create Apache configuration file
sudo nano /etc/apache2/sites-available/simwarga.conf
```

**Paste konfigurasi berikut:**

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    
    # Redirect to HTTPS
    Redirect permanent / https://yourdomain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    
    DocumentRoot /var/www/simwarga/public

    # SSL certificates (disetup di bagian SSL)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem

    # Laravel .htaccess
    <Directory /var/www/simwarga/public>
        AllowOverride All
        Require all granted
        
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule ^ index.php [QSA,L]
        </IfModule>
    </Directory>

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/simwarga_error.log
    CustomLog ${APACHE_LOG_DIR}/simwarga_access.log combined
</VirtualHost>
```

#### Enable Virtual Host

```bash
# Enable Apache modules & site
sudo a2ensite simwarga.conf
sudo a2dissite 000-default.conf (optional)

# Test konfigurasi Apache
sudo apache2ctl configtest

# Reload Apache
sudo systemctl reload apache2
```

---

## 🔒 SSL/HTTPS Setup

### Install Certbot (Let's Encrypt)

```bash
# Install Certbot
sudo apt install -y certbot python3-certbot-nginx
# Atau untuk Apache:
# sudo apt install -y certbot python3-certbot-apache
```

### Generate SSL Certificate

```bash
# Generate sertifikat untuk domain
sudo certbot certonly --nginx -d yourdomain.com -d www.yourdomain.com
# Atau untuk Apache:
# sudo certbot certonly --apache -d yourdomain.com -d www.yourdomain.com

# Follow instruksi & pilih email untuk notifikasi pembaruan
```

### Auto-Renewal SSL Certificate

```bash
# Enable auto-renewal
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer

# Test renewal
sudo certbot renew --dry-run

# Check status
sudo systemctl status certbot.timer
```

---

## ✉️ Konfigurasi Email

### Option 1: Menggunakan Mailtrap (Development/Testing)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@simwarga.local
MAIL_FROM_NAME="SIMWarga"
```

### Option 2: Menggunakan Gmail SMTP

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_gmail@gmail.com
MAIL_PASSWORD=your_app_password (bukan password Gmail biasa)
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your_gmail@gmail.com
MAIL_FROM_NAME="SIMWarga"
```

**Setup Gmail App Password:**
1. Enable 2-Factor Authentication di Google Account
2. Go to https://myaccount.google.com/apppasswords
3. Generate app-specific password
4. Copy password ke MAIL_PASSWORD

### Option 3: Menggunakan SendGrid

```env
MAIL_MAILER=sendgrid
SENDGRID_API_KEY=your_sendgrid_api_key
MAIL_FROM_ADDRESS=noreply@simwarga.local
MAIL_FROM_NAME="SIMWarga"
```

### Test Email Configuration

```bash
# Login ke server
ssh simwarga@server_ip

# Navigate ke project
cd /var/www/simwarga

# Test email dengan Tinker
php artisan tinker

# Dalam Tinker:
Mail::raw('Test email', function($message) {
    $message->to('your_email@example.com')->subject('SIMWarga Test');
});

# Exit Tinker
exit
```

---

## 💾 Backup & Restore

### 1. Database Backup Script

**Create backup script:**

```bash
# Create backup directory
mkdir -p /backups/simwarga

# Create backup script
sudo nano /usr/local/bin/backup-simwarga.sh
```

**Script content untuk PostgreSQL:**

```bash
#!/bin/bash

BACKUP_DIR="/backups/simwarga"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="simwarga_db"
DB_USER="simwarga_user"

# Backup database
pg_dump -h localhost -U $DB_USER -d $DB_NAME -F c -b -v -f $BACKUP_DIR/db_backup_$DATE.sql

# Backup aplikasi files
tar -czf $BACKUP_DIR/app_backup_$DATE.tar.gz /var/www/simwarga

# Delete backups older than 30 days
find $BACKUP_DIR -type f -mtime +30 -delete

# Upload ke cloud storage (optional)
# aws s3 cp $BACKUP_DIR/db_backup_$DATE.sql s3://your-bucket/backups/
# aws s3 cp $BACKUP_DIR/app_backup_$DATE.tar.gz s3://your-bucket/backups/

echo "Backup completed at $DATE"
```

**Make script executable:**

```bash
sudo chmod +x /usr/local/bin/backup-simwarga.sh
```

### 2. Automated Backup dengan Cron

```bash
# Edit crontab
sudo crontab -e

# Add scheduled backup (daily at 2 AM)
0 2 * * * /usr/local/bin/backup-simwarga.sh

# Or (daily at 2 AM & weekly at Sunday 3 AM)
0 2 * * * /usr/local/bin/backup-simwarga.sh
0 3 * * 0 /usr/local/bin/backup-simwarga.sh >> /var/log/simwarga_backup.log 2>&1
```

### 3. Restore Database

```bash
# Restore dari PostgreSQL backup
pg_restore -h localhost -U simwarga_user -d simwarga_db -v /backups/simwarga/db_backup_YYYYMMDD_HHMMSS.sql

# Atau dari MySQL backup
mysql -u simwarga_user -p simwarga_db < /backups/simwarga/db_backup_YYYYMMDD_HHMMSS.sql
```

### 4. Restore Aplikasi Files

```bash
# Backup current files
sudo cp -r /var/www/simwarga /var/www/simwarga.backup

# Restore dari backup
tar -xzf /backups/simwarga/app_backup_YYYYMMDD_HHMMSS.tar.gz -C /

# Reset permissions
sudo chown -R simwarga:simwarga /var/www/simwarga
sudo chmod -R 755 /var/www/simwarga/storage
```

---

## 📊 Monitoring & Maintenance

### 1. Monitor Server Resources

```bash
# Install monitoring tools
sudo apt install -y htop iotop nethogs

# Real-time monitoring
htop

# Monitor disk I/O
iotop

# Monitor network connections
nethogs
```

### 2. Monitor Application Logs

```bash
# View Laravel logs
tail -f /var/www/simwarga/storage/logs/laravel.log

# View Nginx logs
sudo tail -f /var/log/nginx/simwarga_access.log
sudo tail -f /var/log/nginx/simwarga_error.log

# View PHP-FPM logs
sudo tail -f /var/log/php8.2-fpm.log

# Search errors in logs
grep "ERROR" /var/www/simwarga/storage/logs/laravel.log
```

### 3. Queue Worker Monitoring

```bash
# Start queue worker
cd /var/www/simwarga
php artisan queue:work

# Or use Supervisor to manage queue worker
sudo apt install -y supervisor
```

**Create Supervisor config:**

```bash
sudo nano /etc/supervisor/conf.d/simwarga-worker.conf
```

**Content:**

```ini
[program:simwarga-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/simwarga/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/simwarga-worker.log
environment=PATH="/usr/local/bin:/usr/bin:/bin",USER="simwarga",HOME="/home/simwarga"
```

**Enable Supervisor:**

```bash
sudo systemctl restart supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start simwarga-worker:*
```

### 4. Uptime Monitoring

```bash
# Install Uptime Kuma (free uptime monitoring)
docker run -d --name uptime-kuma -p 3001:3001 \
  -v /var/lib/uptime-kuma:/app/data \
  louislam/uptime-kuma:latest

# Access di: http://server_ip:3001
```

### 5. Database Maintenance

```bash
# PostgreSQL vacuum & analyze (maintenance)
sudo -u postgres vacuumdb simwarga_db
sudo -u postgres analyzedb simwarga_db

# MySQL optimize tables
mysql -u simwarga_user -p simwarga_db -e "OPTIMIZE TABLE \`letter_requests\`, \`financial_transactions\`, \`warga_profiles\`;"

# Check database size
sudo -u postgres psql -c "SELECT pg_size_pretty(pg_database_size('simwarga_db'));"

# Or MySQL:
mysql -u simwarga_user -p simwarga_db -e "SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = 'simwarga_db' ORDER BY size_mb DESC;"
```

---

## 🐛 Troubleshooting

### Problem: 502 Bad Gateway (Nginx)

**Solution:**

```bash
# Check PHP-FPM status
sudo systemctl status php8.2-fpm

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm

# Check Nginx error log
sudo tail -f /var/log/nginx/simwarga_error.log

# Check if socket file exists
ls -la /run/php/php8.2-fpm.sock
```

### Problem: Database Connection Error

**Solution:**

```bash
# Check database is running
sudo systemctl status postgresql
# atau
sudo systemctl status mysql

# Test database connection
psql -h localhost -U simwarga_user -d simwarga_db -W
# atau
mysql -h localhost -u simwarga_user -p simwarga_db

# Check .env database credentials
cat /var/www/simwarga/.env | grep DB_

# Run migrations again
cd /var/www/simwarga
php artisan migrate
```

### Problem: Permission Denied

**Solution:**

```bash
# Fix ownership
sudo chown -R simwarga:simwarga /var/www/simwarga

# Fix file permissions
sudo find /var/www/simwarga -type f -exec chmod 644 {} \;
sudo find /var/www/simwarga -type d -exec chmod 755 {} \;

# Fix storage & bootstrap permissions
sudo chmod -R 755 /var/www/simwarga/storage
sudo chmod -R 755 /var/www/simwarga/bootstrap/cache
```

### Problem: Slow Database Queries

**Solution:**

```bash
# Enable slow query log (PostgreSQL)
sudo -u postgres psql -c "ALTER SYSTEM SET log_min_duration_statement = 1000;"
sudo systemctl restart postgresql

# View slow queries
sudo tail -f /var/log/postgresql/postgresql.log | grep duration

# Or MySQL slow log
sudo tail -f /var/log/mysql/slow.log

# Analyze query performance
EXPLAIN ANALYZE SELECT * FROM warga_profiles WHERE rt_id = 1;
```

### Problem: High Memory Usage

**Solution:**

```bash
# Check memory usage
free -h
top -b -n 1 | head -20

# Reduce PHP-FPM processes
sudo nano /etc/php/8.2/fpm/pool.d/www.conf

# Adjust these values:
# pm.max_children = 50 (reduce from default)
# pm.start_servers = 10
# pm.min_spare_servers = 5
# pm.max_spare_servers = 10

# Clear cache
cd /var/www/simwarga
php artisan cache:clear
php artisan config:clear

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

### Problem: Email Not Sending

**Solution:**

```bash
# Test email configuration
cd /var/www/simwarga
php artisan tinker

# Send test email
Mail::raw('Test email', function($message) {
    $message->to('test@example.com')->subject('Test');
});

# Check mail log
tail -f /var/log/mail.log

# Or test SMTP connection
telnet smtp.mailtrap.io 2525
```

### Problem: SSL Certificate Not Found

**Solution:**

```bash
# Renew certificate
sudo certbot renew

# Force renew
sudo certbot renew --force-renewal

# Check certificate validity
sudo ssl-cert-check -c /etc/letsencrypt/live/yourdomain.com/fullchain.pem

# List all certificates
sudo certbot certificates
```

---

## 📋 Deployment Checklist

- [ ] Server OS updated & secured
- [ ] Database installed & configured
- [ ] PHP 8.2 & extensions installed
- [ ] Composer installed & dependencies configured
- [ ] Laravel .env configured correctly
- [ ] Database migrations completed
- [ ] Web server (Nginx/Apache) configured
- [ ] SSL certificate installed & auto-renewal enabled
- [ ] Email service configured & tested
- [ ] Backup script created & scheduled
- [ ] Monitoring tools installed
- [ ] Firewall rules configured
- [ ] SSH key authentication enabled
- [ ] Application tested & working
- [ ] Logs monitored & rotation configured
- [ ] Performance optimized (caching, compression)
- [ ] Security headers configured
- [ ] Database backups automated
- [ ] Application backups automated
- [ ] Documentation created for team

---

## 🔄 Update & Maintenance Schedule

### Weekly
- [ ] Check server disk space
- [ ] Review error logs
- [ ] Monitor database size
- [ ] Verify backups completed

### Monthly
- [ ] Update system packages: `sudo apt update && sudo apt upgrade`
- [ ] Review application logs for issues
- [ ] Check database performance
- [ ] Analyze storage usage

### Quarterly
- [ ] Full security audit
- [ ] Database optimization
- [ ] Performance testing
- [ ] Update documentation

### Annually
- [ ] Server hardware review
- [ ] Disaster recovery drill
- [ ] Security penetration testing
- [ ] Capacity planning

---

## 📞 Support Resources

- **Laravel Documentation**: https://laravel.com/docs
- **PostgreSQL Documentation**: https://www.postgresql.org/docs
- **Nginx Documentation**: https://nginx.org/en/docs
- **SSL/HTTPS**: https://letsencrypt.org/docs
- **Ubuntu Server**: https://ubuntu.com/server/docs

---

**Last Updated:** September 2026
**Version:** 1.0.0
**Environment:** Production Ready
