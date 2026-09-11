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

## 🔴 فاز فعال فعلی (۲۰۲۶-۰۹-۰۸) — پیش‌نیاز تولید گروهی

> مرجع کامل: سند `50-In-Content-Linking-and-Bulk-Readiness-Plan.md`

- **فاز ۵۰ (IL1–IL5) — عملاً تکمیل شده** (آخرین به‌روزرسانی ۲۰۲۶-۰۹-۰۸):
  - **IL1 ✅** — موتور تزریق درون‌متنی سه‌مرحله‌ای (تطبیق مستقیم → جملهٔ پل → بلوک پایانی) با ۴ قالب جملهٔ متنوع، سقف ۴ لینک، dedupe URL و anchorهای متنوع (بدون keyword-stuffing). تست زنده با دیتای واقعی لیونا: ۴ لینک درون‌متنی با anchorهای متفاوت.
  - **IL2 ✅** — RuleBasedDraft: حذف آرتیفکت «دستور ویژه» (لو رفتن پرامپت داخلی)، «جمع‌بندی» همیشه آخر، متادیتای بدون کلمات چسبیده، حذف بلوک لینک دستی (واگذار به موتور).
  - **IL3 ✅** — گیت انتشار: `needs_review` برای خروجی رول‌بیس (دکمهٔ انتشار در UI قفل می‌شود — در حالت توسعه/تست با `CONTENT_ALLOW_RULEBASED_PUBLISH=1` باز می‌شود تا جورنی وردپرس بدون مصرف کلید AI تست شود؛ پیش‌فرض production بسته). **تست زندهٔ انتشار رول‌بیس روی production موفق بود** (۲۰۲۶-۰۹-۰۸): draft رول‌بیس ۹۸۱ واژه از طریق کانکتور به وردپرس لیونا ارسال شد → پست #6906 با وضعیت draft ساخته شد (H1 و اسکیمای بدنه حذف شدند، متا ثبت شد). حذف H1 تزریقی + اسکیمای داخل بدنه قبل از ارسال، اسکیمای بدون null، حداقل ۸۰۰ واژه برای مقاله (تست زنده: گیت کار کرد)، نامک با SlugGenerator::transliterate + چک تصادم `-1` (تست زنده: `test-collision` → `test-collision-1`)، focus keyword توسط پلاگین ۱.۴.۴ ثبت می‌شود.
  - **IL4 🟡** — شناسایی کامل آلودگی تستی لیونا انجام شد (۱۳ دسته + ۱۷ تگ — لیست در سند ۵۰). حذف فیزیکی نیازمند ورود ادمین وردپرس لیونا است (پلاگین endpoint حذف ترم ندارد).
  - **IL5 ✅** — ContentAnalyzer: هشدار رقابت برای عنوان تک‌کلمه‌ای + ۳ پیشنهاد long-tail با دکمهٔ «استفاده» + قفل تولید سریع (تست زنده: «مکمل» → هشدار، «کرم» → هشدار، عنوان معمولی → بدون هشدار).
- **P2.5 (تولید گروهی) ✅ تکمیل و دیپلوی شد** (۲۰۲۶-۰۹-۰۸):
  - جدول‌های `bulk_jobs` + `bulk_job_items` + مدل‌ها + migration (اجرا شده روی production)
  - `BulkContentService` — هر آیتم کل pipeline فاز ۵۰ را طی می‌کند (Analyzer → Gateway → لینک → گیت کیفیت → draft)
  - `content:bulk-run` worker (ری‌ترای آیتم‌های failed) + spawn از فرانت
  - API: ساخت/لیست/جزئیات/اجرا/استاتوس + سقف روزانه ۳۰ آیتم (P2.5.5)
  - صفحهٔ `App/BulkContent.vue` + منوی «استودیوی محتوا ← تولید گروهی»
  - تست زندهٔ production: ۳ آیتم → ۲ draft سالم (۱۱۱۰ و ۹۸۹ واژه) + گیت IL5 روی «مکمل» + پاک‌سازی خودکار تست
  - تست UI کامل (preview): ساخت دسته → شروع → پولینگ → وضعیت «ناقص» با جزئیات ✓
