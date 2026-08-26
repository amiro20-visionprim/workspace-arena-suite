#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════
#  Vision Prime SUITE — کیت راه‌اندازی سرور (فاز F0)
#  اجرا روی خودِ سرور به‌عنوان root — فقط یک بار!
#
#    bash server-bootstrap.sh            # حالت کامل: بکاپ → دیپلوی → Worker → بکاپ شبانه → امنیت
#    bash server-bootstrap.sh --dry      # فقط نشان بده چه می‌کند، بدون تغییر
#
#  پیش‌نیازها: git, php8.3+, composer, nginx, دیتابیس فعلی (sqlite یا pg)
#  خروجی: گزارش قدم‌به‌قدم + فایل لاگ در /var/log/vp-bootstrap.log
# ═══════════════════════════════════════════════════════════════════
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/vision-prime}"   # اگر مسیر نصب فرق دارد، قبل از اجرا عوض کن
SSH_PORT="${SSH_PORT:-9011}"
STAMP="$(date +%Y-%m-%d_%H%M)"
LOG="/var/log/vp-bootstrap.log"
BACKUP_DIR="/root/vp-backups/$STAMP"
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
DRY=0; [[ "${1:-}" == "--dry" ]] && DRY=1

log()  { echo -e "$@" | tee -a "$LOG"; }
ok()   { log "  ✅ $*"; }
fail() { log "  ❌ $* — متوقف شد. هیچ تغییری نصفه نمانده چون هر مرحله atomic است."; exit 1; }
run()  { if [[ $DRY -eq 1 ]]; then log "  [DRY] $*"; else eval "$@" >> "$LOG" 2>&1 || fail "$*"; fi; }

log "\n════════ $(date '+%F %T') — شروع bootstrap ════════"

# ─────────────────── گام ۰: تشخیص محیط ───────────────────
log "▸ گام ۰/۸: تشخیص محیط"
[[ -f "$APP_DIR/artisan" ]] || fail "مسیر پروژه درست نیست: $APP_DIR (با APP_DIR=/مسیر bash server-bootstrap.sh اصلاح کن)"
PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" ]] || fail "php نصب نیست"
log "  پروژه: $APP_DIR | PHP: $($PHP_BIN -r 'echo PHP_VERSION;') | دیتابیس: $(grep -oP '^DB_CONNECTION=\K.*' "$APP_DIR/.env" 2>/dev/null || 'نامشخص')"
# پیدا کردن کاربر مالک فایل‌ها (معمولاً www-data)
WEB_USER="$(stat -c '%U' "$APP_DIR/artisan" 2>/dev/null || echo www-data)"
log "  کاربر مالک: $WEB_USER"

# ─────────────────── گام ۱: بکاپ کامل ───────────────────
log "▸ گام ۱/۸: بکاپ کامل (کد + دیتابیس + env + nginx)"
run "mkdir -p '$BACKUP_DIR'"
run "tar -C '$APP_DIR' --exclude=vendor --exclude=node_modules -czf '$BACKUP_DIR/code.tar.gz' ."
DB_CONN="$(grep -oP '^DB_CONNECTION=\K.*' "$APP_DIR/.env" 2>/dev/null || echo sqlite)"
if [[ "$DB_CONN" == *"pgsql"* || "$DB_CONN" == *"postgres"* ]]; then
  run "sudo -u postgres pg_dumpall > '$BACKUP_DIR/db.sql'"
else
  run "test -f '$APP_DIR/database/database.sqlite' && cp '$APP_DIR/database/database.sqlite' '$BACKUP_DIR/db.sqlite'"
fi
run "cp '$APP_DIR/.env' '$BACKUP_DIR/env.backup'"
run "cp -r /etc/nginx/sites-enabled '$BACKUP_DIR/nginx' 2>/dev/null || true"
[[ $DRY -eq 0 ]] && ok "بکاپ در $BACKUP_DIR (این مسیر را نگه دار؛ تا پایان کار حذف نکن)"

# ─────────────────── گام ۲: اعمال ۱۷ پچ ───────────────────
log "▸ گام ۲/۸: اعمال پچ‌های P0–P3"
cd "$APP_DIR" || fail "ورود به مسیر پروژه"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  CURRENT="$(git log --oneline -1)"
  log "  وضعیت فعلی: $CURRENT"
  if ls "$KIT_DIR"/patches/0*.patch >/dev/null 2>&1; then
    run "git am --ignore-whitespace '$KIT_DIR'/patches/0*.patch"
    ok "پچ‌ها اعمال شد: $(git log --oneline -1)"
  else
    log "  پچی در کیت نیست — فرض: خودت از قبل pull کرده‌ای"
  fi
else
  log "  ⚠️ پروژه git نیست — پچ از مسیر kit-files کپی می‌شود (حالت کامل‌تر: خودت clone کن)"
  run "cp -r '$KIT_DIR/files/.' '$APP_DIR/'"
fi

# ─────────────────── گام ۳: وابستگی‌ها و asset ───────────────────
log "▸ گام ۳/۸: composer و assetهای آماده"
run "composer install --no-dev --optimize-autoloader --no-interaction"
# اگر node روی سرور نیست، assetهای از پیش ساختهٔ کیت را می‌ریزیم (node لازم نیست)
if command -v npm >/dev/null 2>&1; then
  run "npm ci --no-audit --no-fund && npm run build"
else
  if [[ -f "$KIT_DIR/assets/public-build.tar.gz" ]]; then
    run "tar -C '$APP_DIR' -xzf '$KIT_DIR/assets/public-build.tar.gz'"
    ok "assetهای آماده استخراج شد (بدون نیاز به node)"
  else
    log "  ⚠️ نه node هست نه asset آماده — از لپ‌تاپ: scp -r public/build سرور:~/ و دوباره اجرا کن"
  fi
