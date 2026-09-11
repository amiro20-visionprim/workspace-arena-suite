# ۵۲ — نقشهٔ پلن جدید: اکوسیستم تصمیم‌گیر/تصمیم‌ساز خودکار

> سند وضعیت: **فعال از ۲۰۲۶-۰۹-۰۸** — پس از تکمیل فاز ۵۰ (IL1–IL5) و P2.5–P2.9.
> این سند «نقشهٔ راه اجرایی» است؛ وضعیت واقعی هر قدم در STATUS.md ثبت می‌شود.

---

## 🎯 چشمانداز

سیستم باید یک **حلقهٔ تصمیم بسته** باشد:

```
حس‌کردن → تحلیل → تصمیم → اجرا → اندازه‌گیری → یادگیری → (برگشت به تحلیل)
```

| مرحله | وضعیت در شروع پلن | ابزار |
|---|---|---|
| ۱. حس‌کردن | ⚠️ موتور ساخته شده، خاموش بود | GSC import + سینک وردپرس |
| ۲. تحلیل | ✅ | Opportunities / Money Pages / Conversion Risks / SERP |
| ۳. تصمیم | 🟡 همه‌چیز «پیشنهاد» می‌دهد | گیت‌های کیفیت + PolicyEvaluator + D-013 |
| ۴. اجرا | ✅ قوی‌ترین بخش | تولید گروهی + انتشار خودکار + تقویم |
| ۵. اندازه‌گیری | ⚠️ بدون cron هیچ داده‌ای نمی‌آمد | Impact events + GSC metrics |
| ۶. یادگیری | ⚠️ معلق — محاسبه می‌کند ولی وصل نیست | `LearningLoop` |

---

## 🗺️ قدم‌های پلن (به ترتیب اولویت)

### قدم ۱ (🔴) — فعال‌سازی ضربان‌قلب: cron + Supervisor
**وضعیت: ✅ تکمیل و تأیید شد (۲۰۲۶-۰۹-۰۸)**

| آیتم | وضعیت |
|---|---|
| crontab `schedule:run` هر دقیقه | ✅ فعال بود — تأیید شد |
| Supervisor `visionprime-scheduler` | ✅ RUNNING (حلقه ۶۰ ثانیه) |
| Supervisor `visionprime-queue` (redis) | ✅ RUNNING (`queue:work redis --sleep=3`) |
| `content:bulk-schedule` در schedule | ✅ **اضافه شد** (هر ۵ دقیقه، بدون تداخل) — قبلاً تعریف نشده بود و زمان‌بندی گروهی خاموش بود |
| باگ `CollectPlatformEvents` | ✅ **فیکس شد** — ستون گمشدهٔ `resolved_at` در `review_items` (migration `2026_09_08_000005`) — هر روز fail می‌شد |
| `gsc:import` | ✅ اجرا می‌شود؛ «هیچ ملک GSC متصلی نیست» (طبیعی — اتصال GSC هنوز ست نشده) |
| `failed_jobs` | ✅ صفر باقی‌مانده پس از فیکس |

### قدم ۲ (🔴) — اتصال LearningLoop به تصمیم‌گیری (Adaptive)
**وضعیت: ✅ تکمیل و تأیید شد (۲۰۲۶-۰۹-۰۸) — تست زنده production: ۷/۷ PASS**

| آیتم | وضعیت |
|---|---|
| سرویس `AdaptiveLearning` (isBlocked / blockReason / health / shouldBlock) | ✅ ساخته شد — `app/Domains/Automation/Services/AdaptiveLearning.php` |
| ستون‌های جدید `automation_learning_history` (consecutive_failures / blocked / blocked_reason / blocked_at / last_status) | ✅ migration `2026_09_08_000006` روی production اجرا شد |
| `LearningLoop` — محاسبهٔ شکست‌های متوالی + فلگ blocked با دلیل فارسی + auto-heal | ✅ بازنویسی شد (باگ کوئری مشترک هم فیکس شد) |
| گیت پیشنهاد: `CreateRiskRecommendations` — ریسک‌های نوع مسدود دیگر پیشنهاد نمی‌شوند (خروجی suppressed) | ✅ ساخته شد |
| گیت پیشنهاد: `RecommendationController::fromOpportunity` — فرصت‌های منجر به نوع مسدود رد می‌شوند | ✅ ساخته شد |
| گیت اجرا (خط دفاعی دوم): `ConvertRecommendationToCommand` — تبدیل نوع مسدود با پیام فارسی متوقف می‌شود + audit `command.blocked_by_learning` | ✅ ساخته شد |
| فاکتور `learning_blocked` در `CommandConfidenceAssessor` | ✅ اضافه شد (شفافیت در confidence_factors) |
| `RunGrowthAnalysis` — خروجی `suppressed_recommendations` برای شفافیت | ✅ اضافه شد |

