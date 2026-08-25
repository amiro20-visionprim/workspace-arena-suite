# سند ۴۹ — ران‌بوک عملیاتی سرور (اجرای دستی توسط مالک)

> **هدف:** پنج کاری که از داخل مخزن قابل انجام نیستند، با دستور دقیق و قابل کپی.
> سرور فعلی طبق گزارش میدانی: Ubuntu 24.04 · PHP 8.3-fpm + nginx · دیتابیس SQLite · دامنهٔ `visionprime-suite.ir`
> ⚠️ قبل از هر مرحله: `sudo cp -a /path/backup /path/backup.$(date +%F)` — بکاپ بگیر.

---

## ۱) چرخش کلیدها (فوری‌ترین — همین امروز)

فایل `.env.local-backup` با `APP_KEY` واقعی در تاریخچهٔ گیت کامیت شده بوده است.

### ۱-۱) پاک‌سازی تاریخچهٔ گیت

روی سیستم توسعه (بعد از push پچ‌ها):

```bash
cd workspace-arena-suite
pip install git-filter-repo
git filter-repo --path vision-prime/.env.local-backup --invert-paths
# فورس‌پوش (هماهنگ کن: همهٔ cloneها باید از نو clone شوند)
git push --force --all
git push --force --tags
```

> اگر ریسک فورس‌پوش مهم است (همکار زیاد)، حداقل: کلید را بچرخان (زیر) و مخزن را خصوصی نگه دار.

### ۱-۲) چرخش APP_KEY روی سرور

```bash
cd /var/www/vision-prime   # مسیر نصب
php artisan down           # حالت تعمیر — کاربران پیام بروزرسانی ببینند
cp .env .env.backup-$(date +%F)
php artisan key:generate   # APP_KEY جدید در .env
# نکته: با درایور سشن database/redis (نه cookie-encrypt قدیمی) کاربران فقط logout می‌شوند
php artisan config:cache
php artisan up
```

### ۱-۳) رمز دیتابیس و سرویس‌ها

هر جا اعتبارنامه‌ای در `.env` است (DB_PASSWORD، کلیدهای API) — همه را عوض کن و در `.env` جدید بنویس. کلیدهای کاوه‌نگار/زرین‌پال از پنل خودشان reset شوند.

---

## ۲) SSL با Let's Encrypt — چالش DNS-01 (رفع مشکل «DNS ایران»)

چون رکوردهای NS ممکن است از ایران به پورت ۸۰ let's encrypt نرسند، مطمئن‌ترین راه **DNS-01** است (بدون نیاز به HTTP):

```bash
sudo apt install -y certbot python3-certbot-dns-cloudflare   # یا dns-cloudns/dns-desec مطابق DNS هاست
sudo nano /etc/letsencrypt/visionprime-dns.ini               # توکن API زون DNS
#   dns_cloudflare_api_token = XXXXX
sudo chmod 600 /etc/letsencrypt/visionprime-dns.ini

sudo certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/visionprime-dns.ini \
  -d visionprime-suite.ir -d www.visionprime-suite.ir \
  --email you@visionprime-suite.ir --agree-tos -n
```