- **P2.6 (اتصال Bulk به انتشار وردپرس) ✅ تکمیل و دیپلوی شد** (۲۰۲۶-۰۹-۰۸):
  - Migration ستون‌های انتشار (`publish_status`/`post_id`/`published_at`/`publish_error`) روی `bulk_job_items` — اجرا روی production
  - `BulkContentService::publishItem()` + `publishJob()` — گیت کیفیت هر آیتم (رول‌بیس + حداقل واژه + اتصال) از طریق کانکتور امضاشده + بازیابی post_id واقعی از پاسخ پلاگین
  - API: `POST /api/bulk-content/items/{id}/publish` + `POST /api/bulk-content/jobs/{id}/publish`
  - UI: دکمهٔ «انتشار» تکی + «انتشار همهٔ آماده‌ها» + بج وضعیت/خطای گیت
  - تست زندهٔ production: «راهنمای کامل ماسک مو» → **پست #6907 وردپرس ساخته شد**؛ «کرم» (تک‌کلمه‌ای) توسط گیت IL5 رد شد ✓
- **P2.7 (انتشار خودکار + کارت آمار) ✅ تکمیل و دیپلوی شد** (۲۰۲۶-۰۹-۰۸):
  - Migration `2026_09_08_000003_add_auto_publish_to_bulk_jobs.php` — ستون `auto_publish` (off|draft|publish) روی `bulk_jobs`
  - انتشار خودکار در `processItem` — آیتم‌های عبورکرده از گیت بلافاصله به وردپرس می‌روند؛ ردشده‌ها در صف بازبینی می‌مانند
  - UI: سلکت «انتشار خودکار» در فرم ساخت + بج روی کارت دسته + کارت آمار (منتشرشده/ردشده/صف بازبینی + لینک پست‌ها) + فیلتر آیتم‌ها
  - API: `GET /api/bulk-content/stats`
  - تست زندهٔ production: «راهنمای کامل انتخاب شامپو مناسب» با auto_publish=draft → **پست #6908 خودکار ساخته شد**؛ «مکمل» → needs_review بدون تلاش انتشار ✓
- **P2.8 (بازطراحی + زمان‌بندی + گزارش کیفیت) ✅ تکمیل و دیپلوی شد** (۲۰۲۶-۰۹-۰۸):
  - Migration ستون‌های `scheduled_at` + `daily_publish_limit` روی `bulk_jobs` (اجرا روی production)
  - `reworkItem()` — بازتولید آیتم‌های needs_review/failed با عنوان جدید؛ دکمه «🔄 بازتولید» + پیشنهادهای long-tail قابل‌کلیک در UI
  - `content:bulk-schedule` — زمان‌بند کرون + گیت زمان‌بندی در worker و run (قبل از زمان مقرر پردازش نمی‌شود)
  - گیت سقف انتشار روزانه — مازاد تولید می‌شود ولی در صف انتشار می‌ماند
  - `GET /api/bulk-content/quality-report` — نرخ انتشار + میانگین امتیاز + دلایل رایج رد + پیشنهادهای بهبود + کارت «📈 گزارش کیفیت»
  - منو: «تولید گروهی» بعد از «تولید محصول» با آیکون zap (تست UI تأیید شد)
  - تست زندهٔ production: گیت زمان‌بندی + rework تأیید شد ✓
