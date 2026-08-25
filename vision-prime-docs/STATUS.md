# 📌 وضعیت واقعی محصول — Vision Prime SUITE

> **این سند تنها منبع حقیقت برای «چه چیزی واقعاً کار می‌کند» است.**
> هر سند طراحی (۰۰ تا ۴۸) فقط «قصد طراحی» را توصیف می‌کند؛ در صورت تعارض، همین سند ملاک است.
> آخرین به‌روزرسانی: ۲۰۲۶-۰۸-۲۵ — پس از اجرای کامل P0 و بخشی از P1

---

## ✅ کار می‌کند (تست‌شده با تست خودکار)

| قابلیت | پوشش تست | یادداشت |
|---|---|---|
| ثبت‌نام با کد یکبارمصرف پیامکی | `OtpAuthTest`, `RegisterTest`, `MoneyPathTest` | در sandbox بدون کلید کاوه‌نگار کد در پاسخ برمی‌گردد؛ با کلید واقعی فقط پیامک |
| ورود با رمز / خروج | `AuthenticationTest` | خروج → صفحهٔ اصلی |
| ساخت سازمان (onboarding) + نقش agency-admin | `OrganizationOnboardingTest` | بنیان‌گذار = agency-admin |
| CRUD مشتری / پروژه / سایت | `SiteCrudTest` و همتایان | با Audit Log |
| اتصال پلاگین وردپرس (Pairing + امضای HMAC) | `MoneyPathTest`, `Connector/*` | nonce + replay protection + رمزنگاری secret |
| پیکربندی سرویس AI (RBAC) | `AiSettingsAndDraftTest` | مجوز `ai.provider.manage.organization` |
| تولید مقاله/محصول با AI + fallback قانونی | `ArticleDraftGenerationTest`, `ArticleDraftPageTest` | زنجیرهٔ failover: provider سازمان → OpenRouter رایگان → RuleBased |
| گیت‌های کیفیت محتوا (طول/ساختار/FAQ/تکراری) | `AutoPublishGuardrailsTest` | گیت تکراری در ۲۰۲۶-۰۸-۲۵ اصلاح شد |
| تأیید انسانی → انتشار خودکار وردپرس | `ArticlePublishPipelineTest`, **`MoneyPathTest`** | مسیر کامل: امتیاز اطمینان + گرمایش ۵ اجرا + سیاست L3 |
| تقویم محتوایی (زمان‌بندی انتشار) | `ContentCalendarTest` | |
| GSC: OAuth واقعی گوگل + ایمپورت متریک‌ها | `Gsc/*` | توکن رمزنگاری‌شده |
| پلتفرم سوپرادمین (MFA، سازمان‌ها، اضطراری) | `Platform/*` | |
| پرتال مشتری | `Client/*` | |
| **دروازه‌های کیفیت:** PHPUnit ۳۶۹/۳۶۹ · Pint · ESLint · vue-tsc · Prettier · Build | CI `quality.yml` | همه سبز از ۲۰۲۶-۰۸-۲۵ |

## ⚠️ کار می‌کند ولی وابسته به زیرساخت است (در سرور فعلی خاموش)

| قابلیت | شرط فعال‌شدن |
|---|---|
| همهٔ زمان‌بندی‌ها و Jobها (gsc:import، LearningLoop، Dunning، Reportها و…) | اجرای `php artisan schedule:run` با cron + Worker/Horizon با Supervisor — **فعلاً روی سرور تنظیم نشده** |
| ارسال واقعی پیامک | کلید کاوه‌نگار در `services.kavenegar.api_key` |
| انتشار خودکار واقعی | اتصال پلاگین نصب‌شده روی وردپرس مشتری + سیاست اتوماسیون L3 سایت |

## ❌ ناقص / بدهی فنی شناخته‌شده

| مورد | وضعیت | برنامه |
|---|---|---|
| دیتابیس پروداکشن SQLite است | ریسک یکپارچگی/همزمانی | مهاجرت به PostgreSQL (P1) |
| SSL/HTTPS روی دامنه فعال نیست | رمز عبور روی HTTP! | certbot با چالش DNS-01 (P0 دستی) |
| `APP_KEY` لو رفته در تاریخچهٔ گیت | `.env.local-backup` کامیت شده بود | rotate + `git filter-repo` (P0 دستی) |
| جدول‌های موازی قالب پرامپت (`ai_prompt_templates` و `prompt_template`) | دو نسل هم‌زمان | ادغام (P2) |
| مایگریشن خالی `2026_08_21_162628` | پانسمان تاریخی؛ بی‌ضرر | حذف پس از تطبیق با دیتابیس پروداکشن |
| پرداخت (زرین‌پال/عقربه) و اشتراک | اسکلت + تست محدود | پشت feature-flag تا تکمیل تست E2E (P2) |
| پلاگین dist اوبفاسکیت‌شده | غیرقابل دیباگ برای مشتری | بیلد خوانا از CI (P2) |

## 🔍 چطور وضعیت را خودتان راستی‌آزمایی کنید

```bash
composer install && npm ci && npm run build
php artisan test          # باید 369/369 سبز باشد
vendor/bin/pint --test    # PASS
npm run lint && npm run typecheck   # هر دو بدون خطا
```

هر تغییری که یکی از این‌ها را قرمز کند، «مسیر پول» یا کیفیت را شکسته — تا سبز نشود merge نشود.
تست `tests/Feature/E2E/MoneyPathTest.php` کل زنجیرهٔ ارزش (ثبت‌نام تا انتشار وردپرس) را در یک تست محافظت می‌کند.
