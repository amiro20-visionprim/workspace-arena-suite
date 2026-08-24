#!/usr/bin/env bash
#
# deploy-phase1.sh — دیپلوی امن فاز ۱ (احراز هویت) روی سرور پروداکشن
# ---------------------------------------------------------------
# این اسکریپت را «روی سرور» و «داخل پوشهٔ پروژه» اجرا کنید:
#
#   bash deploy-phase1.sh
#
# ترتیب عملیات (هر مرحله با توقف در صورت خطا):
#   ۱) بکاپ از دیتابیس SQLite
#   ۲) دریافت آخرین تغییرات (git pull)
#   ۳) نصب وابستگی‌ها در صورت نیاز (composer/npm)
#   ۴) اجرای تست‌ها (php artisan test) — در صورت قرمز بودن، متوقف می‌شود
#   ۵) اجرای مایگریشن‌ها (php artisan migrate --force)
#   ۶) پاکسازی کش
#   ۷) ری‌استارت صف (در صورت وجود) — اختیاری
#
# ⚠️ پیش‌نیاز: قبل از اجرا، روی برنچ arena/01a035e3-workspace-arena-suite باشید.
#    git fetch origin && git checkout arena/01a035e3-workspace-arena-suite

set -euo pipefail

# ─────────────────────────────────────────────
# تنظیمات (در صورت نیاز ویرایش کنید)
# ─────────────────────────────────────────────
APP_DIR="${APP_DIR:-$(pwd)}"
BRANCH="${BRANCH:-arena/01a035e3-workspace-arena-suite}"
DB_PATH="${DB_PATH:-$APP_DIR/database/database.sqlite}"
BACKUP_DIR="$APP_DIR/storage/backups"
RUN_TESTS="${RUN_TESTS:-yes}"
RESTART_QUEUE="${RESTART_QUEUE:-no}"

TS="$(date +%Y%m%d_%H%M%S)"

log()  { echo -e "\033[1;34m[deploy]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[ok]\033[0m $*"; }
fail() { echo -e "\033[1;31m[FAIL]\033[0m $*" >&2; exit 1; }

cd "$APP_DIR" || fail "پوشهٔ پروژه یافت نشد: $APP_DIR"
log "پوشهٔ پروژه: $APP_DIR"

# ── ۱) بکاپ ─────────────────────────────────
if [ -f "$DB_PATH" ]; then
  mkdir -p "$BACKUP_DIR"
  cp "$DB_PATH" "$BACKUP_DIR/database-$TS.sqlite"
  ok "بکاپ دیتابیس: $BACKUP_DIR/database-$TS.sqlite"
else
  log "دیتابیس SQLite در مسیر پیش‌فرض یافت نشد؛ بکاپ رد شد (در صورت وجود دیتابیس جای دیگر، مسیر را در DB_PATH بدهید)."
fi

# ── ۲) دریافت تغییرات ───────────────────────
log "دریافت تغییرات از برنچ $BRANCH ..."
git fetch origin || fail "git fetch ناموفق"
git checkout "$BRANCH" 2>/dev/null || fail "برنچ $BRANCH یافت نشد"
git pull origin "$BRANCH" || fail "git pull ناموفق"
ok "کد به‌روز شد"

# ── ۳) وابستگی‌ها ────────────────────────────
if [ -f composer.json ] && [ ! -d vendor ]; then
  log "نصب وابستگی‌های PHP (composer install) ..."
  composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader || fail "composer install ناموفق"
fi

# ── ۴) تست‌ها ────────────────────────────────
if [ "$RUN_TESTS" = "yes" ]; then
  log "اجرای تست‌ها ..."
  if ! php artisan test; then
    fail "تست‌ها قرمز شدند. دیپلوی متوقف شد — دیتابیس و کد فعلی دست‌نخورده ماند."
  fi
  ok "همهٔ تست‌ها سبز شدند"
fi

# ── ۵) مایگریشن ──────────────────────────────
log "اجرای مایگریشن‌ها ..."
php artisan migrate --force || fail "مایگریشن ناموفق"
ok "مایگریشن‌ها اعمال شد"

# ── ۶) پاکسازی کش ────────────────────────────
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
ok "کش پاک شد"

# ── ۷) ری‌استارت صف (اختیاری) ───────────────
if [ "$RESTART_QUEUE" = "yes" ]; then
  log "ری‌استارت صف ..."
  php artisan queue:restart 2>/dev/null || log "queue:restart ناموفق (در صورت نبودن worker نادیده گرفته شد)"
fi

ok "دیپلوی فاز ۱ کامل شد ✅"
echo ""
echo "تأیید نهایی — بررسی کنید:"
echo "  - ثبت‌نام:  POST /register/otp  باید 200 و {sent:true} بدهد"
echo "  - ورود OTP: POST /login/otp + /login/otp/verify"
echo "  - مستندات:  PHASE1_AUTH_REVIEW.md (در ریشهٔ ریپو)"
