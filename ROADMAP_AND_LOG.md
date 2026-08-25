# 🗺️ نقشهٔ فازها + لاگ اجرا — Vision Prime Suite
> **فایل مستقل و زنده** — جدای از اسناد `vision-prime-docs/` که در هر فاز به‌روز می‌شوند.
> هدف: هر لحظه بتوانی ریز به ریز ببینی «چه کاری، با چه هدفی، با چه تغییری، و چه نتیجه‌ای» انجام شده است.
> **قانون ما:** خلاصه‌نویسی ممنوع. هر فاز یک‌بار «فول» (نقشه‌برداری کامل تسک‌های اتمی) می‌شود، سپس واردش می‌شویم و تسک‌به‌تسک اجرا می‌کنیم.

---

# بخش ۱ — نقشهٔ کامل فازها (Full Phase Map)

## مبنای نقشه (واقعیتِ کد، نه داکیومنت)
| لایه | حجم واقعی | توضیح |
|---|---|---|
| بک‌اند (PHP) | ۲۵۷ فایل / ~۲۴هزار خط | Modular Monolith با ۱۳ دامنه |
| فرانت‌اند (Vue) | ۱۵۵ فایل / ~۲۲هزار خط | Inertia + Vue 3 + Tailwind، RTL-first |
| دیتابیس | ۵۳ مایگریشن | SQLite فعلی (باید PostgreSQL شود) |
| تست | ۸۹ تست | PHPUnit Feature |
| دامنه‌ها | Ai, Audit, Automation, Connector, Content, Gsc, Identity, Marketing, Organization, Platform, Reporting, Seo, Workspace | |

### کلیدِ قالب هر تسک اتمی
هر تسک با این ۷ فیلد کامل تعریف می‌شود (هیچ تسکی بدون این‌ها وارد اجرا نمی‌شود):

| فیلد | معنا |
|---|---|
| **هدف** | چرا این تسک؟ چه دردی را حل می‌کند؟ |
| **ورودی** | وابستگی‌ها و فایل‌های پیش‌نیاز |
| **تغییر/خروجی** | دقیقاً چه چیزی ساخته/اصلاح می‌شود |
| **معیار پذیرش (AC)** | شرایط «Done» — قابل آزمون |
| **تست** | کدام تست، چه assertion |
| **داکیومنت** | کدام سند مرجع آپدیت می‌شود |
| **شدت** | 🔴 مسیر درآمد / 🟠 حیاتی / 🟡 مهم / 🟢 پولیش |

### مالکان (تیم میز گرد)
| نام | حوزه |
|---|---|
| آرش | پروداکت/بک‌لاگ |
| سارا | بک‌اند/معماری |
| نیما | فرانت‌اند |
| لیلا | UX/جورنی |
| امیرحسین | SEO |
| مریم | بیزینس/مونیتایز |
| پویا | فروش |
| حامد | گروث/CRO |
| رضا | DevOps/امنیت |
| هومن | برند/پوزیشنینگ |

---

# بخش ۲ — تسک‌های اتمی کامل (به تفکیک فاز)

---

## فاز ۱ — Identity & Auth ✅ (انجام شد — لاگ در بخش ۳)

---

## فاز ۲ — Organization & Membership (سازمان و عضویت)

### O2-01 — ممیزی مدل‌ها و قواعد RBAC
- **هدف:** اطمینان از اینکه پایهٔ دسترسی، درست و مقیاس‌پذیر است (همهٔ فازهای بعد به آن تکیه می‌کنند).
- **ورودی:** `Organization`, `Membership`, `Role`, `Permission` + سند `06-RBAC-Permission-Matrix.md`.
- **تغییر:** بازبینی روابط، فیلدهای fillable، cast ها، و یکتا بودن (unique) role key.
- **AC:** هر role یک `key` یکتا و `is_system` دارد؛ membership از `status` (active/invited/suspended) پشتیبانی می‌کند.
- **تست:** مدل‌ها + سیدر نقش‌ها.
- **داکیومنت:** `06`, `09`.
- **شدت:** 🟠 — **مالک:** سارا

