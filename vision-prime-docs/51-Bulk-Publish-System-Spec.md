# ۵۱ — مشخصات فنی: انتشار گروهی (Bulk Publish) — P2.6 و P2.7

**تاریخ ایجاد:** ۲۰۲۶-۰۹-۰۸
**وضعیت:** فعال — پیاده‌سازی‌شده و روی production دیپلوی شده
**مرجع پلن:** سند ۵۰ (فاز P2.5/P2.6/P2.7/P2.8) · سند ۴۲ (Content Generation System Spec)

---

## ۱) نمای کلی

این سیستم به «تولید گروهی محتوا» (P2.5) قابلیت **انتشار به وردپرس** می‌دهد، با دو حالت:

| حالت | توضیح |
|---|---|
| **انتشار دستی (P2.6)** | بعد از تولید، هر آیتم یا کل دسته با دکمهٔ UI منتشر می‌شود؛ گیت کیفیت قبل از هر انتشار اجرا می‌شود |
| **انتشار خودکار (P2.7)** | دسته با `auto_publish=draft|publish` ساخته می‌شود؛ آیتم‌هایی که از گیت کیفیت عبور می‌کنند **بلافاصله** به وردپرس ارسال می‌شوند؛ آیتم‌های ردشده در صف بازبینی می‌مانند |
| **بازطراحی (P2.8)** | آیتم‌های needs_review/failed با دکمهٔ «🔄 بازتولید» (یا پیشنهاد long-tail قابل‌کلیک) به pending برمی‌گردند و با عنوان جدید دوباره تولید می‌شوند |
| **زمان‌بندی (P2.8)** | دسته با `scheduled_at` زمان‌بندی می‌شود (کرون `content:bulk-schedule`) و با `daily_publish_limit` سقف انتشار روزانه می‌گیرد |

**اصل طراحی:** هیچ آیتمی بدون عبور از گیت‌های کیفیت به وردپرس نمی‌رود — چه دستی چه خودکار. «رد شدن» هرگز آیتم را حذف نمی‌کند؛ فقط وضعیت انتشار را `failed` می‌کند تا قابل بازبینی/ری‌ترای باشد.

---

## ۲) معماری

```
┌─────────────────────────────────────────────────────────────┐
│  Frontend (BulkContent.vue)                                  │
│  - فرم ساخت: auto_publish (off|draft|publish)                │
│  - دکمه انتشار تکی / «انتشار همهٔ آماده‌ها»                    │
│  - کارت آمار (P2.7): منتشرشده / ردشده / صف بازبینی            │
│  - فیلتر آیتم‌ها بر اساس وضعیت انتشار                         │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTP
┌──────────────────────────▼──────────────────────────────────┐
│  BulkContentController (routes/web.php)                      │
│  POST /api/bulk-content/jobs        → ساخت دسته (auto_publish)│
│  POST /api/bulk-content/items/{id}/publish  → انتشار تکی     │
│  POST /api/bulk-content/jobs/{id}/publish  → انتشار گروهی    │
│  GET  /api/bulk-content/stats       → کارت آمار (P2.7)       │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  BulkContentService                                         │
│  processItem(): تولید → گیت کیفیت → draft                    │
│      └─ اگر auto_publish فعال و گیت پاس شد → publishItem()   │
│  publishItem(): گیت‌ها → PublishDraftThroughConnector        │
│  publishJob():  حلقه روی آیتم‌های آماده                      │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  PublishDraftThroughConnector (گیت کیفیت نهایی)              │
│  ۱) گیت IL3.1: رول‌بیس بدون فلگ → رد                        │
│  ۲) گیت IL3.4: حداقل واژه (۸۰۰ مقاله / ۲۵۰ محصول) → رد      │
│  ۳) اتصال site_connections (جفت‌سازی HMAC) → رد اگر نباشد    │
│  ۴) ساخت command از نوع publish_new_article (امضاشده)        │
│  ۵) ExecuteCommand → HTTP به پلاگین وردپرس                   │
└──────────────────────────┬──────────────────────────────────┘
                           │
                    command_execution_logs
                    (پاسخ پلاگین → استخراج post_id واقعی)
```

---

## ۳) مدل داده

### `bulk_jobs` (P2.7 — ستون جدید)

| ستون | نوع | توضیح |
|---|---|---|
| `auto_publish` | string(10) default `off` | `off` (فقط تولید) · `draft` (پیش‌نویس وردپرس) · `publish` (منتشر مستقیم) |

### `bulk_job_items` (P2.6 — ستون‌های جدید)

