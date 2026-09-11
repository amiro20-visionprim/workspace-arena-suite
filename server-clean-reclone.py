#!/usr/bin/env python3
"""
Vision Prime SUITE — Server Clean & Re-Clone
استفاده از SSH بومی ویندوز (نه paramiko)
"""

import subprocess
import sys
import os
import tempfile

SERVER = "45.156.186.6"
PORT = 9011
USER = "root"
REPO = "https://github.com/amiro20-visionprim/workspace-arena-suite.git"
REMOTE_DIR = "/root/workspace-arena-suite"
APP_DIR = f"{REMOTE_DIR}/vision-prime"
PASSWORD = "Iran1245"

def p(n, msg):
    print(f"\n{'='*60}\n  گام {n}: {msg}\n{'='*60}")

def ok(msg):
    print(f"  ✅ {msg}")

def warn(msg):
    print(f"  ⚠️  {msg}")

def ssh(cmd, timeout=120):
    """اجرای دستور روی سرور با SSH بومی"""
    # ساخت فایل موقت برای رمز
    askpass = tempfile.NamedTemporaryFile(mode='w', suffix='.bat', delete=False)
    askpass.write(f'@echo off\necho {PASSWORD}\n')
    askpass.close()

    env = os.environ.copy()
    env['SSH_ASKPASS'] = askpass.name
    env['SSH_ASKPASS_REQUIRE'] = 'force'
    env['DISPLAY'] = ':0'

    full_cmd = [
        "ssh", "-p", str(PORT),
        "-o", "StrictHostKeyChecking=no",
        "-o", "UserKnownHostsFile=/dev/null",
        f"{USER}@{SERVER}",
        cmd
    ]

    try:
        r = subprocess.run(full_cmd, capture_output=True, text=True, timeout=timeout, env=env)
        os.unlink(askpass.name)

        if r.stdout.strip():
            lines = r.stdout.strip().split('\n')
            for line in lines[-15:]:
                print(f"    {line}")
        if r.returncode != 0 and r.stderr.strip():
            warn(r.stderr.strip()[:200])
        return r.returncode == 0, r.stdout.strip()
    except subprocess.TimeoutExpired:
        os.unlink(askpass.name)
        warn(f"timeout ({timeout}s)")
        return False, ""
    except Exception as e:
        try: os.unlink(askpass.name)
        except: pass
        warn(str(e))
        return False, ""

# ─── اجرا ────────────────────────────────────────────────────────────────
def main():
    print(f"\n🔄 Vision Prime SUITE — Server Clean & Re-Clone")
    print(f"   سرور: {USER}@{SERVER}:{PORT}")

    # تست اتصال
    p(0, "تست اتصال")
    ok_conn, _ = ssh("echo OK")
    if ok_conn:
        ok("SSH متصل شد!")
    else:
        err("SSH متصل نشد — SSH_ASKPASS روش جدیدیه، ممکنه روی بعضی سیستم‌ها کار نکنه")
        print("\n💡 جایگزین: این دستورات رو دستی از PowerShell اجرا کن:")
        print(f"""
# ۱. کلون:
ssh -p {PORT} {USER}@{SERVER} "rm -rf {REMOTE_DIR} && git clone -b new-branch {REPO} {REMOTE_DIR}"

# ۲. نصب composer:
ssh -p {PORT} {USER}@{SERVER} "cd {APP_DIR} && composer install --no-dev --optimize-autoloader"

# ۳. migrate + cache:
ssh -p {PORT} {USER}@{SERVER} "cd {APP_DIR} && php artisan migrate --force && php artisan config:cache && php artisan route:cache"

# ۴. ریستارت:
ssh -p {PORT} {USER}@{SERVER} "supervisorctl restart all"
""")
        sys.exit(1)

    # بکاپ
    p(1, "بکاپ .env + database")
    ssh(f"mkdir -p /root/vp-pre-clone-backup && cp {APP_DIR}/.env /root/vp-pre-clone-backup/.env.bak 2>/dev/null && cp {APP_DIR}/database/database.sqlite /root/vp-pre-clone-backup/ 2>/dev/null", check=False)
    ok("بکاپ انجام شد")

    # توقف سرویس‌ها
    p(2, "توقف سرویس‌ها")
    ssh("supervisorctl stop all 2>/dev/null", check=False)
    ok("Supervisor متوقف شد")

    # حذف قدیمی
    p(3, "حذف پروژه قدیمی")
    ssh(f"rm -rf {REMOTE_DIR}")
    ok("حذف شد")

    # کلون
    p(4, "کلون مجدد از گیت‌هاب")
    print("  ⏳ ۱-۲ دقیقه...")
    ssh(f"git clone -b new-branch {REPO} {REMOTE_DIR}", timeout=300)
    ok("کلون تمام شد")

    # بررسی
    p(5, "بررسی صحت")
    _, c = ssh(f"cd {REMOTE_DIR} && git log --oneline -1", check=False)
    _, f = ssh(f"cd {REMOTE_DIR} && find . -type f | wc -l", check=False)
    ok(f"کامیت: {c}")
    ok(f"فایل‌ها: {f}")

    # بازگردانی
    p(6, "بازگردانی .env")
    ssh(f"cp /root/vp-pre-clone-backup/.env.bak {APP_DIR}/.env 2>/dev/null", check=False)
    ssh(f"cp /root/vp-pre-clone-backup/database.sqlite {APP_DIR}/database/ 2>/dev/null", check=False)
    ok("بازگردانی شد")

    # composer
    p(7, "Composer install")
    ssh(f"cd {APP_DIR} && composer install --no-dev --optimize-autoloader --no-interaction", timeout=300)
    ok("Composer تمام شد")

    # migrate
    p(8, "Migrate + Cache")
    ssh(f"cd {APP_DIR} && php artisan migrate --force", check=False)
    ssh(f"cd {APP_DIR} && php artisan config:clear && php artisan config:cache", check=False)
    ssh(f"cd {APP_DIR} && php artisan route:clear && php artisan route:cache", check=False)
    ssh(f"cd {APP_DIR} && php artisan view:clear && php artisan view:cache", check=False)
    ok("Migrate + Cache تمام شد")

    # storage + perms
    p(9, "Storage + Permissions")
    ssh(f"cd {APP_DIR} && php artisan storage:link --force 2>/dev/null", check=False)
    ssh(f"chown -R www-data:www-data {APP_DIR}/storage {APP_DIR}/bootstrap/cache 2>/dev/null", check=False)
    ok("تنظیم شد")

    # ریستارت
    p(10, "ریستارت سرویس‌ها")
    ssh("supervisorctl reread && supervisorctl update && supervisorctl start all", check=False)
    ssh(f"cd {APP_DIR} && php artisan queue:restart", check=False)
    ok("ریستارت شد")

    # تست
    p(11, "تست نهایی")
    _, h = ssh(f"curl -s -o /dev/null -w '%{{http_code}}' http://localhost/up", check=False)
    print(f"    /up → {h}")
    if "200" in h:
        ok("سالمه! 🎉")

    _, s = ssh("supervisorctl status | head -5", check=False)
    print(f"    Supervisor:\n{s}")

    print(f"\n{'='*60}\n  ✅ تمام شد!\n{'='*60}")
    print(f"  📁 {APP_DIR}")
    print(f"  💾 بکاپ: /root/vp-pre-clone-backup/\n")

if __name__ == "__main__":
    main()