سپس در nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name visionprime-suite.ir www.visionprime-suite.ir;
    ssl_certificate     /etc/letsencrypt/live/visionprime-suite.ir/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/visionprime-suite.ir/privkey.pem;
    # ... بقیهٔ تنظیمات فعلی server
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}
server {
    listen 80;
    server_name visionprime-suite.ir www.visionprime-suite.ir;
    return 301 https://$host$request_uri;
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
# تمدید خودکار (cerbot خودش timer دارد):
sudo systemctl status certbot.timer
```

تأیید: `curl -I https://visionprime-suite.ir/up` باید 200 بدهد و `.env` باید `APP_URL=https://...` باشد.

---

## ۳) فعال‌سازی واقعی اتوماسیون (cron + Supervisor)

بدون این دو، **هیچ‌کدام** از ۱۲ تسک زمان‌بندی‌شده و Jobها اجرا نمی‌شوند (اتوماسیون خاموش است).

### ۳-۱) کران‌جاب

```bash
crontab -e
# این خط را اضافه کن:
* * * * * cd /var/www/vision-prime && php artisan schedule:run >> /dev/null 2>&1
```

### ۳-۲) Supervisor برای worker صف

```bash
sudo apt install -y supervisor
sudo tee /etc/supervisor/conf.d/vision-prime-worker.conf > /dev/null << 'EOF'
[program:vision-prime-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/vision-prime/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/vision-prime-worker.log
stopwaitsecs=3600
EOF

sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

تأیید: `php artisan schedule:list` باید تسک‌ها را نشان دهد و `sudo supervisorctl status` باید `RUNNING` بگوید. پس از هر دیپلوی: `php artisan queue:restart`.

---

## ۴) مهاجرت SQLite → PostgreSQL

```bash
# ۱) بکاپ
cd /var/www/vision-prime && php artisan down
sqlite3 database/database.sqlite ".backup '/root/backup-$(date +%F).sqlite'"

# ۲) نصب و ساخت دیتابیس
sudo apt install -y postgresql php8.3-pgsql
sudo -u postgres psql -c "CREATE USER vision_prime WITH PASSWORD 'رمزجدیدقوی';"
sudo -u postgres psql -c "CREATE DATABASE vision_prime OWNER vision_prime;"

# ۳) اسکیمای جدید روی یک کلون تازه از مخزن
#    (بدون اجرای seed دیتای قدیمی!)
DB_CONNECTION=pgsql … php artisan migrate --force

# ۴) انتقال داده (ترتیب جداول مهم است — FK)
#    ابزار ساده و مطمئن: اسکریپت php کوچک با DB::table روی هر دو اتصال،
#    یا pgloader (توصیه‌شده):
sudo apt install -y pgloader
pgloader sqlite:///root/backup-2026-08-25.sqlite postgresql://vision_prime:رمز@localhost/vision_prime
#    سپس بررسی سکوئنس‌ها:
sudo -u postgres psql -d vision_prime -c "SELECT setval(pg_get_serial_sequence('users','id'), (SELECT COALESCE(MAX(id),1) FROM users));"
#    (برای همهٔ جداول id-دار تکرار کن — یا از حلقهٔ pg/bash استفاده کن)

# ۵) سوییچ .env
#   DB_CONNECTION=pgsql  DB_HOST=127.0.0.1  DB_PORT=5432
#   DB_DATABASE=vision_prime  DB_USERNAME=vision_prime  DB_PASSWORD=رمزجدید
php artisan config:cache && php artisan migrate --force   # باید «Nothing to migrate» بگوید
php artisan up
```

تأیید نهایی: `php artisan tinker → DB::table('users')->count()` و لاگین با حساب دمو.

---

## ۵) فایروال + بکاپ خودکار

```bash
# فایروال — فقط پورت‌های لازم
sudo ufw allow 9011/tcp    # SSH
sudo ufw allow 80,443/tcp  # وب
sudo ufw enable && sudo ufw status

# بکاپ شبانه دیتابیس + env (نگه‌داری ۱۴ روز)
sudo tee /etc/cron.daily/vision-prime-backup > /dev/null << 'EOF'
#!/bin/bash
set -e
mkdir -p /var/backups/vision-prime
cd /var/www/vision-prime
sudo -u www-data php artisan down
if [ -f database/database.sqlite ]; then
  sqlite3 database/database.sqlite ".backup /var/backups/vision-prime/db-$(date +%F).sqlite"
else
  sudo -u postgres pg_dump vision_prime > /var/backups/vision-prime/db-$(date +%F).sql
fi
sudo -u www-data php artisan up
cp .env /var/backups/vision-prime/env-$(date +%F)
find /var/backups/vision-prime -mtime +14 -delete
EOF
sudo chmod +x /etc/cron.daily/vision-prime-backup
```

> دستور آمادهٔ `php artisan platform:backup-db` هم در پروژه هست — این اسکریپت همان را کامل‌تر (env + retention) انجام می‌دهد.

---

## چک‌لیست نهایی «آمادهٔ مشتری»

- [ ] `https://visionprime-suite.ir/up` → 200 سبز (قفل SSL در مرورگر)
- [ ] ثبت‌نام کاربر تازه با کد پیامکی موفق
- [ ] `sudo supervisorctl status` → دو worker در حال RUNNING
- [ ] `php artisan schedule:list` → تسک‌ها دیده می‌شوند و `storage/logs/laravel.log` خطای cron ندارد
- [ ] دیتابیس pgsql + بکاپ شبانه تست‌شده (یک فایل بکاپ را واقعاً restore کن!)
- [ ] تب Actions گیت‌هاب: اولین اجرای سبز quality (workflow حالا در ریشهٔ مخزن است)
- [ ] `vision-prime-docs/STATUS.md` با واقعیت جدید هم‌روز شود