- **P2.9 (ارتقای تولید محصول به سطح مقاله) ✅ تکمیل و دیپلوی شد** (۲۰۲۶-۰۹-۰۸):
  - **فیکس باگ واقعی `needs_review`**: در `ContentGenerateController::generate` دو عبارت bool خام (به‌جای کلید نامدار) توی آرایهٔ audit_log و پاسخ JSON جا گرفته بود → کلید `needs_review` هرگز به فرانت نمی‌رسید و گیت UI (دکمهٔ انتشار) عملاً خاموش بود. فیکس: `'needs_review' => !allowRulebasedPublish && source==='rule_based'` در هر دو مکان — اکنون صفحهٔ مقاله هم گیتش واقعاً کار می‌کند
  - **پرامپت اختصاصی محصول** در `AiPromptBuilder::productPrompts()`: سیستم پرامپت فروشگاهی (کپی‌رایتر + سئوی ووکامرس)، ممنوعیت h1 (وردپرس عنوان را H1 می‌کند)، اسکیمای Product، ساختار ویژگی→فایده→مشخصات جدولی→CTA، و رفتار متفاوت per-subtype (short_desc بدون زیرعنوان / technical با جدول مشخصات / comparison / long_desc) — قبلاً برای محصول هم پرامپت «مقاله» ساخته می‌شد (۹ تست واحد محلی پاس)
  - **پورت سه کارت هوشمند به صفحهٔ تولید محصول** (`ProductDraft/Create.vue`): کارت «کلمه کلیدی هوشمند» (debounce روی تایپ عنوان + ۵ گزینه رتبه‌بندی + هشدار رقابت) + کارت «پیشنهاد دسته‌بندی» (پیشنهاد/انتخاب خودکار ≥۵۵٪/ساخت دسته جدید با SEO meta) + کارت «🏷️ تگ‌های هوشمند» (خودکار بعد از تولید + افزودن به برچسب‌های انتخابی) + گیت needs_review روی دیالوگ انتشار
  - **اکانت دمو عملیاتی شد**: سایت تست (id=3، `سایت تست دمو`، https://demo.liuna.ir) + client + project برای ارگ دمو ساخته شد؛ رمز دمو ریست شد
  - تست زندهٔ E2E روی production (لاگین واقعی + HTTP): تولید محصول رول‌بیس → `needs_review` با کلید درست + امتیاز ۹۰ + **بدون h1** + **اسکیمای Product** + draft ذخیره؛ کیوورد هوشمند محصول (`راهنمای کرم ضد آفتاب` ۷۸٪ با دلیل «محصول»)، پیشنهاد ساخت دسته جدید، تگ‌های هوشمند — همه ✓ (draft تستی پاک شد)
- امتیازدهی InternalLinkEngine نسخهٔ ۲ (ایست‌واژه + آستانه ۴۵٪) دیپلوی و تست شده ✅
- **تست زندهٔ محصول → ووکامرس لیونا (۲۰۲۶-۰۹-۰۸) ✅** — جورنی کامل: تولید «کرم ضد آفتاب صورت SPF 50» (rule-based، بدون H1، اسکیمای Product) → پیشنهاد دسته «ضد آفتاب» ۱۰۰٪ (product_cat) → انتشار → پست‌های #6910 و #6911 با `post_type=product` + دسته/تگ درست + متا Rank Math (پس از فیکس‌ها).
  - **فیکس ۱ (باگ واقعی):** تشخیص محصول در `PublishDraftThroughConnector` با `subtype === 'product'` هرگز match نمی‌شد → گیت ۸۰۰ واژهٔ مقاله اعمال و `content_type=article` ارسال می‌شد. حالا `ContentProfiler::SUBTYPES['product']`.
  - **فیکس ۲:** متا دیسکریپشن از «مقدمه» چسبیده یا متن جدول مشخصات شروع می‌شد → `deriveMetaDescription()` (حذف هدینگ/جدول + جملهٔ اول پاراگراف واقعی + ۱۵۵ کاراکتر).
  - **فیکس ۳:** ساخت دستهٔ محصول در taxonomy اشتباه `category` → پارامتر `taxonomy=product_cat` (بک‌اند + فرانت محصول).
  - **فیکس ۴ (پلاگین):** پلاگین ۱.۴.۴ `meta_description` را نمی‌نوشت → **۱.۴.۵** ساخته و روی لینک دانلود قرار گرفت (`public/vision-prime-connector.zip`) + متای پست‌های #6910/#6911 به‌صورت مستقیم از طریق کانکتور فیکس و تأیید شد.
- **قدم ۱ پلن اکوسیستم (cron + Supervisor) ✅ تأیید شد (۲۰۲۶-۰۹-۰۸):** crontab `schedule:run` + Supervisor `visionprime-scheduler` و `visionprime-queue` (redis) RUNNING؛ `content:bulk-schedule` به schedule اضافه شد (هر ۵ دقیقه — قبلاً تعریف نشده بود)؛ باگ fail روزانهٔ `CollectPlatformEvents` (ستون گمشدهٔ `resolved_at` در `review_items`) با migration `2026_09_08_000005` فیکس و Job تست شد. مرجع: سند `52-Ecosystem-Activation-Plan.md`
- **قدم ۲ پلن اکوسیستم (Adaptive Learning) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸) — تست زنده production ۷/۷ PASS:**
  - `AdaptiveLearning` سرویس جدید: `isBlocked()` / `blockReason()` (فارسی) / `health()` / `shouldBlock()` — آستانه‌ها: ۳ شکست متوالی یا نرخ موفقیت <۵۰٪ با ≥۳ نمونه → مسدود؛ **auto-heal** با ۲ موفقیت متوالی؛ جداسازی کامل به‌ازای (سایت، نوع دستور)
  - Migration `2026_09_08_000006`: ستون‌های `consecutive_failures`/`blocked`/`blocked_reason`/`blocked_at`/`last_status` روی `automation_learning_history` (روی production اجرا شد)
  - `LearningLoop` بازنویسی شد: محاسبهٔ شکست/موفقیت‌های متوالی از trail واقعی + فلگ blocked با دلیل فارسی + auto-heal + **فیکس باگ** (کوئری دوم groupBy قبلی را به ارث می‌برد → clone)
  - **گیت پیشنهاد:** `CreateRiskRecommendations` — ریسک‌های منجر به نوع مسدود دیگر پیشنهاد نمی‌شوند (خروجی `suppressed`)؛ `RecommendationController::fromOpportunity` — فرصت‌های نوع مسدود با پیام فارسی رد می‌شوند
  - **گیت اجرا (خط دفاعی دوم):** `ConvertRecommendationToCommand` — تبدیل نوع مسدود → `RuntimeException` فارسی + audit `command.blocked_by_learning`؛ فاکتور `learning_blocked` در `CommandConfidenceAssessor`
  - `RunGrowthAnalysis` خروجی `suppressed_recommendations` دارد (شفافیت)
  - تست زنده production (پراب ۷ مرحله‌ای با دیتای واقعی): مسدودیت ۳ شکست متوالی ✅ · جداسازی نوع/سایت ✅ · رد تبدیل ✅ · suppress ریسک ✅ · auto-heal ✅ · مسدودیت مجدد ✅ — دیتای تست پاک شد. مرجع: سند `52-Ecosystem-Activation-Plan.md`