**آستانه‌های مسدودیت (AdaptiveLearning::shouldBlock):**
- ۳ شکست متوالی (`MAX_CONSECUTIVE_FAILURES`) → مسدود فوری
- یا نرخ موفقیت < ۵۰٪ با حداقل ۳ نمونه (`MIN_SAMPLE`, `SUCCESS_RATE_FLOOR`)
- **Auto-heal:** ۲ موفقیت متوالی بعد از مسدودیت → برداشته می‌شود
- جداسازی کامل به‌ازای (سایت، نوع دستور) — مسدودیت یک سایت به سایت دیگر سرایت نمی‌کند

**تست زنده production (پراب ۷ مرحله‌ای):** نرخ/شکست متوالی ✅ · blocked ✅ · blockReason فارسی ✅ · جداسازی نوع ✅ · رد تبدیل مسدود ✅ · suppress ریسک ✅ · auto-heal ✅ · مسدودیت مجدد ✅

### قدم ۳ (🟡) — خودمختاری پلکانی (T1/T2/T3)
**وضعیت: ✅ تکمیل و تأیید شد (۲۰۲۶-۰۹-۰۸) — تست زنده production: ۱۲/۱۲ PASS**

| آیتم | وضعیت |
|---|---|
| Migration `2026_09_08_000007` — ستون `autonomy_tier` (manual/t1/t2/t3) | ✅ اجرا شد |
| `PolicyEvaluator` — منطق پلکانی T1/T2/T3 با آستانه‌های اعتماد | ✅ ساخته شد |
| `AutoPublish` — خواندن tier + اطلاع‌رسانی بعد از انتشار خودکار | ✅ ساخته شد |
| `NotifyAutoPublishTeam` + `AutoPublishNotification` | ✅ ساخته شد |
| `AutomationPolicyController` — اعتبارسنجی و ذخیره tier | ✅ آپدیت شد |
| UI — سلکتور سطح خودمختاری با توضیح فارسی | ✅ آپدیت شد |

**رفتار سطوح:**
- **manual**: همهچیز تأیید انسانی (backward-compat با L1 قدیمی)
- **T1 (کم‌خطر)**: متا + تگ + اصلاحات نگارشی خودکار (confidence ≥ ۶۰٪)
- **T2 (متوسط)**: + تولید محتوا خودکار (گیت کیفیت + گرمایش ۲ اجرا موفق)
- **T3 (پرخطر)**: + به‌روزرسانی محتوای منتشرشده خودکار (R3 ساختاری همیشه انسانی)

**تست زنده production (پراب ۱۲ مرحله‌ای):** T1 متا بالا ✅ · T1 متا پایین ✅ · T1 مقاله جدید ✅ · T1 R3 ساختاری ✅ · T1 fail-closed ✅ · T2 کیفیت+گرمایش ✅ · T2 بدون گرمایش ✅ · T2 کیفیت رد ✅ · T3 به‌روزرسانی ✅ · T3 حذف R3 ✅ · manual backward-compat ✅ · PolicyEvaluator عمومی ✅

### قدم ۴ (🟡) — OpportunityPipeline (حلقهٔ بسته فرصت → انتشار)
**وضعیت: ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸) — تست زنده production: موفق**

| آیتم | وضعیت |
|---|---|
| `OpportunityPipeline` سرویس اصلی | ✅ ساخته شد |
| `OpportunityPipelineJob` (Laravel Job) | ✅ ساخته شد |
| `RunOpportunityPipeline` (کنسول کامند) | ✅ ساخته شد |
| Schedule (هر ۳۰ دقیقه) | ✅ اضافه شد به `console.php` |

**جریان:** `opportunities (status=open)` → اولویت‌بندی بر اساس score → `BulkJob + BulkJobItems` → `BulkContentService::processItem` (تولید + گیت کیفیت + انتشار خودکار)

**ویژگی‌ها:** idempotent (هر فرصت فقط یک‌بار)، خودمختاری tier-aware (auto_publish بر اساس T1/T2/T3)، حداکثر ۱۰ آیتم در هر اجرا، حداقل امتیاز ۳۰

**تست زنده production:** ۳ فرصت test → ۳ دسته خودکار ساخته شد ✓

```
فرصت → تولید خودکار → گیت کیفیت → زمان‌بندی → انتشار → اندازه‌گیری → گزارش
(✅)      (✅ BulkService)   (✅ Guard)    (✅ Schedule)  (✅ Publish)  (⬜ بعدی)   (⬜ بعدی)
```

### قدم ۵ (🟢) — گزارش خودکار مشتری + شفافیت (تکمیل Phase 11)
**وضعیت: ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸) — تست زنده production: موفق**