### O2-02 — آنبوردینگ (ساخت سازمان + اولین super-admin)
- **هدف:** کاربرِ تازه‌ثبت‌نام‌شده، سازمانش را بسازد و super-admin شود.
- **ورودی:** `OrganizationOnboardingController`, `RegisterController`.
- **تغییر:** جریان کامل: ساخت org → ساخت membership با role=super-admin → audit → هدایت به داشبورد.
- **AC:** بعد از ثبت‌نام، یک org + یک membership فعال super-admin ساخته شده و کاربر وارد داشبورد می‌شود.
- **تست:** E2E ثبت‌نام → آنبوردینگ → داشبورد.
- **داکیومنت:** `14`, `17`.
- **شدت:** 🔴 — **مالک:** سارا/آرش

### O2-03 — تعیین «سازمانِ پیش‌فرض» قطعی
- **هدف:** حذف رفتار غیرقطعی `orderBy('id')->first()`.
- **ورودی:** `EnsureCurrentOrganization`.
- **تغییر:** افزودن ستون/فیلد `is_default` یا ترجیح primary در membership؛ انتخاب قطعی و قابل‌پیش‌بینی.
- **AC:** کاربر چندسازمانی همیشه به سازمانِ درست هدایت می‌شود؛ سوئیچ دستی حفظ می‌شود.
- **تست:** کاربر با دو سازمان.
- **داکیومنت:** `09`, `18`.
- **شدت:** 🟠 — **مالک:** سارا

### O2-04 — مدیریت اعضا (دعوت/نقش/وضعیت)
- **هدف:** آژانس بتواند اعضای تیمش را مدیریت کند.
- **ورودی:** `OrganizationSettingsController` (store/update/destroy membership).
- **تغییر:** دعوت (invite)، تغییر نقش، تعلیق/فعال‌سازی، حذف + audit کامل.
- **AC:** هر عملیات عضو، audit دارد و نقش‌ها از RBAC پیروی می‌کنند؛ super-admin آخرین را نمی‌توان حذف کرد.
- **تست:** RBAC (non-admin نمی‌تواند عضو مدیریت کند).
- **داکیومنت:** `06`, `10`.
- **شدت:** 🔴 — **مالک:** سارا

### O2-05 — سوئیچ سازمان + ایزوله‌سازی داده
- **هدف:** کاربر چندسازمانی بدون نشت داده بین سازمان‌ها.
- **ورودی:** `CurrentOrganization`, `CurrentOrganizationController`.
- **تغییر:** سوئیچ امن + همهٔ کوئری‌ها با scope سازمان (tenant isolation).
- **AC:** هیچ داده‌ای از سازمان دیگر در هیچ endpoint ای دیده نمی‌شود.
- **تست:** تست ایزوله‌سازی (سازمان A نمی‌بیند دادهٔ B).
- **داکیومنت:** `05`, `09`.
- **شدت:** 🔴 — **مالک:** سارا

### O2-06 — ماتریس مجوز (Permission keys)
- **هدف:** هم‌راستایی کامل permission keys با قرارداد `10-Permission-Keys`.
- **تغییر:** سیدر نقش/مجوز + گیت‌ها (Gates/Policies) روی منابع.
- **AC:** هر permission key یک گیت واقعی دارد؛ deny به‌درستی 403 می‌دهد.
- **تست:** تست مجوز (ماتریس).
- **داکیومنت:** `10`, `06`.
- **شدت:** 🟠 — **مالک:** سارا

### O2-07 — تست‌های مرجع دسترسی
- **هدف:** پوشش کامل authorization (مثبت و منفی).
- **تغییر:** تکمیل `WorkspaceAuthorizationTest` + تست‌های جدید.
- **AC:** مسیر موفق و خطا برای هر نقش پوشش داده می‌شود.
- **تست:** Feature tests.
- **داکیومنت:** `06`.
- **شدت:** 🟠 — **مالک:** سارا

---

## فاز ۳ — Workspace: Site CRUD (سایت‌ها)

### S3-01 — مدل Site و فیلدهای ضروری
- **هدف:** شیء مرکزیِ محصول (سایت وردپرسی مشتری).
- **ورودی:** `app/Domains/Workspace/Models/Site`.
- **تغییر:** فیلدهای domain, wordpress_url, type, status + ارتباط org/client/project.
- **AC:** هر سایت به یک org تعلق دارد و فیلدهای اجباری validation دارند.
- **تست:** مدل + factory.
- **داکیومنت:** `09`, `19`.
- **شدت:** 🔴 — **مالک:** سارا