- **قدم ۳ پلن اکوسیستم (خودمختاری پلکانی T1/T2/T3) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸) — تست زنده production ۱۲/۱۲ PASS:**
  - Migration `2026_09_08_000007`: ستون `autonomy_tier` (manual/t1/t2/t3) روی `site_automation_policies` (روی production اجرا شد)
  - `PolicyEvaluator` — منطق پلکانی با آستانه‌های اعتماد: manual (همه انسانی) / T1 (متا+تگ خودکار، ≥۶۰٪) / T2 (+تولید محتوا خودکار، گیت کیفیت+گرمایش) / T3 (+به‌روزرسانی محتوای منتشرشده، R3 همیشه انسانی)
  - `AutoPublish` — خواندن tier از سیاست + اطلاع‌رسانی (`NotifyAutoPublishTeam` + `AutoPublishNotification`) بعد از انتشار خودکار در T2/T3
  - `AutomationPolicyController` — اعتبارسنجی و ذخیره `autonomy_tier` + ارسال به فرانت
  - UI — سلکتور سطح خودمختاری با توضیح فارسی در صفحه اتوماسیون سایت
  - تست زنده production (پراب ۱۲ مرحله‌ای): T1 متا بالا ✅ · T1 متا پایین ✅ · T1 مقاله جدید ✅ · T1 R3 ساختاری ✅ · T1 fail-closed ✅ · T2 کیفیت+گرمایش ✅ · T2 بدون گرمایش ✅ · T2 کیفیت رد ✅ · T3 به‌روزرسانی ✅ · T3 حذف R3 ✅ · manual backward-compat ✅ · PolicyEvaluator عمومی ✅ — دیتای تست پاک شد. مرجع: سند `52-Ecosystem-Activation-Plan.md`