| ستون | نوع | توضیح |
|---|---|---|
| `publish_status` | string(20) nullable | `null`/`pending` (آماده) · `publishing` (در حال ارسال) · `published` · `failed` |
| `post_id` | unsignedBigInteger nullable | شناسه پست واقعی وردپرس (از پاسخ پلاگین) |
| `published_at` | timestamp nullable | زمان انتشار موفق |
| `publish_error` | text nullable | پیام خطای گیت/ارسال |

**نکته:** `status` آیتم (`completed`/`needs_review`/`failed`) مربوط به **تولید** است و `publish_status` مربوط به **انتشار**. این دو مستقل‌اند: یک آیتم می‌تواند `completed` (تولید موفق) ولی `publish_status=failed` (گیت انتشار ردش) باشد.

---

## ۴) جریان داده انتشار (Data Flow)

### ۴.۱ انتشار دستی (P2.6)

```
کاربر → POST /api/bulk-content/items/{id}/publish {status: draft|publish}
  → publishItem():
      - اگر publish_status=published → skip (idempotent)
      - publish_status ← publishing
      - draft = ContentDraft::find(item.draft_id) — اگر null → گیت «پیش‌نویس یافت نشد»
      - PublishDraftThroughConnector::handle(draft, status)
          ├─ گیت رول‌بیس (IL3.1)
          ├─ گیت حداقل واژه (IL3.4)
          ├─ گیت اتصال (site_connections)
          └─ ارسال command امضاشده → پلاگین → پست وردپرس
      - موفق: post_id از command_execution_logs استخراج می‌شود
              publish_status ← published · post_id · published_at
      - ناموفق: publish_status ← failed · publish_error ← پیام گیت
```

### ۴.۲ انتشار خودکار (P2.7)

```
ساخت دسته با auto_publish=draft|publish
  → worker (content:bulk-run) → processItem(item):
      - تولید محتوا + لینک درون‌متنی + گیت‌های کیفیت (IL5، واژه، امتیاز)
      - needs_review? → آیتم در صف بازبینی می‌ماند؛ **بدون تلاش انتشار** ✅
      - completed?  و auto_publish فعال → publishItem(item, auto_publish)
          ├─ موفق → publish_status=published · post_id ثبت می‌شود
          └─ ناموفق → publish_status=failed · publish_error ثبت می‌شود
                      (آیتم همچنان completed است — قابل انتشار دستی)
```

### ۴.۳ بازیابی post_id

`resolvePostId(commandId)` پاسخ خام پلاگین را از `command_execution_logs.response_redacted` می‌خواند:

```json
{"callback": true, "result": {"post_id": 6908, "created": true, "new_title": "..."}, "error": null}
```

مسیرهای جستجو: `body.result.post_id` ← `result.post_id`. اگر یافت نشد، `post_id=null` (انتشار موفق ولی بدون شناسه — وضعیت پست از پنل وردپرس قابل مشاهده است).

---

## ۵) گیت‌های کیفیت (ترتیب اجرا)

| # | گیت | مکان | پیام رد |
|---|---|---|---|
| ۱ | عنوان تک‌کلمه‌ای رقابتی (IL5) | `processItem` | «این عنوان تک‌کلمه‌ای بسیار رقابتی است... پیشنهاد long-tail» |
| ۲ | خروجی رول‌بیس (IL3.1) | `PublishDraftThroughConnector` | «خروجی نمونه (رول‌بیس)... باید با VisionPrime AI تولید مجدد شود» — با `CONTENT_ALLOW_RULEBASED_PUBLISH=1` باز می‌شود |
| ۳ | حداقل واژه (IL3.4) | `PublishDraftThroughConnector` | «فقط N واژه دارد (حداقل مجاز: ۸۰۰)» |
| ۴ | اتصال جفت‌شده (pairing) | `PublishDraftThroughConnector` | «پلاگین وردپرس برای این سایت جفت نشده است» |
| ۵ | کیفیت محتوا (score < ۵۰) | `processItem` | `needs_review` (بدون انتشار) |

---

## ۶) API ها

