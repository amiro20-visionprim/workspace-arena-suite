#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════
#  Vision Prime SUITE — کیت راه‌اندازی سرور (فاز F0) — نسخهٔ یک‌شات
#
#  bash server-bootstrap.sh            # بکاپ → کد → دیپلوی → Worker → بکاپ شبانه → فایروال
#  bash server-bootstrap.sh --dry      # فقط نمایش، بدون تغییر
#
#  حالت‌ها:
#   • APP_DIR موجود و git → git pull از گیت‌هاب (new-branch)
#   • APP_DIR موجود بدون git → از patches/ کیت اعمال می‌شود (اگر باشد)
#   • APP_DIR ناموجود → کلون تازه از گیت‌هاب (env/دیتابیس قبلی را از
#     LEGACY_DIR یا DB_FILE/ENV_FILE برمی‌گرداند — مهاجرت امن)
# ═══════════════════════════════════════════════════════════════════
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/vision-prime}"
LEGACY_DIR="${LEGACY_DIR:-}"                 # مسیر نصب قدیمی برای مهاجرت (اختیاری)
REPO_URL="https://github.com/amiro20-visionprim/workspace-arena-suite.git"
REPO_BRANCH="${REPO_BRANCH:-new-branch}"
SSH_PORT="${SSH_PORT:-9011}"
STAMP="$(date +%Y-%m-%d_%H%M)"
LOG="/var/log/vp-bootstrap.log"
BACKUP_DIR="/root/vp-backups/$STAMP"
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
DRY=0; [[ "${1:-}" == "--dry" ]] && DRY=1

log()  { echo -e "$@" | tee -a "$LOG"; }
ok()   { log "  ✅ $*"; }
fail() { log "  ❌ $* — متوقف."; exit 1; }
run()  { if [[ $DRY -eq 1 ]]; then log "  [DRY] $*"; else eval "$@" >> "$LOG" 2>&1 || fail "$*"; fi; }

log "\n════════ $(date '+%F %T') — شروع bootstrap ════════"

# ─────────────── گام ۰: تشخیص محیط ───────────────
log "▸ گام ۰/۹: تشخیص محیط"
PHP_BIN="$(command -v php || true)"; [[ -n "$PHP_BIN" ]] || fail "php نصب نیست (apt install php8.3-cli php8.3-fpm …)"
command -v git >/dev/null || fail "git نصب نیست"
FRESH_CLONE=0

if [[ ! -f "$APP_DIR/artisan" ]]; then
    log "  پروژه در $APP_DIR نیست → کلون تازه از گیت‌هاب (شاخهٔ $REPO_BRANCH)"
    if [[ -n "$DRY" && $DRY -eq 1 ]]; then
        log "  [DRY] git clone -b $REPO_BRANCH $REPO_URL $APP_DIR"
    else
        git clone -q -b "$REPO_BRANCH" "$REPO_URL" "$APP_DIR" || fail "کلون از گیت‌هاب نشد (اینترنت سرور؟)"
    fi
    FRESH_CLONE=1
fi

if [[ -n "$LEGACY_DIR" && -d "$LEGACY_DIR" && $FRESH_CLONE -eq 1 ]]; then
    log "  مهاجرت از نصب قدیمی: $LEGACY_DIR"
    [[ -f "$LEGACY_DIR/.env" ]] && run "cp '$LEGACY_DIR/.env' '$APP_DIR/.env'"
    if [[ -f "$LEGACY_DIR/database/database.sqlite" ]]; then
        run "mkdir -p '$APP_DIR/database' && cp '$LEGACY_DIR/database/database.sqlite' '$APP_DIR/database/database.sqlite'"
    elif [[ -d "$LEGACY_DIR/storage/backups" ]]; then
        run "cp -r '$LEGACY_DIR/storage/backups' '$APP_DIR/storage/'"
    fi
    ok "env/دیتابیس قدیمی منتقل شد"
fi

WEB_USER="$(stat -c '%U' "$APP_DIR/artisan" 2>/dev/null || echo www-data)"
log "  پروژه: $APP_DIR | PHP: $($PHP_BIN -r 'echo PHP_VERSION;') | کاربر: $WEB_USER | کلون‌تازه: $FRESH_CLONE"

# ─────────────── گام ۱: بکاپ کامل ───────────────
log "▸ گام ۱/۹: بکاپ کامل"
run "mkdir -p '$BACKUP_DIR'"
run "tar -C '$APP_DIR' --exclude=vendor --exclude=node_modules -czf '$BACKUP_DIR/code.tar.gz' ."
DB_CONN="$(grep -oP '^DB_CONNECTION=\K.*' "$APP_DIR/.env" 2>/dev/null || echo sqlite)"
if [[ "$DB_CONN" == *"pgsql"* || "$DB_CONN" == *"postgres"* ]]; then
    run "sudo -u postgres pg_dumpall > '$BACKUP_DIR/db.sql'"
else
    run "test -f '$APP_DIR/database/database.sqlite' && cp '$APP_DIR/database/database.sqlite' '$BACKUP_DIR/db.sqlite' || true"
fi
[[ -f "$APP_DIR/.env" ]] && run "cp '$APP_DIR/.env' '$BACKUP_DIR/env.backup'"
run "cp -r /etc/nginx/sites-enabled '$BACKUP_DIR/nginx' 2>/dev/null || true"
[[ $DRY -eq 0 ]] && ok "بکاپ در $BACKUP_DIR"

# ─────────────── گام ۲: کد (pull / patches) ───────────────
log "▸ گام ۲/۹: همگام‌سازی کد"
cd "$APP_DIR" || fail "ورود به $APP_DIR"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    run "git fetch origin --quiet && git checkout -q '$REPO_BRANCH' && git reset --hard origin/$REPO_BRANCH"
    ok "کد روی $(git log --oneline -1 2>/dev/null | head -c 60)"