- **قدم ۴ پلن اکوسیستم (OpportunityPipeline) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸):**
  - `OpportunityPipeline` — تبدیل خودکار `opportunities` (status=open) به `BulkJob + BulkJobItems` با اولویت‌بندی بر اساس score + idempotent + tier-aware (auto_publish)
  - `OpportunityPipelineJob` (Laravel Job) + `RunOpportunityPipeline` (کنسول کامند)
  - Schedule: هر ۳۰ دقیقه `content:opportunity-pipeline` + `withoutOverlapping` + `onOneServer`
  - تست زنده production: ۳ فرصت test → ۳ دسته خودکار با نام‌های فارسی + auto_publish بر اساس tier ✓ — دیتای تست پاک شد. مرجع: سند `52-Ecosystem-Activation-Plan.md`
- **قدم ۵ پلن اکوسیستم (گزارش خودکار مشتری) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸):**
  - `BuildClientWeeklyReport` — جمع‌آوری جامع دادهٔ هفتگی هر مشتری: تولیدات محتوا (تعداد/کیفیت/واژه) + انتشارها (موفق/ناموفق) + فرصت‌ها (یافت‌شده/اقدام‌شده) + ریسک‌ها + روند GSC (کلیک/نمایش) + اتوماسیون (دستور/خودکار/انسانی) + نقاط عطف هوشمند
  - `SendClientWeeklyReports` — Job: هر شنبه صبح ۰۹:۰۰ برای هر مشتری با سایت فعال → ذخیره در `reports` (status=published) + اعلان به مدیر ارشد
  - Schedule: `weeklyOn(6, '09:00')` + `onOneServer`
  - تست زنده production: لیونا بیوتی (80 محتوا، کیفیت 75.9، 76 دستور، 18 خودکار) + مشتری دمو (گزارش خالی) ✓ — دیتای تست پاک شد. مرجع: سند `52-Ecosystem-Activation-Plan.md`
- **تکمیل حلقه — اندازهگیری خودکار تأثیر ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸):**
  - `MeasurePublishImpact` Job — روزانه ۰۶:۳۰: کامندهای publish_new_article ≥۲ روزه بدون impact event تازه → `BuildPublishImpactReport` → ذخیره/آپدیت `impact_events` + هشدار افت معیارها
  - Schedule: `dailyAt('06:30')` + `withoutOverlapping` + `onOneServer`
  - تست زنده production: Job با موفقیت اجرا شد (86 impact event موجود، همه تازه) ✓. مرجع: سند `52-Ecosystem-Activation-Plan.md`
