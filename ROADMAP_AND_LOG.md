# 🗺️ نقشهٔ فازها + لاگ اجرا — Vision Prime Suite
> **فایل مستقل و زنده** — جدای از اسناد `vision-prime-docs/` که در هر فاز به‌روز می‌شوند.
> هدف: هر لحظه بتوانی ریز به ریز ببینی «چه کاری، با چه هدفی، با چه تغییری، و چه نتیجه‌ای» انجام شده است.
> **قانون ما:** خلاصه‌نویسی ممنوع؛ هر فاز یک‌بار عمیق نقشه‌برداری می‌شود، سپس واردش می‌شویم و تسک‌های اتمی‌اش را اجرا می‌کنیم.

---

# بخش ۱ — نقشهٔ فازها (Phase Map)

## مبنای نقشه (واقعیتِ کد، نه داکیومنت)
| لایه | حجم واقعی | توضیح |
|---|---|---|
| بک‌اند (PHP) | ۲۵۷ فایل / ~۲۴هزار خط | Modular Monolith با ۱۳ دامنه |
| فرانت‌اند (Vue) | ۱۵۵ فایل / ~۲۲هزار خط | Inertia + Vue 3 + Tailwind، RTL-first |
| دیتابیس | ۵۳ مایگریشن | SQLite فعلی (باید PostgreSQL شود) |
| تست | ۸۹ تست | PHPUnit Feature |
| دامنه‌ها | Ai, Audit, Automation, Connector, Content, Gsc, Identity, Marketing, Organization, Platform, Reporting, Seo, Workspace | |

### ترتیبِ فازها (زنجیرهٔ وابستگی)
فازها طوری چیده شده‌اند که هر کدام، خروجیِ فاز قبلی را مصرف می‌کند و **زنجیرهٔ ارزش** (`Connect → Collect → Analyze → Recommend → Execute → Measure → Report`) را کامل می‌کند.

---

## فاز ۱ — Identity & Auth (هویت و احراز هویت) ✅ انجام شد
**هدف:** باز کردن «درِ ورودی» محصول — ثبت‌نام، ورود (رمز/OTP)، بازیابی رمز، امنیت نشست.
**حوزهٔ کد:** `app/Domains/Identity`, `app/Domains/Audit`, `app/Http/Controllers/Auth`, `app/Models/User`, میدلورهای `Ensure*`.
**نتیجه:** ۸ فایل فیکس + ۲ تست جدید + ۱ مایگریشن. جزئیات در بخش ۲ (لاگ).

---

## فاز ۲ — Organization & Membership (سازمان و عضویت/نقش‌ها)
**هدف:** مدل چندسازمانی + RBAC؛ پایهٔ دسترسیِ همهٔ بخش‌های بعدی.
**حوزهٔ کد:** `app/Domains/Organization`, `app/Domains/Identity/Models/Role`, `EnsureCurrentOrganization`.
**تسک‌های اتمی (اولیه):**
- O2-01: بازبینی مدل‌های Organization/Membership/Role و اعتبارسنجیِ قواعد RBAC.
- O2-02: آنبوردینگ (`OrganizationOnboardingController`) — ساخت سازمان + اولین super-admin.
- O2-03: تعیین «سازمانِ پیش‌فرض» قطعی (primary) به‌جای `orderBy('id')->first()`.
- O2-04: مدیریت اعضا (invite/role/status) + audit.
- O2-05: سوئیچ سازمان (CurrentOrganization) + ایزوله‌سازی داده بین سازمان‌ها.
- O2-06: ماتریس مجوز (Permission keys) — هم‌راستا با `06-RBAC-Permission-Matrix.md`.
- O2-07: تست‌های مرجع دسترسی (WorkspaceAuthorizationTest و غیره).

---