### S3-02 — CRUD کامل سایت
- **هدف:** create/read/update/delete با RBAC.
- **ورودی:** `SiteController`.
- **تغییر:** index/create/store/show/edit/update/destroy + validation + audit.
- **AC:** فقط کاربران مجاز؛ حذف با `impersonation.readonly` مسدود می‌شود.
- **تست:** CRUD feature tests.
- **داکیومنت:** `19`, `20`.
- **شدت:** 🔴 — **مالک:** سارا

### S3-03 — UI سایت‌ها (فرانت‌اند)
- **هدف:** تجربهٔ کامل مدیریت سایت.
- **ورودی:** `resources/js/Pages/App/Sites`.
- **تغییر:** لیست + فرم + نمایش + stateهای empty/loading/error.
- **AC:** RTL صحیح، موبایل، keyboard؛ فرم با خطاهای فارسی.
- **تست:** کامپوننت/بازبینی.
- **داکیومنت:** `20`.
- **شدت:** 🔴 — **مالک:** نیما/لیلا

### S3-04 — URL Profiles پایه
- **هدف:** نمایهٔ هر URL از سایت (پایهٔ تحلیل‌های SEO).
- **ورودی:** `UrlProfileController`.
- **تغییر:** index/show + اتصال به site.
- **AC:** هر URL profile به سایت و org اسکوپ می‌شود.
- **تست:** feature.
- **داکیومنت:** `23`.
- **شدت:** 🟠 — **مالک:** سارا

---

## فاز ۴ — Workspace: Project & Client

### P4-01 — CRUD پروژه + تخصیص سایت
- **هدف:** گروه‌بندی سایت‌ها زیر پروژه.
- **تغییر:** `ProjectController` کامل + رابطه سایت‌ها.
- **AC:** پروژه متعلق به org، با سایت‌های قابل انتساب.
- **تست:** feature.
- **داکیومنت:** `09`.
- **شدت:** 🔴 — **مالک:** سارا

### C4-02 — CRUD مشتری + تخصیص کاربر
- **هدف:** مدل مشتری + تخصیص agency→client.
- **تغییر:** `ClientController` + `ClientUserAssignment`.
- **AC:** تخصیص/حذف کاربر با audit.
- **تست:** feature.
- **داکیومنت:** `09`, `35`.
- **شدت:** 🔴 — **مالک:** سارا

### C4-03 — نقش‌های پرتال مشتری + scope visibility
- **هدف:** viewer/approver + دیدن فقط دادهٔ مجاز.
- **تغییر:** `ClientAccessScope`, `EnsureClientPortalAccess`.
- **AC:** client فقط مشتریِ خودش را می‌بیند؛ approver تصمیم می‌گیرد.
- **تست:** authorization.
- **داکیومنت:** `06`, `35`.
- **شدت:** 🟠 — **مالک:** سارا

---

## فاز ۵ — WordPress Connector

### C5-01 — جریان pairing (توکن + HMAC)
- **هدف:** اتصال امن سایت وردپرسی.
- **تغییر:** `PairSiteController`, `SiteConnectorTokenController`, HMAC.
- **AC:** توکن یک‌بارمصرف، امضای HMAC، انقضای کوتاه.
- **تست:** تست HMAC/بازپخش.
- **داکیومنت:** `22`, `43`.
- **شدت:** 🔴 — **مالک:** رضا/سارا

### C5-02 — ذخیره/حذف اعتبارنامهٔ وردپرس
- **هدف:** اتصال دائم با credentials امن.
- **تغییر:** `SiteConnectorController` (save/removeWpCredentials).
- **AC:** رمزنگاری/redact در لاگ؛ حذف کامل.
- **تست:** امنیت.
- **داکیومنت:** `22`.
- **شدت:** 🔴 — **مالک:** رضا

### C5-03 — health check + sync status
- **هدف:** دیده‌شدن وضعیت اتصال.
- **تغییر:** `HealthCheckController`, `SiteSyncController/Status`.
- **AC:** وضعیت زندهٔ اتصال/سینک.
- **تست:** feature.
- **داکیومنت:** `23`, `24`.
- **شدت:** 🟠 — **مالک:** سارا

### C5-04 — disconnect + ابطال توکن
- **هدف:** قطع امن اتصال.
- **تغییر:** `SiteDisconnectController`.
- **AC:** ابطال توکن و پاک‌سازی.
- **تست:** feature.
- **داکیومنت:** `22`.
- **شدت:** 🟠 — **مالک:** سارا