- **قدم ۶ پلن اکوسیستم — DataBridge: تلمتری داخلی ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۱۰):**
  - مشکل: سرور فقط نت ملی دارد → GSC قطع → حلقهٔ اندازه‌گیری تأثیر انتشار کور می‌ماند
  - راه‌حل: تلمتری بازدید واقعی از خودِ سایت (پلاگین وردپرس + Matomo سلف‌هاست) جایگزین GSC می‌شود
  - **پلاگین v1.5.0** (`class-vp-telemetry.php`): شمارش بازدید سمت سرور (template_redirect) + cron روزانه ۰۲:۳۰ + ارسال امضاشده به `POST /connector/traffic` (HMAC مشابه connector) + آداپتور Matomo (اختیاری)
  - **پلتفرم**: migration `site_traffic_daily` + `site_traffic_ingest_log` · `TrafficIngestService` (validate + upsert + idempotency) · `TrafficIngestController` (HMAC verification)
  - **fallback در BuildPublishImpactReport**: وقتی GSC property نیست یا داده ناکافی ← از `site_traffic_daily` خوانده می‌شود (views_before/after → verdict improved/declined/stable)
  - **سازگاری**: MeasurePublishImpact + BuildContentImpactSummary — delta.clicks ≡ views برای telemetry source · attribution_note فارسی با source
  - تست‌های واحد: ۹/۹ سبز (۶ اصلی + ۳ جدید: fallback تلمتری · idempotency · normalization URL)
  - تست E2E سرور: POST امضاشده → DB insert → windowFor → idempotency re-post → cleanup — همه ✓
  - لینک دانلود پلاگین: `https://visionprime-suite.ir/vision-prime-connector.zip` (v1.5.0)
  - ⚠️ **باگ تولیدی شناخته‌شده**: `site_connections.secret_ciphertext` لیونا (site_id=1) با APP_KEY قدیمی رمزنگاری شده (چرخش کلید قبلی) → connector HMAC شکسته → نیاز به **re-pair** سایت لیونا در وردپرس
- **قدم ۷ پلن اکوسیستم — DecisionEngine (موتور تخصیص بودجه هوشمند) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۱۰):**
  - `DecisionEngine.php` — اولویت‌بندی ریاضی فرصت‌ها بر اساس «اثر مورد انتظار ÷ ریسک» با توجه به بودجهٔ روزانهٔ سایت:
    - اثر = ترکیب وزنی: قدرت سیگنال (score) + سابقهٔ یادگیری تاریخی (automation_learning_history) + ترافیک واقعی صفحه (site_traffic_daily از DataBridge) + نوع فرصت
    - ریسک = نوع فرصت (interlink کم‌خطر → keyword پرهزینه) + سابقهٔ شکست همان نوع
    - تخصیص حریصانه: مرتب‌سازی نزولی اثر÷ریسک → تا رسیدن به بودجهٔ روزانه
    - فرصت‌های نوعِ مسدودشده توسط AdaptiveLearning هرگز تخصیص نمی‌یابند
    - `OpportunityPipeline` اکنون به جای `sortByDesc('score')` ساده از DecisionEngine استفاده می‌کند
    - هزینهٔ اجرا: محتوای جدید ۳ واحد، متا ۱ واحد، بقیه ۲ واحد
  - **تست‌ها**: ۴ تست واحد سبز (رتبه‌بندی · بودجه · مسدودیت · پرکردن بودجه)
- **قدم ۸ پلن اکوسیستم — PreExecutionSimulator (شبیه‌ساز پیش از اجرا) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۱۰):**
  - `PreExecutionSimulator.php` — تخمین اثر هر تغییر قبل از تصمیم PolicyEvaluator:
    - ترکیب: ۴۰٪ سابقهٔ تاریخی impact_events + ۳۰٪ ترافیک واقعی (DataBridge) + ۳۰٪ کیفیت محتوا
    - خروجی: expected_impact (۰–۱۰۰) · success_probability · risk_tier پیشنهادی · simulated_confidence · توصیهٔ execute/review
    - fail-closed: شبیه‌ساز هرگز ریسک را پایین نمی‌آورد (فقط بالاتر می‌برد)
    - اتصال به `ConvertRecommendationToCommand`: اگر اثر پایین باشد command ساخته نمی‌شود + audit log
    - اتصال به `ConfidenceScorer`: دادهٔ واقعی ترافیک/سابقه به امتیاز اطمینان اضافه می‌شود
  - **تست‌ها**: ۳ تست واحد سبز (تخمین محافظه‌کارانه · سابقه بالا → اثر بالا · ریسک افزایشی)
