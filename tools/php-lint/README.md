# 🧪 PHP Lint (بدون PHP بومی)

ابزار سینتکس‌چکِ فایل‌های PHP بر بستر `@php-wasm/node` — برای محیط‌هایی که PHP نصب نیست.

## راه‌اندازی
```bash
cd tools/php-lint
npm install
```

## استفاده
```bash
# یک فایل
node lint.mjs ../../vision-prime/app/Http/Controllers/Auth/OtpLoginController.php

# چند فایل
node lint.mjs ../../vision-prime/app/Models/User.php ../../vision-prime/app/Domains/Identity/Services/OtpService.php
```

## تفسیر خروجی
- `✅ file → OK` — سینتکس سالماست (حتی اگر `runtime-note: Error` باشد؛ یعنی فقط کلاسِ والد در WASM بار نشده، که برای lint طبیعی است).
- `❌ file → SYNTAX_ERROR: ...` — خطای نحوی واقعی که باید رفع شود.

## چرا این ابزار؟
در محیط توسعه‌ی فعلی (sandbox) فقط Node/npm در دسترس است و PHP/Composer قابل نصب نیستند. این ابزار معادل `php -l` را بدون PHP بومی فراهم می‌کند تا هر تغییرِ فازهای بازسازی، بلافاصله از نظر نحوی تأیید شود.