### C5-05 — همراستایی پلاگین (command-result/publish)
- **هدف:** endpoint های پلاگین با بک‌اند.
- **تغییر:** `vision-prime-wordpress-plugin`, `CommandResultController`.
- **AC:** publish/command-result end-to-end.
- **تست:** integration.
- **داکیومنت:** `28`, `43`.
- **شدت:** 🔴 — **مالک:** سارا/رضا

---

## فاز ۶ — GSC Integration

### G6-01 — OAuth flow + ذخیرهٔ امن توکن
- **هدف:** دریافت دادهٔ واقعی GSC.
- **تغییر:** `GscOAuthController`, refresh token امن.
- **AC:** redirect/callback، refresh، رمزنگاری توکن.
- **تست:** OAuth (mock).
- **داکیومنت:** `24`.
- **شدت:** 🔴 — **مالک:** سارا

### G6-02 — مدیریت properties
- **تغییر:** `GscPropertyController`.
- **AC:** لیست/انتخاب property.
- **تست:** feature.
- **داکیومنت:** `24`.
- **شدت:** 🟠

### G6-03 — metrics (pages/queries) + import
- **تغییر:** `GscMetricsController`, `GscImportController`.
- **AC:** دادهٔ صفحات/کوئری‌ها + import.
- **تست:** feature.
- **داکیومنت:** `24`.
- **شدت:** 🟠

### G6-04 — analyze (داده → بینش)
- **تغییر:** `GscAnalyzeController`.
- **AC:** خروجی بینش (نه دادهٔ خام).
- **تست:** feature.
- **داکیومنت:** `25`.
- **شدت:** 🟠

### G6-05 — حل دسترسی GSC از ایران
- **هدف:** دور زدن تحریم/فیلتر به‌صورت امن.
- **تغییر:** پروکسی/refresh در سمت سرور + تصمیم ثبت‌شده در Decision Log.
- **AC:** OAuth در ایران کار کند.
- **تست:** smoke.
- **داکیومنت:** `24`, `04`.
- **شدت:** 🔴 — **مالک:** رضا

---

## فاز ۷ — SEO Intelligence

### SEO7-01 — Opportunities (کشف + اولویت)
- **تغییر:** `OpportunityController`.
- **AC:** کشف فرصت با منبع داده + confidence.
- **تست:** feature.
- **داکیومنت:** `25`.
- **شدت:** 🔴

### SEO7-02 — Money Pages (تشخیص + audit)
- **تغییر:** `MoneyPageController`, دامنه Seo.
- **AC:** تشخیص صفحات پول‌ساز + issues.
- **تست:** feature.
- **داکیومنت:** `26`.
- **شدت:** 🔴

### SEO7-03 — Conversion Risks
- **تغییر:** `ConversionRiskController`.
- **AC:** ریسک با شدت.
- **تست:** feature.
- **داکیومنت:** `26`.
- **شدت:** 🟠

### SEO7-04 — URL Profiles
- **تغییر:** `UrlProfileController` کامل.
- **AC:** نمایهٔ هر URL با آخرین sync.
- **تست:** feature.
- **داکیومنت:** `23`.
- **شدت:** 🟠

### SEO7-05 — Recommendations + اتصال به command
- **تغییر:** `RecommendationController`.
- **AC:** توصیهٔ قابل اجرا + تبدیل به command.
- **تست:** feature.
- **داکیومنت:** `25`, `28`.
- **شدت:** 🔴

### SEO7-06 — اطمینان‌سنجی (اصل #4)
- **هدف:** هر insight منبع/زمان/confidence/فاکتور داشته باشد.
- **تغییر:** افزودن متادیتا به همهٔ بینش‌ها.
- **AC:** بدون منبع، بینش نمایش داده نمی‌شود.
- **تست:** unit.
- **داکیومنت:** `00`.
- **شدت:** 🟠

---

## فاز ۸ — AI & Content Generation

### A8-01 — AI Gateway (provider/failover)
- **تغییر:** `app/Domains/Ai`, `AiSettingsController`.
- **AC:** failover خودکار، کلید فقط سمت Laravel، محدود به سوپرادمین.
- **تست:** unit/integration.
- **داکیومنت:** `27`, `42`.
- **شدت:** 🔴