| آیتم | وضعیت |
|---|---|
| `BuildClientWeeklyReport` — جمع‌آوری دادهٔ هفتگی مشتری | ✅ ساخته شد |
| `SendClientWeeklyReports` — Job ارسال گزارش به هر مشتری | ✅ ساخته شد |
| Schedule (هر شنبه صبح ۰۹:۰۰) | ✅ اضافه شد |

**محتوای گزارش:** تولیدات محتوا + انتشارها + فرصت‌ها + ریسک‌ها + روند GSC + اتوماسیون + نقاط عطف

**تست زنده production:** لیونا بیوتی (80 محتوا، کیفیت 75.9، 76 دستور اجرا، 18 خودکار) + مشتری دمو (گزارش خالی) ✓

### قدم ۶ (🟢) — امنیت باقی‌مانده
- SSL روی دامنه، rotate APP_KEY، پاکسازی تاریخچهٔ گیت.
- وضعیت: ⬜ شروع نشده (دستی — نیازمند مالک دامنه).

---

## 🔄 تکمیل حلقه — اندازهگیری خودکار تأثیر
**وضعیت: ✅ تکمیل و دیپلوی شد (۲۰۲۶-۰۹-۰۸)**

| آیتم | وضعیت |
|---|---|
| `MeasurePublishImpact` Job | ✅ ساخته شد |
| Schedule (هر روز ۰۶:۳۰ — بعد از import GSC) | ✅ اضافه شد |

**رفتار:** کامندهای publish_new_article اجراشده که ≥۲ روز از انتشارشان گذشته و impact event تازه ندارند → `BuildPublishImpactReport` → ذخیره/آپدیت `impact_events` + هشدار خودکار افت معیارها

**حلقهٔ تصمیم بسته کامل شد:**
```
حس‌کردن → تحلیل → تصمیم → اجرا → اندازه‌گیری → یادگیری → گزارش → (برگشت)
   ✅         ✅       ✅      ✅        ✅           ✅        ✅
```

---

## 🧪 تست زندهٔ محصول روی ووکامرس (پیش‌نیاز قدم‌ها — ۲۰۲۶-۰۹-۰۸)

جورنی کامل با اکانت ارگ لیونا اجرا و تأیید شد:

| مرحله | نتیجه |
|---|---|
| تولید محصول «کرم ضد آفتاب صورت SPF 50» (rule-based, long_desc) | ✅ draft ذخیره، بدون H1، اسکیمای Product |
| پیشنهاد دسته با product_cats واقعی | ✅ «ضد آفتاب» ۱۰۰٪ (confidence high) |
| انتشار روی ووکامرس | ✅ پست #6910 و #6911 با `post_type=product` |
| دستهٔ ووکامرس | ✅ product_cat «ضد آفتاب» (term 1951) روی پست اعمال شد |
| تگ‌ها | ✅ ۶ تگ product_tag ساخته و متصل شد |
| اسکیمای Product | ✅ JSON-LD زنده روی صفحه با متا تمیز |
| Rank Math | ✅ meta_title + focus_keyword + **meta_description** (پس از فیکس ۱.۴.۵) |

### باگ‌های پیدا و رفع‌شده در این تست

| # | باگ | فیکس |
|---|---|---|
| ۱ | تشخیص محصول در `PublishDraftThroughConnector` با `subtype === 'product'` — هرگز match نمی‌شد → گیت واژه ۸۰۰ مقاله + `content_type=article` | ✅ استفاده از `ContentProfiler::SUBTYPES['product']` |
| ۲ | متا دیسکریپشن با «مقدمه» چسبیده یا متن جدول مشخصات شروع می‌شد | ✅ `deriveMetaDescription()` — حذف هدینگ/جدول، جملهٔ اول پاراگراف واقعی، ۱۵۵ کاراکتر |
| ۳ | ساخت دستهٔ محصول در taxonomy اشتباه `category` — ووکامرس به‌جای آن دستهٔ پیش‌فرض می‌گذاشت | ✅ پارامتر `taxonomy=product_cat` در بک‌اند + فرانت |
| ۴ | پلاگین ۱.۴.۴ `meta_description` را هرگز نمی‌نوشت (فقط title) | ✅ پلاگین ۱.۴.۵ — نوشتن description در publish_new_article + فیکس دستی پست‌های موجود |
| ۵ | `CollectPlatformEvents` fail روزانه (ستون resolved_at گمشده) | ✅ migration + اجرا روی production |

---

## 🔗 پیوندها

- وضعیت زنده: `STATUS.md`
- فاز ۵۰ (لینک/گروهی): `50-In-Content-Linking-and-Bulk-Readiness-Plan.md`
- اسپک انتشار گروهی: `51-Bulk-Publish-System-Spec.md`
- ران‌بوک سرور: `49-Production-Runbook.md`