| متد | مسیر | ورودی | خروجی |
|---|---|---|---|
| POST | `/api/bulk-content/jobs` | `site_id`, `keywords[]`, `auto_publish?` (off/draft/publish), ... | `{id, status, total_items, auto_publish}` |
| GET | `/api/bulk-content/jobs` | — | لیست دسته‌ها (با `auto_publish`) |
| GET | `/api/bulk-content/jobs/{id}` | — | جزئیات + آیتم‌ها (با `publish_status`, `post_id`, `publish_error`) |
| POST | `/api/bulk-content/jobs/{id}/run` | — | شروع worker |
| GET | `/api/bulk-content/jobs/{id}/status` | — | پولینگ پیشرفت |
| POST | `/api/bulk-content/items/{id}/publish` | `status?` (publish/draft/pending) | `{success, publish_status, post_id?, error?}` — 422 اگر گیت رد کند |
| POST | `/api/bulk-content/jobs/{id}/publish` | `status?` | `{published, failed, skipped, results[]}` |
| GET | `/api/bulk-content/stats` | — | `{published, publish_failed, review_queue, recent_published[]}` |

---

## ۷) عیب‌یابی (Troubleshooting)

### نشانه: `publish_status=failed` و `publish_error` مشخص

| خطا | علت | راه‌حل |
|---|---|---|
| «پیش‌نویس این آیتم یافت نشد» | آیتم پردازش نشده یا draft حذف شده | `content:bulk-run --job={id}` را اجرا کنید |
| «خروجی نمونه (رول‌بیس) است» | گیت IL3.1 — خروجی رول‌بیس | با VisionPrime AI تولید مجدد کنید یا در حالت تست فلگ `CONTENT_ALLOW_RULEBASED_PUBLISH=1` را بگذارید |
| «فقط N واژه دارد» | گیت IL3.4 — زیر حداقل واژه | مقاله را کامل‌تر کنید (۸۰۰+ واژه) |
| «پلاگین وردپرس برای این سایت جفت نشده است» | اتصال HMAC برقرار نیست | صفحهٔ «اتصال» سایت → pairing با توکن |
| «وردپرس با خطای 4xx/5xx پاسخ داد» | پلاگین خطا داد یا آفلاین است | لاگ `command_execution_logs` را ببینید؛ پلاگین را آپدیت کنید |

### نشانه: `publish_status=published` ولی `post_id=null`

پاسخ پلاگین ساختار متفاوتی داشت. پست در وردپرس ساخته شده — از پنل وردپرس یا `command_execution_logs.response_redacted` پیدا کنید.

### نشانه: انتشار خودکار هیچ آیتمی را منتشر نکرد

1. `auto_publish` دسته را چک کنید (`off` = فقط دستی)
2. وضعیت آیتم‌ها: اگر همه `needs_review` هستند، گیت‌های کیفیت (IL5/واژه/امتیاز) رد کرده‌اند — آیتم‌های ردشده **باید** در صف بازبینی بمانند (طراحی عمدی)
3. فلگ `CONTENT_ALLOW_RULEBASED_PUBLISH` — اگر `0` باشد خروجی رول‌بیس هرگز منتشر نمی‌شود

### نشانه: `publish_status=published` ولی پست در وردپرس دیده نمی‌شود

- `status` ارسال‌شده را چک کنید: `draft` = پیش‌نویس (در پنل وردپرس → پیش‌نویس‌ها) · `publish` = منتشر
- REST عمومی وردپرس لیونا پشت فایروال است (403) — بررسی مستقیم از پنل وردپرس انجام دهید

---

## ۸) تست‌های زنده ثبت‌شده (production)

| تاریخ | سناریو | نتیجه |
|---|---|---|
| ۲۰۲۶-۰۹-۰۸ | انتشار دستی: «راهنمای کامل ماسک مو» (draft) | ✅ پست #6907 — پاسخ پلاگین `{"post_id":6907,"created":true}` |
| ۲۰۲۶-۰۹-۰۸ | گیت IL5: «کرم» تک‌کلمه‌ای | ✅ needs_review — بدون انتشار |
| ۲۰۲۶-۰۹-۰۸ | گیت‌ها: آیتم بدون پیش‌نویس / پیش‌نویس ۶ واژه‌ای | ✅ هر دو رد شدند |
| ۲۰۲۶-۰۹-۰۸ | انتشار خودکار (P2.7): «راهنمای کامل انتخاب شامپو مناسب» با `auto_publish=draft` | ✅ **پست #6908 خودکار ساخته شد** — پاسخ پلاگین `{"post_id":6908,"created":true}` |
| ۲۰۲۶-۰۹-۰۸ | انتشار خودکار + گیت IL5: «مکمل» | ✅ needs_review — بدون تلاش انتشار |
| ۲۰۲۶-۰۹-۰۸ | UI (لوکال): دکمه انتشار روی سایت بدون اتصال | ✅ گیت اتصال رد + بج «رد شد» + پیام واضح |