### A8-02 — فرم کامل مقاله (SEO)
- **تغییر:** `ArticleDraft/Create.vue`, `AiDraftController`.
- **AC:** فیلدهای Meta/کلیدواژه/اسکیما/لینک داخلی + امتیاز زنده.
- **تست:** E2E.
- **داکیومنت:** `42`, `44`.
- **شدت:** 🔴

### A8-03 — فرم کامل محصول (WooCommerce)
- **تغییر:** `ProductDraft/Create.vue`.
- **AC:** قیمت/موجودی/اسکیمای Product.
- **تست:** E2E.
- **داکیومنت:** `42`.
- **شدت:** 🟠

### A8-04 — Content Quality Guard
- **تغییر:** `ContentGuardrailController`, ScoreCard.
- **AC:** امتیاز ۰–۱۰۰ + چک‌لیست.
- **تست:** unit.
- **داکیومنت:** `42`.
- **شدت:** 🟠

### A8-05 — guardrails + prompt templates
- **تغییر:** `PromptTemplateController`.
- **AC:** قالب‌های قابل مدیریت.
- **تست:** feature.
- **داکیومنت:** `47`.
- **شدت:** 🟡

### A8-06 — واقعی‌سازی AI (کیفیت 85+)
- **هدف:** ارتقای کیفیت از RuleBased 50-65.
- **تغییر:** اتصال provider واقعی.
- **AC:** امتیاز واقعی بالای 85.
- **تست:** evaluation.
- **داکیومنت:** `42`, `44`.
- **شدت:** 🔴

---

## فاز ۹ — Automation & Commands

### A9-01 — سیاست‌های اتوماسیون
- **تغییر:** `AutomationPolicyController`.
- **AC:** policy/trust/routes با گیت‌های D-017.
- **تست:** feature.
- **داکیومنت:** `01`, `28`.
- **شدت:** 🔴

### A9-02 — dispatch + decision
- **تغییر:** `CommandDispatchController`, `CommandDecisionController`.
- **AC:** تأیید/رد + throttle.
- **تست:** feature.
- **داکیومنت:** `28`.
- **شدت:** 🔴

### A9-03 — اجرای command روی وردپرس
- **تغییر:** اتصال به فاز ۵.
- **AC:** end-to-end publish.
- **تست:** integration.
- **داکیومنت:** `28`, `43`.
- **شدت:** 🔴

### A9-04 — emergency stop + resume
- **تغییر:** `AutomationPolicyController`.
- **AC:** توقف فوری + ازسرگیری.
- **تست:** feature.
- **داکیومنت:** `01`.
- **شدت:** 🟠

### A9-05 — rollback بدون اتلاف
- **تغییر:** مکانیزم rollback.
- **AC:** برگشت امن تغییرات.
- **تست:** integration.
- **داکیومنت:** `01`, `28`.
- **شدت:** 🟠

---

## فاز ۱۰ — Reporting & Client Portal

### R10-01 — تولید + publish گزارش
- **تغییر:** `ReportController`, `ReportPublishController`.
- **AC:** گزارش قابل publish.
- **تست:** feature.
- **داکیومنت:** `29`.
- **شدت:** 🔴

### R10-02 — گزارش تأثیر (قبل/بعد GSC)
- **تغییر:** مقایسهٔ GSC قبل/بعد.
- **AC:** کارت «تأثیر محتوا».
- **تست:** feature.
- **داکیومنت:** `29`.
- **شدت:** 🔴

### R10-03 — پرتال مشتری (پنل کامل)
- **تغییر:** `resources/js/Pages/Client`, `Client*Controller`.
- **AC:** dashboard/growth/site-health/opportunities/decisions/activity.
- **تست:** E2E.
- **داکیومنت:** `35`.
- **شدت:** 🔴

### R10-04 — تصمیم‌گیری مشتری
- **تغییر:** `ClientDecisionController`.
- **AC:** command/question/review + throttle.
- **تست:** feature.
- **داکیومنت:** `35`.
- **شدت:** 🟠

### R10-05 — UX پرتال (نتیجه‌محور)
- **هدف:** اصل #7 — بدون دادهٔ خام.
- **تغییر:** بازبینی UI پرتال.
- **AC:** مشتری نتیجه را می‌فهمد.
- **تست:** بازبینی لیلا.
- **داکیومنت:** `35`.
- **شدت:** 🟡

---