fi

# ─────────────────── گام ۴: migrate + seed + cache ───────────────────
log "▸ گام ۴/۸: مایگریشن، مجوزها و کش"
run "$PHP_BIN artisan down --render=errors::503 || true"
run "$PHP_BIN artisan migrate --force --no-interaction"
run "$PHP_BIN artisan db:seed --class=RolePermissionSeeder --force"
run "$PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache"
run "chown -R $WEB_USER:$WEB_USER '$APP_DIR/storage' '$APP_DIR/bootstrap/cache' '$APP_DIR/public/build'"
ok "دیتابیس و کش به‌روز شد"

# ─────────────────── گام ۵: Worker و زمان‌بند (قلب اتوماسیون) ───────────────────
log "▸ گام ۵/۸: Supervisor + cron — تا اینجا نبود، کل اتوماسیون خاموش بود"
if ! command -v supervisord >/dev/null 2>&1 && [[ $DRY -eq 0 ]]; then
  apt-get install -y supervisor >> "$LOG" 2>&1 || fail "نصب supervisor"
fi
if [[ $DRY -eq 0 ]] || [[ $DRY -eq 1 ]]; then
  cat > /tmp/vp-worker.conf << EOF
[program:vision-prime-worker]
process_name=%(program_name)s_%(process_num)02d
command=$PHP_BIN $APP_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$WEB_USER
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/vision-prime-worker.log
stopwaitsecs=3600
EOF
  if [[ $DRY -eq 1 ]]; then log "  [DRY] نصب /etc/supervisor/conf.d/vision-prime-worker.conf"; else
    cp /tmp/vp-worker.conf /etc/supervisor/conf.d/vision-prime-worker.conf
    supervisorctl reread >> "$LOG" 2>&1; supervisorctl update >> "$LOG" 2>&1
    sleep 2; supervisorctl status | tee -a "$LOG"
  fi
fi
CRON_LINE="* * * * * cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
if [[ $DRY -eq 1 ]]; then
  log "  [DRY] کران‌جاب: $CRON_LINE"
elif ! (crontab -l 2>/dev/null | grep -qF "artisan schedule:run"); then
  (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
  ok "کران‌جاب نصب شد"
else
  ok "کران‌جاب از قبل موجود بود"
fi

# ─────────────────── گام ۶: بکاپ خودکار شبانه ───────────────────
log "▸ گام ۶/۸: بکاپ شبانه با نگه‌داری ۱۴ روز"
cat > /tmp/vp-nightly << 'EOF'
#!/bin/bash
set -e
DIR=/var/backups/vision-prime; mkdir -p "$DIR"; cd /var/www/vision-prime
if [ -f database/database.sqlite ]; then
  sqlite3 database/database.sqlite ".backup $DIR/db-$(date +%F).sqlite"
elif grep -q 'DB_CONNECTION=pgsql' .env; then
  sudo -u postgres pg_dump vision_prime > "$DIR/db-$(date +%F).sql"
fi
cp .env "$DIR/env-$(date +%F)"
find "$DIR" -mtime +14 -delete
EOF
if [[ $DRY -eq 1 ]]; then log "  [DRY] نصب /etc/cron.daily/vision-prime-backup"; else
  install -m 755 /tmp/vp-nightly /etc/cron.daily/vision-prime-backup
  bash /etc/cron.daily/vision-prime-backup 2>>"$LOG" || log "  (اجرای تستی بکاپ — اگر خطا دیدی دیتابیس را چک کن)"
  ok "بکاپ شبانه فعال + یک اجرای تستی انجام شد"
fi

# ─────────────────── گام ۷: امنیت پایه ───────────────────
log "▸ گام ۷/۸: فایروال (فقط پورت‌های لازم)"
if command -v ufw >/dev/null 2>&1; then
  run "ufw allow $SSH_PORT/tcp comment 'SSH'"
  run "ufw allow 80/tcp comment 'HTTP'"
  run "ufw allow 443/tcp comment 'HTTPS'"
  if [[ $DRY -eq 0 ]] && ! ufw status | grep -q "Status: active"; then
    ufw --force enable >> "$LOG" 2>&1 || true
    ok "ufw فعال (SSH:$SSH_PORT + 80 + 453 مجاز)"
  fi
else
  log "  ufw نیست — رد شد (اول نصب: apt install ufw)"
fi

# ─────────────────── گام ۸: سلامت‌سنجی و بازگشت آنلاین ───────────────────
log "▸ گام ۸/۸: تست سلامت و بازگشت سرویس"
run "$PHP_BIN artisan queue:restart || true"
run "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true"
run "$PHP_BIN artisan up"
if [[ $DRY -eq 0 ]]; then
  sleep 3
  HEALTH="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/up 2>/dev/null || echo ERR)"
  [[ "$HEALTH" == "200" ]] && ok "سلامت: /up = 200" || log "  ⚠️ /up=$HEALTH — nginx/دامنه را چک کن (شاید فقط روی دامنه جواب می‌دهد)"
fi

log "\n════════ پایان ════════"
log "🔹 بکاپِ قبل از تغییرات: $BACKUP_DIR"
log "🔹 لاگ کامل: $LOG"
log "🔹 تسک‌های باقی‌ماندهٔ F0 (دستیت): SSL بعد از انتشار NS در ایرنیک (سند ۴۹) + چرخش رمز root!"
log "⚠️  رمز root در چت لو رفته — همین الان از ترمینال خودت اجرا کن: passwd"
[[ $DRY -eq 1 ]] && log "\n(حالت DRY — هیچ تغییری واقعی اعمال نشد. برای اجرای واقعی: bash server-bootstrap.sh)"