---

## ۹) فایل‌های مرتبط (P2.8)

| فایل | نقش |
|---|---|
| `app/Domains/Content/Services/BulkContentService.php` | موتور: processItem + publishItem + publishJob + resolvePostId |
| `app/Http/Controllers/App/BulkContentController.php` | API + صفحه + آمار |
| `app/Domains/Content/Models/BulkJob.php` / `BulkJobItem.php` | مدل‌ها (auto_publish + publish_status) |
| `app/Domains/Connector/Actions/PublishDraftThroughConnector.php` | گیت‌های نهایی + ارسال امضاشده |
| `resources/js/Pages/App/BulkContent.vue` | داشبورد: فرم (auto_publish) + دکمه‌های انتشار + کارت آمار + فیلتر |
| `database/migrations/2026_09_08_000002_add_publish_columns_to_bulk_job_items.php` | ستون‌های انتشار آیتم |
| `database/migrations/2026_09_08_000003_add_auto_publish_to_bulk_jobs.php` | ستون auto_publish دسته |
| `database/migrations/2026_09_08_000004_add_schedule_columns_to_bulk_jobs.php` | ستون‌های scheduled_at + daily_publish_limit (P2.8) |
| `app/Console/Commands/RunScheduledBulkJobs.php` | زمان‌بند کرون (P2.8) |
| `resources/js/app/components/AppNavigation.vue` | جایگاه منو + آیکون (P2.8) |

---

## ۱۰) P2.8 — جریان بازطراحی و زمان‌بندی

### ۱۰.۱ بازطراحی آیتم ناقص

```
POST /api/bulk-content/items/{id}/rework  {title?: "راهنمای جامع کرم"}
  → reworkItem():
      - فقط needs_review/failed قابل بازطراحی است (completed → رد)
      - حذف پیش‌نویس قبلی
      - کاهش شمارنده‌های دسته (needs_review_items/failed_items)
      - ریست آیتم: title جدید · status=pending · publish_status=null · خطاها پاک
      - دسته به pending برمی‌گردد (قابل اجرای مجدد)
  → کاربر «شروع پردازش» را می‌زند → آیتم از کل pipeline دوباره می‌گذرد
```

در UI: آیتم‌های needs_review با خطای IL5، پیشنهادهای long-tail را به‌صورت دکمه‌های قابل‌کلیک نشان می‌دهند (استخراج از `error` با الگوی «پیشنهادها: …»).

### ۱۰.۲ زمان‌بندی شروع (scheduled_at)

```
ساخت دسته با scheduled_at → worker و run() قبل از زمان مقرر رد می‌کنند
کرون:  * * * * * php artisan content:bulk-schedule
  → دسته‌های pending با scheduled_at رسیده را spawn می‌کند (هر دسته یک بار — چک started_at)
```

### ۱۰.۳ سقف انتشار روزانه (daily_publish_limit)

```
processItem: اگر auto_publish فعال و daily_publish_limit > 0:
    publishedToday(site_id) >= limit؟ → فقط تولید، publish نمی‌شود (در صف می‌ماند)
    (پیش‌نویس ذخیره می‌شود؛ publish_status=null — قابل انتشار دستی)
```

### ۱۰.۴ گزارش کیفیت (quality-report)

`GET /api/bulk-content/quality-report` — گروه‌بندی هوشمند دلایل رد:
- عنوان تک‌کلمه‌ای رقابتی (نیاز به long-tail)
- کم‌بودن تعداد واژه (زیر حداقل گیت)
- وردپرس جفت نشده (نیاز به اتصال)
- خروجی نمونه (رول‌بیس) بدون تأیید
- پیش‌نویس موجود نیست (پردازش نشده)

+ پیشنهادهای بهبود مبتنی بر داده (نرخ انتشار پایین → long-tail؛ امتیاز پایین → زیرنوع و…)

---

## ۱۱) محدودیت‌ها و نکات

- **ایمپوتنسی:** انتشار دوبارهٔ آیتم `published` بدون عملیات رد می‌شود (`already_published=true`)
- **پلاگین endpoint حذف پست ندارد** — پست‌های تستی باید از پنل وردپرس حذف شوند
- **REST عمومی وردپرس لیونا پشت فایروال است (403)** — بررسی پست از پنل وردپرس انجام می‌شود
- انتشار خودکار برای آیتم‌های `needs_review` **هرگز** اجرا نمی‌شود (طراحی عمدی — حفاظت از توکن و کیفیت)