## فاز ۱۱ — Marketing & Lead Funnel

### M11-01 — لندینگ واحد آژانس + Lead Magnet
- **تغییر:** `Marketing/ForAgencies` + فرم گزارش رایگان.
- **AC:** لندینگ با CTA + Lead Magnet.
- **تست:** بازبینی CRO.
- **داکیومنت:** `33`.
- **شدت:** 🔴

### M11-02 — قیف لید
- **تغییر:** `MarketingLeadController` (store/status/notes/امتیاز).
- **AC:** قیف قابل ردیابی.
- **تست:** feature.
- **داکیومنت:** `33`.
- **شدت:** 🔴

### M11-03 — Assistant (دانش/چت/تماس)
- **تغییر:** `AssistantController`.
- **AC:** چت + throttle.
- **تست:** feature.
- **داکیومنت:** `33`.
- **شدت:** 🟠

### M11-04 — CRO (CTA/دمو)
- **تغییر:** بهینه‌سازی نرخ تبدیل.
- **AC:** دموی کوتاه + CTA واضح.
- **تست:** بازبینی حامد.
- **داکیومنت:** `33`.
- **شدت:** 🟠

---

## فاز ۱۲ — Platform Command Center

### PL12-01 — داشبورد + events
- **تغییر:** `PlatformDashboardController`, `PlatformDecisionController`.
- **AC:** داشبورد سوپرادمین + resolve.
- **تست:** feature.
- **داکیومنت:** `38`.
- **شدت:** 🟠

### PL12-02 — سازمان‌ها (suspend/activate/impersonate)
- **تغییر:** `PlatformOrganizationController`, `PlatformImpersonationController`.
- **AC:** مدیریت سازمان + impersonation با readonly.
- **تست:** authorization.
- **داکیومنت:** `38`.
- **شدت:** 🔴

### PL12-03 — صورتحساب (plans/subscriptions/payments/invoices)
- **تغییر:** `PlatformBillingController`.
- **AC:** چرخهٔ کامل billing.
- **تست:** feature.
- **داکیومنت:** `39`.
- **شدت:** 🔴

### PL12-04 — درگاه پرداخت + callback
- **تغییر:** `PlatformPaymentGatewayController`.
- **AC:** zarinpal/aqayepardakht + callback عمومی.
- **تست:** mock.
- **داکیومنت:** `39`.
- **شدت:** 🔴

### PL12-05 — MFA سوپرادمین
- **تغییر:** `PlatformMfaController`.
- **AC:** challenge/verify/setup.
- **تست:** feature.
- **داکیومنت:** `39`.
- **شدت:** 🟠

### PL12-06 — SMS + لاگ
- **تغییر:** `PlatformSmsController`.
- **AC:** ارسال + لاگ.
- **تست:** feature.
- **داکیومنت:** `39`.
- **شدت:** 🟡

### PL12-07 — emergency stop پلتفرمی
- **تغییر:** `PlatformEmergencyController`.
- **AC:** توقف فوری.
- **تست:** feature.
- **داکیومنت:** `37`.
- **شدت:** 🟠

---

## فاز ۱۳ — Frontend/Design/UX

### D13-01 — ممیزی RTL + mixed-direction
- **تغییر:** بازبینی همهٔ صفحات.
- **AC:** URL/کد LTR در متن RTL.
- **تست:** بازبینی.
- **داکیومنت:** `07`, `16`.
- **شدت:** 🟠

### D13-02 — دسترسی‌پذیری (keyboard/aria/focus)
- **AC:** ناوبری با کیبورد کامل.
- **تست:** بازبینی.
- **داکیومنت:** `07`.
- **شدت:** 🟠

### D13-03 — stateهای کامل (empty/loading/error/success)
- **AC:** هر صفحه همهٔ stateها را دارد.
- **تست:** بازبینی.
- **داکیومنت:** `13`.
- **شدت:** 🟡

### D13-04 — ریسپانسیو موبایل
- **AC:** موبایل‌فرست.
- **تست:** بازبینی.
- **داکیومنت:** `13`.
- **شدت:** 🟠

### D13-05 — توکن‌ها و کامپوننت‌کانترکت
- **تغییر:** `16-Tailwind-Design-Tokens` → پیاده‌سازی.
- **AC:** توکن‌ها واحد.
- **تست:** بازبینی.
- **داکیومنت:** `16`.
- **شدت:** 🟡

