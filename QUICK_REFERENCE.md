# SIMWarga - Quick Reference Guide

## 🚀 Quick Installation Summary

### Prerequisites
```bash
# Server: Ubuntu 22.04 LTS
# RAM: 4GB+, Storage: 20GB+, PHP 8.2+
```

### 10-Step Installation

```bash
# 1. Update system
sudo apt update && sudo apt upgrade -y

# 2. Install PostgreSQL & PHP
sudo apt install -y postgresql-15 php8.2 php8.2-pgsql redis-server nginx composer

# 3. Create PostgreSQL database
sudo -u postgres createdb simwarga_db
sudo -u postgres psql -c "CREATE USER simwarga_user WITH PASSWORD 'password'"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE simwarga_db TO simwarga_user"

# 4. Clone project
cd /var/www
sudo git clone https://github.com/igunawan266/simwarga.git
sudo chown -R www-data:www-data simwarga

# 5. Install dependencies
cd simwarga
composer install --no-dev

# 6. Configure .env
cp .env.example .env
php artisan key:generate
# Edit .env - set DB credentials & APP_URL

# 7. Run migrations
php artisan migrate

# 8. Configure Nginx (copy config from HOSTING_INSTALLATION.md)
sudo nano /etc/nginx/sites-available/simwarga.conf
sudo ln -s /etc/nginx/sites-available/simwarga.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# 9. Setup SSL
sudo apt install -y certbot python3-certbot-nginx
sudo certbot certonly --nginx -d yourdomain.com

# 10. Start services
sudo systemctl start postgresql php8.2-fpm redis-server
sudo systemctl enable postgresql php8.2-fpm redis-server nginx
```

---

## 📂 File & Directory Structure

```
/var/www/simwarga/
├── app/
│   ├── Models/           # Database models
│   ├── Http/
│   │   ├── Controllers/  # API controllers
│   │   └── Middleware/   # Auth & role middleware
│   ├── Policies/         # RBAC authorization
│   └── Services/         # Business logic
├── database/
│   ├── migrations/       # Database schema
│   └── seeders/          # Initial data
├── routes/
│   └── api.php           # API routes
├── storage/
│   ├── logs/             # Application logs
│   └── app/              # Uploaded files
├── .env                  # Environment config
├── composer.json         # PHP dependencies
└── artisan               # Laravel CLI
```

---

## ⚙️ Essential .env Configuration

```env
APP_NAME=SIMWarga
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=simwarga_db
DB_USERNAME=simwarga_user
DB_PASSWORD=secure_password

# Cache & Session
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_DRIVER=redis

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=app_password
```

---

## 🗄️ Database Commands

### PostgreSQL

```bash
# Connect to database
psql -U simwarga_user -d simwarga_db

# Backup
pg_dump -U simwarga_user simwarga_db > backup.sql

# Restore
psql -U simwarga_user simwarga_db < backup.sql

# Database size
SELECT pg_size_pretty(pg_database_size('simwarga_db'));

# Vacuum & analyze
VACUUM ANALYZE;
```

### MySQL

```bash
# Connect to database
mysql -u simwarga_user -p simwarga_db

# Backup
mysqldump -u simwarga_user -p simwarga_db > backup.sql

# Restore
mysql -u simwarga_user -p simwarga_db < backup.sql

# Database size
SELECT SUM(data_length + index_length) / 1024 / 1024 FROM information_schema.tables WHERE table_schema = 'simwarga_db';

# Optimize
OPTIMIZE TABLE `letter_requests`;
```

---

## 🛠️ Laravel Artisan Commands

```bash
cd /var/www/simwarga

# Database
php artisan migrate              # Run migrations
php artisan migrate:rollback     # Rollback migrations
php artisan db:seed              # Seed default data

# Cache & Config
php artisan cache:clear          # Clear cache
php artisan config:cache         # Cache config
php artisan route:cache          # Cache routes

# Queue
php artisan queue:work           # Start queue worker
php artisan queue:restart        # Restart queue
php artisan queue:failed         # View failed jobs

# Maintenance
php artisan tinker               # Interactive shell
php artisan storage:link         # Create storage symlink
php artisan key:generate         # Generate APP_KEY

# Optimize
php artisan optimize             # Optimize autoloader
php artisan view:clear           # Clear cached views
```

---

## 🌐 Nginx Common Commands

```bash
# Configuration
sudo nginx -t                    # Test configuration
sudo systemctl reload nginx      # Reload config (no downtime)
sudo systemctl restart nginx     # Restart service

# Logs
sudo tail -f /var/log/nginx/access.log
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/simwarga_error.log

# Monitoring
sudo ps aux | grep nginx         # View processes
sudo netstat -tlnp | grep nginx  # View listening ports
```

---

## 🔐 SSL/HTTPS Commands

```bash
# Generate certificate
sudo certbot certonly --nginx -d yourdomain.com

# List certificates
sudo certbot certificates

# Renew certificate
sudo certbot renew
sudo certbot renew --force-renewal

# Auto-renewal
sudo systemctl enable certbot.timer
sudo systemctl status certbot.timer

# View certificate details
openssl x509 -in /etc/letsencrypt/live/yourdomain.com/fullchain.pem -text -noout
```

---

## 🔧 PHP-FPM Commands