- **فیکس باگ AdaptiveLearningTest ✅**: تست از ستون `page_type` (ناموجود) و `site_id` روی `conversion_risks` (ناموجود) استفاده می‌کرد → با `content_type` + `public_id` + `score` اصلاح شد (از قبل شکسته بود)
- **قدم ۴ اکوسیستم — PostPublishVerifier (تأیید بعد از اجرا) ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۱۰):**
  - `PostPublishVerifier.php` — بعد از auto-publish، URL منتشرشده را دوباره کرال و تأیید می‌کند:
    - `update_meta_title`: عنوان صفحه شامل مقدار جدید باشد (partial match ≥ ۵۰٪)
    - `update_meta_description`: توضیحات متا شامل مقدار جدید باشد
    - `publish_new_article`: صفحه وجود داشته باشد (200)
    - `update_content`: hash محتوا تأیید شود
  - `rollback_recommended`: اگر تأیید نشد و rollback پیشنهاد شود → audit log ثبت می‌شود
  - ری‌ترای خودکار (حداکثر ۳ بار) + HTTP timeout ۱۵ ثانیه
  - اتصال به `AutoPublish::publish()` — بعد از execute → verify → audit
  - تست‌ها: ۳ تست واحد سبز
  - **کل تستهای Automation**: ۱۱۲/۱۱۲ PASS (۳۹۶ assertion)
- **راهکار ۲ — کرالر هوشمند داخلی ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۹):**
  - `SiteSpiderService.php` — کرالر BFS با تحلیل HTML (title, meta, headings, images, schemas, links)
  - `SpiderRunJob.php` — Job شبانه (هر شب ۰۳:۰۰) + schedule در console.php
  - Migration `2026_09_08_000009` — جداول `static_opportunities` + `static_risk_patterns`
  - Migration `2026_09_09_000001` — اصلاح unique constraint (شامل `keyword_suggested`)
  - ۵ الگوی فرصت‌یابی: کلیدواژه H2 · محتوای کم‌عمق · متادیتای خالی · اسکیمای ناقص · تصاویر بدون Alt
  - ۴ الگوی ریسک: محتوای کم‌عمق · متادیتای خالی · تصاویر بدون Alt · H1 متعدد
  - تست زنده production (لیونا): **۵۰ صفحه · ۱۵۳ فرصت (۱۵۱ کلیدواژه + ۲ دسترسی) · ۲ ریسک · ۰ خطا · ۱۳۸ ثانیه** ✓
  - **بدون نیاز به GSC** — صرفاً با کرال HTML صفحات سایت
  - **اتصال به اکوسیستم ✅**: `OpportunityPipeline` از `static_opportunities` هم میخونه → فرصت‌های کرالر خودکار تبدیل به دسته تولید گروهی میشن
  - **گزارش مشتری ✅**: فرصت‌ها و ریسک‌های کرالر توی گزارش هفتگی نمایش داده میشه
  - **لایه ۲ — تحلیل عمیق ✅** (۲۰۲۶-۰۹-۰۹):
    - `SiteAnalysisService.php` — تحلیل گراف لینک + عمق صفحه + تازگی + تراکم کلمات کلیدی
    - تست زنده (لیونا): **۷۲۳ فرصت** (۲۸۷ یتیم + ۲۶۴ غیرقابل دسترس + ۱۹ تراکم پایین + ۱۵۱ H2 + ۲ Alt)
    - `SpiderRunJob` خودکار بعد از کرال اجرا میشه
  - **کرالر رقبا ✅** (۲۰۲۶-۰۹-۰۹):
    - `CompetitorSpiderService.php` — کرال سایت‌های رقیب + تحلیل ساختار محتوا
    - `CompetitorController.php` — CRUD رقبا + مقایسه
    - جداول `competitors` + `competitor_pages` + `competitor_keywords`
    - صفحه `/app/competitors` — مدیریت رقبا + جزئیات + مقایسه
    - شکاف‌های محتوایی خودکار → `static_opportunities`
  - **داشبورد بصری تحلیل کرالر ✅** (۲۰۲۶-۰۹-۰۹):
    - صفحه `/app/crawler-analysis` — نمودار + فیلتر + عملیات
    - API: `/api/crawler/analysis` + تغییر وضعیت + تبدیل به دسته تولید