### D13-06 — جورنی‌های پولساز (دموی ۵ دقیقه‌ای)
- **تغییر:** مسیر onboarding → گزارش واقعی.
- **AC:** ارزش در ۵ دقیقه دیده می‌شود.
- **تست:** بازبینی لیلا.
- **داکیومنت:** `02`, `33`.
- **شدت:** 🔴

---

## فاز ۱۴ — Infrastructure Hardening

### I14-01 — SSL/HTTPS
- **تغییر:** Let's Encrypt + Nginx.
- **AC:** HTTPS کامل + HSTS + کوکی secure.
- **تست:** smoke.
- **داکیومنت:** `41`.
- **شدت:** 🔴 — **مالک:** رضا

### I14-02 — مهاجرت SQLite → PostgreSQL + بکاپ
- **AC:** Postgres + بکاپ روزانه + بازیابی.
- **تست:** migration.
- **داکیومنت:** `41`.
- **شدت:** 🔴 — **مالک:** رضا/سارا

### I14-03 — SMTP واقعی + نوتیفیکیشن
- **AC:** ایمیل واقعی.
- **تست:** smoke.
- **داکیومنت:** `41`.
- **شدت:** 🔴

### I14-04 — Redis + Horizon + Supervisor
- **AC:** صف پایدار.
- **تست:** smoke.
- **داکیومنت:** `41`.
- **شدت:** 🟠

### I14-05 — rate limiting + مانیتورینگ + لاگ
- **AC:** مانیتورینگ فعال.
- **تست:** smoke.
- **داکیومنت:** `41`.
- **شدت:** 🟠

### I14-06 — هاردنینگ Docker/Nginx + عدم نشت secret
- **AC:** اسکن secret + هدرهای امنیتی.
- **تست:** audit.
- **داکیومنت:** `12`, `41`.
- **شدت:** 🔴

### I14-07 — بکاپ/restore و disaster recovery
- **AC:** بازیابی کامل در زمان مشخص.
- **تست:** drill.
- **داکیومنت:** `41`.
- **شدت:** 🟠

---

## اولویت‌بندی نهایی (نگاه تجاری مشترک)
| سطح | فازها | منطق |
|---|---|---|
| 🔴 مسیر درآمد | ۱→۲→۳→۴→۵→۶→۷→۱۰ | Connect→Collect→Analyze→Report = چیز قابل فروش |
| 🟡 تقویت | ۸→۹ | AI + اتوماسیون = Done-for-you و مقیاس |
| 🟢 تکمیل | ۱۱→۱۲→۱۳→۱۴ | گوتومارکت، فرماندهی، پولیش، پایداری |

> **نکته:** فاز ۱۴ (زیرساخت) در مسیر تجاری «آخر» است، اما ۴ پیش‌نیاز فروش آن (SSL/Postgres/SMTP/Redis) قبل از ورود مشتری پولی الزامی‌اند — یعنی موازی با فازهای 🔴 انجام می‌شوند.

---

# بخش ۳ — لاگ اجرا (Execution Log)

> قالب هر رکورد: **فاز / تسک / هدف / تغییر / فایل‌ها / تست / وضعیت**.

## فاز ۱ — Identity & Auth — ✅ تکمیل شد

### 1.1 بازسازی ثبت‌نام OTP
- **هدف:** باز کردن درِ ورود مشتری. **تغییر:** `OtpRegisterController@request` از 501 → اتصال به OtpService + رد شمارهٔ تکراری + rate-limit. **فایل:** `app/Http/Controllers/Auth/OtpRegisterController.php`. **تست:** `RegisterTest`/`OtpAuthTest`. **وضعیت:** ✅ lint سبز

### 1.2 بازسازی ورود OTP
- **هدف:** فعال‌کردن ورود بدون رمز. **تغییر:** `OtpLoginController` (request/verify + Auth::login + audit). **فایل:** `app/Http/Controllers/Auth/OtpLoginController.php`. **تست:** `OtpAuthTest`. **وضعیت:** ✅

### 1.3 رفع نشت کد OTP
- **تغییر:** `OtpService` (کد فقط غیر-پروداکشن) + `KavenegarSms` (success=false در پروداکشن بدون کلید). **فایل:** ۲ فایل. **وضعیت:** ✅