elif ls "$KIT_DIR"/patches/0*.patch >/dev/null 2>&1; then
    run "git am --ignore-whitespace '$KIT_DIR'/patches/0*.patch"
    ok "پچ‌های کیت اعمال شد"
else
    log "  git نیست و پچ نیست — همان کد موجود استفاده می‌شود"
fi

# ─────────────── گام ۳: وابستگی‌ها و asset ───────────────
log "▸ گام ۳/۹: composer و asset"
if [[ ! -f "$APP_DIR/.env" ]]; then
    run "cp '$APP_DIR/.env.example' '$APP_DIR/.env'"
    log "  ⚠️ .env از example ساخته شد — APP_KEY و DB را بعداً تنظیم کن"
fi
run "composer install --no-dev --optimize-autoloader --no-interaction"
if command -v npm >/dev/null 2>&1; then
    run "npm ci --no-audit --no-fund && npm run build"
else
    if [[ -f "$KIT_DIR/assets/public-build.tar.gz" ]]; then
        run "tar -C '$APP_DIR' -xzf '$KIT_DIR/assets/public-build.tar.gz'"
        ok "assetهای آماده استخراج شد (بدون node)"
    else
        log "  ⚠️ نه node هست نه asset — روی لپ‌تاپ: npm run build && scp -r public/build سرور:"
    fi
fi

# ─────────────── گام ۴: migrate + seed + cache ───────────────
log "▸ گام ۴/۹: دیتابیس و کش"
run "mkdir -p '$APP_DIR/storage/framework/cache' '$APP_DIR/storage/framework/sessions' '$APP_DIR/storage/framework/views' '$APP_DIR/bootstrap/cache'"
run "$PHP_BIN artisan down --render=errors::503 || true"
run "$PHP_BIN artisan migrate --force --no-interaction"
run "$PHP_BIN artisan db:seed --class=RolePermissionSeeder --force"
run "$PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache"
run "chown -R $WEB_USER:$WEB_USER '$APP_DIR/storage' '$APP_DIR/bootstrap/cache' '$APP_DIR/public/build' 2>/dev/null || true"
ok "دیتابیس و کش به‌روز"

# ─────────────── گام ۵: Worker و زمان‌بند ───────────────
log "▸ گام ۵/۹: Supervisor + cron (قلب اتوماسیون)"
if ! command -v supervisord >/dev/null 2>&1 && [[ $DRY -eq 0 ]]; then
    apt-get install -y supervisor >> "$LOG" 2>&1 || fail "نصب supervisor"
fi
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
if [[ $DRY -eq 1 ]]; then log "  [DRY] نصب conf سوپروایزر"; else
    cp /tmp/vp-worker.conf /etc/supervisor/conf.d/vision-prime-worker.conf
    supervisorctl reread >> "$LOG" 2>&1; supervisorctl update >> "$LOG" 2>&1; sleep 2
    supervisorctl status | tee -a "$LOG"
fi
CRON_LINE="* * * * * cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
if [[ $DRY -eq 1 ]]; then log "  [DRY] $CRON_LINE"; elif ! (crontab -l 2>/dev/null | grep -qF "artisan schedule:run"); then
    (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab - && ok "کران‌جاب نصب شد"
else ok "کران‌جاب موجود بود"; fi

# ─────────────── گام ۶: بکاپ شبانه ───────────────
log "▸ گام ۶/۹: بکاپ خودکار شبانه"
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
if [[ $DRY -eq 1 ]]; then log "  [DRY] نصب بکاپ شبانه"; else
    install -m 755 /tmp/vp-nightly /etc/cron.daily/vision-prime-backup
    bash /etc/cron.daily/vision-prime-backup 2>>"$LOG" || log "  (اجرای تستی بکاپ — خروجی را ببین)"
    ok "بکاپ شبانه فعال"
fi

# ─────────────── گام ۷: فایروال ───────────────
log "▸ گام ۷/۹: فایروال"
if command -v ufw >/dev/null 2>&1; then
    run "ufw allow $SSH_PORT/tcp comment 'SSH'"
    run "ufw allow 80/tcp comment 'HTTP'"
    run "ufw allow 443/tcp comment 'HTTPS'"
    if [[ $DRY -eq 0 ]] && ! ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw --force enable >> "$LOG" 2>&1 || true && ok "ufw فعال (SSH:$SSH_PORT + 80 + 443)"
    fi
else
    log "  ufw نیست — apt install ufw و اجرای مجدد"
fi

# ─────────────── گام ۸: سلامت و بازگشت آنلاین ───────────────
log "▸ گام ۸/۹: سلامت‌سنجی"
run "$PHP_BIN artisan queue:restart || true"
run "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true"
run "$PHP_BIN artisan up"
if [[ $DRY -eq 0 ]]; then
    sleep 3
    H="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/up 2>/dev/null || echo ERR)"
    [[ "$H" == "200" ]] && ok "/up = 200" || log "  ⚠️ /up=$H — ممکن است فقط روی دامنه جواب دهد"
fi

# ─────────────── گام ۹: گام‌های بعدی ───────────────
log "▸ گام ۹/۹: باقی‌مانده (دستی)"
log "  ۱) passwd root  (رمز فعلی افشا شده!)"
log "  ۲) SSL بعد از انتشار NS — سند ۴۹ بخش ۲"
log "  ۳) کلید کاوه‌نگار/AI و APP_KEY جدید در .env"
log "  ۴) \$PHP_BIN artisan app:health برای گزارش سلامت"
[[ $DRY -eq 1 ]] && log "\n(DRY — تغییری اعمال نشد)"
log "════════ پایان ════════"