## فاز ۳ — Workspace: Site CRUD (سایت‌ها)
**هدف:** شیء مرکزیِ محصول — «سایت» (سایت وردپرسیِ مشتری). همهٔ تحلیل‌ها روی آن سوار می‌شود.
**حوزهٔ کد:** `app/Domains/Workspace/Models/Site`, `SiteController`, `resources/js/Pages/App/Sites`.
**تسک‌های اتمی (اولیه):**
- S3-01: CRUD کامل سایت + فیلدهای ضروری (دامنه، وردپرس URL، نوع).
- S3-02: نمایش/ویرایش + حالت‌های empty/loading/error.
- S3-03: URL Profile های مرتبط.
- S3-04: یکپارچگی با سازمان (scoping) + تست.

---

## فاز ۴ — Workspace: Project & Client (پروژه و مشتری)
**هدف:** گروه‌بندی سایت‌ها زیر پروژه/مشتری؛ ساختار گزارش‌دهی و پرتال.
**حوزهٔ کد:** `ProjectController`, `ClientController`, `ClientUserAssignment`.
**تسک‌های اتمی (اولیه):**
- P4-01: CRUD پروژه + تخصیص سایت‌ها به پروژه.
- C4-02: CRUD مشتری + تخصیص کاربر (agency → client).
- C4-03: نقش‌های پرتال مشتری (viewer/approver) + scope visibility.
- C4-04: تست‌های تخصیص و ایزوله‌سازی.

---

## فاز ۵ — WordPress Connector (اتصال وردپرس)
**هدف:** لایهٔ «Execute» — اتصال امن به وردپرس مشتری.
**حوزهٔ کد:** `app/Domains/Connector`, `vision-prime-wordpress-plugin/`, `SiteConnectorController`.
**تسک‌های اتمی (اولیه):**
- C5-01: جریان pairing (توکن) + HMAC.
- C5-02: ذخیره/حذف اعتبارنامهٔ وردپرس (wp-credentials).
- C5-03: health check و status sync.
- C5-04: disconnect + ابطال توکن.
- C5-05: پلاگین وردپرس — همراستایی endpoint ها (command-result و publish).
- C5-06: تست امنیت (HMAC، replay، rollback).

---

## فاز ۶ — GSC Integration (Google Search Console)
**هدف:** لایهٔ «Collect» — دادهٔ واقعی رتبه/ترافیک.
**حوزهٔ کد:** `app/Domains/Gsc`, `Gsc*Controller`, `resources/js/Pages/App/Gsc`.
**تسک‌های اتمی (اولیه):**
- G6-01: OAuth flow (redirect/callback) + ذخیرهٔ امن توکن.
- G6-02: مدیریت properties.
- G6-03: metrics (pages/queries) + import.
- G6-04: analyze (تبدیل داده به بینش).
- G6-05: حل دسترسی GSC از داخل ایران (پروکسی/refresh).
- G6-06: تست‌ها + حالت‌های خطای OAuth.

---

## فاز ۷ — SEO Intelligence (هوش SEO)
**هدف:** لایهٔ «Analyze/Recommend» — قلبِ ارزش محصول.
**حوزهٔ کد:** `app/Domains/Seo`, `OpportunityController`, `MoneyPageController`, `ConversionRiskController`, `RecommendationController`, `UrlProfileController`.
**تسک‌های اتمی (اولیه):**
- SEO7-01: Opportunities (کشف + اولویت‌بندی فرصت‌ها).
- SEO7-02: Money Pages (تشخیص + audit + issues).
- SEO7-03: Conversion Risks (تشخیص + شدت).
- SEO7-04: URL Profiles (نمایهٔ هر URL).
- SEO7-05: Recommendations (توصیهٔ قابل اجرا + اتصال به command).
- SEO7-06: اطمینان‌سنجی (منبع داده، confidence، آخرین sync) — اصل #4.
- SEO7-07: تست‌ها.

---