### 1.4 لاگ ورود ناموفق
- **تغییر:** `auth.login_failed` در `AuthenticatedSessionController`. **تست:** `test_failed_login_is_audited`. **وضعیت:** ✅

### 1.5 پیام صادقانهٔ بازیابی رمز
- **تغییر:** خطا وقتی mailer=log (فقط پروداکشن). **فایل:** `PasswordResetLinkController.php`. **وضعیت:** ✅

### 1.6 بهینه‌سازی isSuperAdmin
- **تغییر:** `get()+contains` → `exists()` با `whereHas`. **فایل:** `app/Models/User.php`. **وضعیت:** ✅

### 1.7 حذف ستون‌های مردهٔ two_factor_*
- **تغییر:** مایگریشن drop. **فایل:** `database/migrations/2026_08_25_000001_...`. **وضعیت:** ✅

### 1.8 مستندسازی
- **تغییر:** `PHASE1_AUTH_REVIEW.md`, `31-Progress-Log.md`, `FIELD_TEST_REPORT.md`. **وضعیت:** ✅

### 1.9 ابزار lint
- **تغییر:** `tools/php-lint/`. **وضعیت:** ✅

### 1.10 دیپلوی امن
- **تغییر:** `deploy-phase1.sh`. **وضعیت:** ✅ push شد — منتظر اجرا روی سرور.

---

## فاز ۲ — Organization & Membership — 🚧 در حال اجرا

### 2.1 — رفع escalation سطح دسترسی (اسکالیشن به super-admin) 🔴
- **هدف:** جلوگیری از اینکه یک `agency-admin` (سطح سازمان) بتواند نقش `super-admin` (سطح پلتفرم) را به خود/دیگران تخصیص دهد و پلتفرم را تسخیر کند.
- **تغییر:** `OrganizationSettingsController` — لیست `roles` ارسالی به فرانت، نقش `super-admin` را حذف می‌کند؛ validation در `store` و `update` با `Rule::exists(...)->where('key','!=','super-admin')` تخصیص آن را در سطح دیتابیس مسدود می‌کند.
- **فایل:** `app/Http/Controllers/App/Settings/OrganizationSettingsController.php`
- **تست:** `test_agency_admin_cannot_assign_super_admin_role`, `test_agency_admin_cannot_elevate_member_to_super_admin_via_update`
- **وضعیت:** ✅ lint سبز

### 2.2 — محافظ «آخرین مدیر» (last-admin guard) 🟠
- **هدف:** جلوگیری از تنزل/حذف آخرین مدیر سازمان (که سازمان را بدون مدیر رها می‌کند).
- **تغییر:** افزودن `assertNotLastAdminWhenDemoting` و `assertNotLastAdminWhenRemoving` + `otherAdminExists`.
- **فایل:** همان کنترلر.
- **تست:** `test_cannot_demote_the_last_agency_admin`, `test_cannot_remove_own_membership`, `test_admin_can_remove_a_non_admin_member`
- **وضعیت:** ✅ lint سبز

### 2.3 — بهینه‌سازی EnsurePlatformAccess 🟡
- **هدف:** حذف تکرار منطق `get()->contains()` و استفاده از `isSuperAdmin()` بهینه.
- **تغییر:** `EnsurePlatformAccess` → `$user->isSuperAdmin()`.
- **فایل:** `app/Http/Middleware/EnsurePlatformAccess.php`
- **وضعیت:** ✅ lint سبز

### 2.4 — انتخاب قطعی سازمانِ پیش‌فرض 🟡
- **هدف:** حذف رفتار غیرقطعی `orderBy('id')` در انتخاب سازمانِ پیش‌فرض.
- **تغییر:** `EnsureCurrentOrganization` → `orderBy('created_at')` (قدیمی‌ترین عضویتِ فعال = نخستین سازمان کاربر).
- **فایل:** `app/Http/Middleware/EnsureCurrentOrganization.php`
- **وضعیت:** ✅ lint سبز

---

## وضعیت کنونی
- **فاز جاری:** فاز ۲ — Organization & Membership (تسک‌های 2.1–2.4 اجرا شد؛ 2.5–2.7 در ادامه).
- **اقدام بعدی:** ادامهٔ تسک‌های فاز ۲ (سوئیچ سازمان، ماتریس مجوز، تست‌های مرجع)، سپس commit + push + آپدیت داکیومنت‌های `vision-prime-docs/`.