```bash
# Service management
sudo systemctl status php8.2-fpm
sudo systemctl restart php8.2-fpm
sudo systemctl reload php8.2-fpm

# Configuration
sudo nano /etc/php/8.2/fpm/pool.d/www.conf

# Logs
sudo tail -f /var/log/php8.2-fpm.log

# Process
ps aux | grep php-fpm
```

---

## 📊 Monitoring Commands

```bash
# Resource usage
free -h                          # Memory
df -h                            # Disk space
top                              # Real-time monitoring
htop                             # Better top

# Network
netstat -tlnp                    # Listening ports
ss -tlnp                         # Socket statistics
nethogs                          # Network per-process

# Database connections
sudo -u postgres psql -c "\conninfo"
mysql -u simwarga_user -p -e "SHOW PROCESSLIST;"

# Application logs
tail -100 /var/www/simwarga/storage/logs/laravel.log
```

---

## 🔄 Backup & Restore

```bash
# Backup database
pg_dump -U simwarga_user simwarga_db > /backups/db_$(date +%Y%m%d).sql
# OR
mysqldump -u simwarga_user -p simwarga_db > /backups/db_$(date +%Y%m%d).sql

# Backup files
tar -czf /backups/app_$(date +%Y%m%d).tar.gz /var/www/simwarga

# Restore database
psql -U simwarga_user simwarga_db < /backups/db_20260915.sql
# OR
mysql -u simwarga_user -p simwarga_db < /backups/db_20260915.sql

# Restore files
tar -xzf /backups/app_20260915.tar.gz -C /
```

---

## 🚨 Common Issues & Solutions

### 502 Bad Gateway
```bash
sudo systemctl restart php8.2-fpm
sudo nginx -t && sudo systemctl reload nginx
sudo tail -f /var/log/nginx/error.log
```

### Database Connection Failed
```bash
# Check database is running
sudo systemctl status postgresql
mysql -u simwarga_user -p simwarga_db

# Check .env credentials
grep DB_ /var/www/simwarga/.env
```

### Permission Denied
```bash
sudo chown -R www-data:www-data /var/www/simwarga
sudo chmod -R 755 /var/www/simwarga/storage
sudo chmod -R 755 /var/www/simwarga/bootstrap/cache
```

### High Memory Usage
```bash
php artisan cache:clear
php artisan config:clear
sudo systemctl restart php8.2-fpm
top  # Check memory usage
```

### Slow Queries
```bash
# Enable slow query log
sudo nano /etc/php/8.2/fpm/php.ini
# Set: mysql.default_socket=/tmp/mysql.sock

# Check query execution
php artisan tinker
DB::enableQueryLog();
# Run query
dd(DB::getQueryLog());
```

### SSL Certificate Issues
```bash
sudo certbot renew --dry-run
sudo systemctl restart nginx
openssl x509 -in /etc/letsencrypt/live/yourdomain.com/fullchain.pem -noout -dates
```

---

## 📱 API Authentication

### Get Token (Sanctum)
```bash
curl -X POST https://yourdomain.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Response:
# {"token":"1|xxx...xxx","user":{"id":1,"email":"user@example.com",...}}
```

### Use Token in Requests
```bash
curl -X GET https://yourdomain.com/api/warga \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 🔑 User Management

### Create Admin User
```bash
cd /var/www/simwarga

php artisan tinker

// Create user
$admin = App\Models\User::create([
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
]);

// Assign admin role
$admin->assignRole('admin');

exit
```

### Reset User Password
```bash
php artisan tinker

$user = App\Models\User::find(1);
$user->update(['password' => bcrypt('newpassword')]);

exit
```

---

## 📞 Useful Links

| Resource | URL |
|----------|-----|
| Laravel Docs | https://laravel.com/docs |
| PostgreSQL Docs | https://www.postgresql.org/docs |
| Nginx Docs | https://nginx.org/en/docs |
| Composer Docs | https://getcomposer.org/doc |
| PHP Docs | https://www.php.net/docs.php |
| Let's Encrypt | https://letsencrypt.org |
| Sanctum Docs | https://laravel.com/docs/sanctum |

---

## 📋 Deployment Checklist

- [ ] Domain name configured
- [ ] Server created & accessible
- [ ] Database installed & tested
- [ ] PHP & extensions installed
- [ ] Laravel project cloned & configured
- [ ] Migrations ran successfully
- [ ] Web server configured
- [ ] SSL certificate installed
- [ ] Email service tested
- [ ] Backups automated
- [ ] Monitoring setup
- [ ] Application tested
- [ ] Performance optimized
- [ ] Security headers configured
- [ ] Logs rotation setup
- [ ] Team trained on maintenance

---

## 🆘 Emergency Commands

```bash
# Application down - check logs
tail -100 /var/www/simwarga/storage/logs/laravel.log

# Clear all cache
cd /var/www/simwarga
php artisan cache:clear
php artisan config:clear
php artisan route:cache

# Fix permissions
sudo chown -R www-data:www-data /var/www/simwarga
sudo chmod -R 755 /var/www/simwarga/storage

# Restart all services
sudo systemctl restart postgresql php8.2-fpm redis-server nginx

# Check disk space
df -h
du -sh /var/www/simwarga

# Database check
sudo -u postgres psql -d simwarga_db -c "SELECT COUNT(*) FROM warga_profiles;"
```

---

**Last Updated:** September 2026  
**Version:** 1.0.0  
**Status:** Ready for Production

Untuk dokumentasi lengkap, lihat: `HOSTING_INSTALLATION.md`