## فاز ۸ — AI Gateway & Content Generation (تولید محتوا)
**هدف:** لایهٔ copilot — تولید پیش‌نویس مقاله/محصول با استاندارد SEO.
**حوزهٔ کد:** `app/Domains/Ai`, `app/Domains/Content`, `AiDraftController`, `ContentApiController`, `resources/js/Pages/App/{ArticleDraft,ProductDraft}`.
**تسک‌های اتمی (اولیه):**
- A8-01: AI Gateway (providerها، failover، سوپرادمین-محور).
- A8-02: فرم کامل مقاله (فیلدهای SEO، امتیاز، اسکیما، لینک داخلی).
- A8-03: فرم کامل محصول (WooCommerce، قیمت، موجودی، اسکیما).
- A8-04: Content Quality Guard (امتیاز ۰–۱۰۰ + چک‌لیست).
- A8-05: guardrails + prompt templates.
- A8-06: واقعی‌سازی AI (ارتقای کیفیت از RuleBased 50-65 به 85+).
- A8-07: تست‌ها.

---

## فاز ۹ — Automation & Commands (اتوماسیون و فرمان‌ها)
**هدف:** لایهٔ «Execute» کنترل‌شده — فرمان‌های امن با سیاست و rollback.
**حوزهٔ کد:** `app/Domains/Automation`, `Command*Controller`, `AutomationPolicyController`, `resources/js/Pages/App/Commands`.
**تسک‌های اتمی (اولیه):**
- A9-01: سیاست‌های اتوماسیون (policy، trust، routes).
- A9-02: dispatch + decision (تأیید/رد).
- A9-03: اجرای command روی وردپرس (اتصال به فاز ۵).
- A9-04: emergency stop + resume.
- A9-05: rollback بدون اتلاف.
- A9-06: گیت‌های D-017 (scope/گرمایش/کیفیت).
- A9-07: تست‌ها.

---

## فاز ۱۰ — Reporting & Client Portal (گزارش و پرتال مشتری)
**هدف:** لایهٔ «Measure/Report» — برگرداندن نتیجه به مشتری.
**حوزهٔ کد:** `app/Domains/Reporting`, `ReportController`, `ReportPublishController`, `resources/js/Pages/Client`.
**تسک‌های اتمی (اولیه):**
- R10-01: تولید گزارش (store) + publish.
- R10-02: گزارش تأثیر (قبل/بعد GSC).
- R10-03: پرتال مشتری (dashboard، growth، site-health، opportunities، decisions، activity).
- R10-04: تصمیم‌گیری مشتری (command/question/review).
- R10-05: UX پرتال (نتیجه‌محور، بدون دادهٔ خام) — اصل #7.
- R10-06: تست‌ها.

---

## فاز ۱۱ — Marketing & Lead Funnel (مارکتینگ و قیف لید)
**هدف:** لایهٔ Go-to-market — لندینگ، لید، دستیار.
**حوزهٔ کد:** `app/Domains/Marketing`, `MarketingLeadController`, `AssistantController`, `resources/js/Pages/Marketing`.
**تسک‌های اتمی (اولیه):**
- M11-01: لندینگ واحد آژانس + Lead Magnet (گزارش رایگان).
- M11-02: قیف لید (store/status/notes + امتیازدهی).
- M11-03: Assistant (دانش + چت + تماس).
- M11-04: CRO (CTA، دموی کوتاه).
- M11-05: تست‌ها.

---

## فاز ۱۲ — Platform Command Center (اتاق فرماندهی پلتفرم)
**هدف:** لایهٔ سوپرادمین — مدیریت سازمان‌ها، صورتحساب، پرداخت، MFA، پیامک.
**حوزهٔ کد:** `app/Domains/Platform`, `Platform*Controller`, `resources/js/Pages/Platform`.
**تسک‌های اتمی (اولیه):**
- PL12-01: داشبورد + events/resolve.
- PL12-02: سازمان‌ها (suspend/activate/impersonate).
- PL12-03: صورتحساب (plans، subscriptions، payments، invoices).
- PL12-04: درگاه پرداخت (zarinpal/aqayepardakht) + callback.
- PL12-05: MFA سوپرادمین (challenge/verify/setup).
- PL12-06: SMS (ارسال + لاگ).
- PL12-07: emergency stop پلتفرمی.
- PL12-08: تست‌ها.