## ❌ ناقص / بدهی فنی شناخته‌شده

| مورد | وضعیت | برنامه |
|---|---|---|
| دیتابیس پروداکشن SQLite است | ریسک یکپارچگی/همزمانی | مهاجرت به PostgreSQL (P1) |
| SSL/HTTPS با certbot | ⚠️ **شبکه سرور outbound HTTPS را مسدود کرده** — certbot/acme.sh/curl timeout می‌خورند (ولی openssl کار می‌کند) | نیاز به بررسی فایروال سرور توسط مالک (P0) |
| `APP_KEY` | ✅ **چرخش شد** (۲۰۲۶-۰۹-۰۸) — کلید قدیمی از `.env.local-backup` در تاریخچه گیت بود | `.env.local-backup` از git tracking حذف شد |
| جدول‌های موازی قالب پرامپت (`ai_prompt_templates` و `prompt_template`) | دو نسل هم‌زمان | ادغام (P2) |
| مایگریشن خالی `2026_08_21_162628` | پانسمان تاریخی؛ بی‌ضرر | حذف پس از تطبیق با دیتابیس پروداکشن |
| پرداخت (زرین‌پال/عقربه) و اشتراک | اسکلت + تست محدود | پشت feature-flag تا تکمیل تست E2E (P2) |
| ~~پلاگین dist اوبفاسکیت‌شده~~ | ✅ حل شد — بیلد خوانا از CI (`plugin-build.yml`) | — |

## 🧪 QA پیش از دیپلوی (۲۰۲۶-۰۸-۲۶ — v1.0.0-rc1)

| بررسی | نتیجه |
|---|---|
| سویت کامل PHPUnit | ✅ ۴۰۰/۴۰۰ (۲۰۶۰ assertion) |
| جاروی صفحات (هر GET محصول با کاربر واقعی + دادهٔ دمو) | ✅ ۳۰+ صفحه، صفر ۵۰۰ — `PageSmokeSweepTest` |
| وجود کامپوننت Vue برای ۷۲ صفحهٔ رندرشده | ✅ صفر گمشده |
| جورنی پول کامل (ثبت‌نام تا انتشار وردپرس) | ✅ `MoneyPathTest` |
| جورنی پرتال مشتری | ✅ smoke + ClientDecisionTest |
| جورنی اعلان‌ها (لید → نوتیفیکیشن → مرکز اعلان) | ✅ `NotificationJourneyTest` |
| پلاگین وردپرس (dist واقعی: guard/HMAC/replay/RankMath/Yoast) | ✅ ۳۰/۳۰ |
| ایزولاسیون چند-مستأجری | ✅ ۱۹ سناریو |
| RTL + وزیرمتن + بدون lorem | ✅ |

## 🔍 چطور وضعیت را خودتان راستی‌آزمایی کنید

```bash
composer install && npm ci && npm run build
php artisan test          # باید 369/369 سبز باشد
vendor/bin/pint --test    # PASS
npm run lint && npm run typecheck   # هر دو بدون خطا
```

> ران‌بوک اجرای کارهای سروری (کلید، SSL، cron، PostgreSQL، بکاپ): سند ۴۹.

هر تغییری که یکی از این‌ها را قرمز کند، «مسیر پول» یا کیفیت را شکسته — تا سبز نشود merge نشود.
تست `tests/Feature/E2E/MoneyPathTest.php` کل زنجیرهٔ ارزش (ثبت‌نام تا انتشار وردپرس) را در یک تست محافظت می‌کند.
محیط دمو: `php artisan demo:seed --fresh` → `demo@visionprime.test` / `DemoAdmin2024!Secure#` (تست دود: DemoEnvironmentSmokeTest).