---

## فاز ۱۳ — Frontend/Design System & UX (دیزاین و جورنی)
**هدف:** استاندارد UX بین‌المللی — RTL، دسترسی‌پذیری، stateها، توکن‌ها.
**حوزهٔ کد:** `resources/js/shared/ui` (۳۰ کامپوننت)، `resources/js/app/layouts`, `resources/js/directives`, دیزاین توکن‌ها.
**تسک‌های اتمی (اولیه):**
- D13-01: ممیزی RTL و mixed-direction (URL/کد LTR در متن RTL).
- D13-02: دسترسی‌پذیری (keyboard، aria، focus).
- D13-03: stateهای empty/loading/error/success در همهٔ صفحات.
- D13-04: ریسپانسیو موبایل.
- D13-05: توکن‌ها و کامپوننت‌کانترکت (`16-Tailwind-Design-Tokens`).
- D13-06: جورنی‌های پولساز (دموی ۵ دقیقه‌ای، onboarding روان).

---

## فاز ۱۴ — Infrastructure & Production Hardening (زیرساخت پروداکشن)
**هدف:** استاندارد پروداکشن بین‌المللی — امنیت، مقیاس، پایداری.
**حوزه:** Docker، Nginx، DB، صف، ایمیل، مانیتورینگ.
**تسک‌های اتمی (اولیه):**
- I14-01: SSL/HTTPS (Let's Encrypt).
- I14-02: مهاجرت SQLite → PostgreSQL + بکاپ روزانه.
- I14-03: SMTP واقعی + نوتیفیکیشن.
- I14-04: Redis + Horizon + Supervisor.
- I14-05: rate limiting + مانیتورینگ + لاگ.
- I14-06: هاردنینگ Docker/Nginx + عدم نشت secret.
- I14-07: بکاپ/restore و disaster recovery.

---

## اولویت‌بندی (نگاه تجاری مشترک — مصوب میز گرد)
| سطح | فاز | چرا |
|---|---|---|
| 🔴 مسیر درآمد | ۱→۲→۳→۴→۵→۶→۷→۱۰ | زنجیرهٔ Connect→Collect→Analyze→Report = چیز قابل فروش |
| 🟡 تقویت | ۸→۹ | AI و اتوماسیون = لایهٔ Done-for-you و مقیاس |
| 🟢 تکمیل | ۱۱→۱۲→۱۳→۱۴ | گوتومارکت، فرماندهی، پولیش، پایداری |

> هر فاز قبل از ورود، در بخش ۲ «گسترش‌یافته» و تسک‌های اتمی‌اش با جزئیات کامل (ورودی/خروجی/معیار پذیرش/تست/داکیومنت) ثبت می‌شود.

---

# بخش ۲ — لاگ اجرا (Execution Log)

> قالب هر رکورد: **فاز / تسک / هدف / تغییر / فایل‌ها / تست / وضعیت**.

---

## فاز ۱ — Identity & Auth — ✅ تکمیل شد

### تسک 1.1 — بازسازی ثبت‌نام OTP
- **هدف:** باز کردن درِ ورود مشتری (ثبت‌نام end-to-end که در کد استاب بود).
- **تغییر:** `OtpRegisterController@request` از `501` به اتصال واقعی به `OtpService` + اعتبارسنجی phone + رد شمارهٔ تکراری (422) + rate-limit هر شماره (SMS bombing).
- **فایل:** `app/Http/Controllers/Auth/OtpRegisterController.php`
- **تست:** پوشش‌یافته در `RegisterTest` و `OtpAuthTest` (قرارداد `{sent, code}`).
- **وضعیت:** ✅ lint سبز

### تسک 1.2 — بازسازی ورود OTP
- **هدف:** فعال‌کردن ورود بدون رمز (تبلیغ‌شده ولی استاب بود).
- **تغییر:** `OtpLoginController@request` (ارسال OTP) و `@verify` (احراز + `Auth::login` + `session()->regenerate()` + audit `auth.login_otp_succeeded`).
- **فایل:** `app/Http/Controllers/Auth/OtpLoginController.php`
- **تست:** `OtpAuthTest` + assertion جدید audit.
- **وضعیت:** ✅ lint سبز

### تسک 1.3 — رفع نشت کد OTP
- **هدف:** جلوگیری از برگشتن کد OTP در پاسخ JSON در پروداکشن.
- **تغییر:** `OtpService@request` کد را فقط در محیط غیر-پروداکشن و sandbox برمی‌گرداند؛ `KavenegarSms` در پروداکشن بدون کلید `success=false` می‌دهد.
- **فایل:** `app/Domains/Identity/Services/OtpService.php`, `app/Domains/Platform/Sms/KavenegarSms.php`
- **وضعیت:** ✅ lint سبز

### تسک 1.4 — لاگ ورود ناموفق
- **هدف:** تحقق اصل #8 (همهٔ عملیات حساس audit دارند) + کشف brute-force.
- **تغییر:** ثبت `auth.login_failed` در `AuthenticatedSessionController@store`.
- **فایل:** `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- **تست:** `test_failed_login_is_audited` (جدید).
- **وضعیت:** ✅

### تسک 1.5 — پیام صادقانهٔ بازیابی رمز
- **هدف:** حذف «موفقیت کاذب» وقتی mailer=log.
- **تغییر:** در پروداکشن با `MAIL_MAILER=log` خطای صادقانه می‌دهد (تست‌ها دست‌نخورده).
- **فایل:** `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- **وضعیت:** ✅

### تسک 1.6 — بهینه‌سازی isSuperAdmin
- **هدف:** حذف کوئری سنگین روی هر روت پلتفرم.
- **تغییر:** `get()+contains` → یک `exists()` با `whereHas('role')`.
- **فایل:** `app/Models/User.php`
- **وضعیت:** ✅

### تسک 1.7 — حذف ستون‌های مردهٔ two_factor_*
- **هدف:** پاک‌سازی اسکیمای موازیِ MFA.
- **تغییر:** مایگریشن drop سه ستون.
- **فایل:** `database/migrations/2026_08_25_000001_drop_legacy_two_factor_columns_from_users_table.php`
- **وضعیت:** ✅ lint سبز

### تسک 1.8 — مستندسازی
- **هدف:** همگام‌سازی داکیومنت با واقعیت.
- **تغییر:** `PHASE1_AUTH_REVIEW.md` (نقد + فیکس‌ها)، `31-Progress-Log.md`، `FIELD_TEST_REPORT.md` (یادداشت اصلاحی).
- **وضعیت:** ✅

### تسک 1.9 — ابزار lint
- **هدف:** امکان سینتکس‌چک PHP بدون PHP بومی.
- **تغییر:** `tools/php-lint/` (lint.mjs + package.json + README) بر بستر `@php-wasm/node`.
- **وضعیت:** ✅

### تسک 1.10 — دیپلوی امن
- **هدف:** استقرار فاز ۱ روی سرور بدون ریسک.
- **تغییر:** `deploy-phase1.sh` (بکاپ → pull → test → migrate → کش).
- **وضعیت:** ✅ push شد — منتظر اجرا/تأیید روی سرور.

---

## وضعیت کنونی
- **فاز بعدی:** فاز ۲ — Organization & Membership.
- **اقدام بعدی:** قبل از ورود به فاز ۲، تسک‌های اتمی O2-* را در همین فایل «گسترش‌یافته» (ورودی/خروجی/معیار پذیرش) ثبت می‌کنیم، سپس اجرا.
