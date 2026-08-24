# 🔬 فاز ۱ — نقدِ ریزبینانهٔ کد: احراز هویت، هویت و زیرساختِ هسته (Identity / Auth / Platform Foundation)

**روش بررسی:** خواندنِ خطبهخطِ کدِ واقعی (`routes/web.php`، کنترلرهای Auth، مدل User، مایگریشنها، میدلورها، سرویس OTP) — نه داکیومنت.
**حاضرین نقد:** آرش (PM)، سارا (بکاند)، رضا (امنیت)، لیلا (جورنی)، پویا (فروش).
**حکم کلی:** معماری تمیز و بالغ است (Modular Monolith واقعی، audit، redaction، hash_equals، session regeneration). اما **مهمترین فلوهای ورودِ مشتری، در لایهٔ اتصال (Wiring) خاموش و شکستهاند.** مشکل از جنس «سرویس ساخته شده ولی کنترلر استاب شده».

---

## 🔴 یافتههای بحرانی (CRITICAL) — اینها مانعِ فروشاند

### C1 — ثبتنام عملاً غیرممکن است (شکستگی end-to-end)
**شواهد کد:**
- `OtpRegisterController@request` → `return response()->json(['message' => 'OTP registration is not available yet.'], 501);` — استابِ خالی.
- `Register.vue` (خط ۵۳) برای دریافت کد، `POST /register/otp` میزند و به `data.code` وابسته است.
- `RegisterController@store` → `app(OtpService::class)->verify($phone, $otp_code, 'register')` — ولی چون کد هرگز صادر و ذخیره نشده، `verify` **همیشه false** برمیگردد.

**نتیجه:** هیچ کاربر جدیدی نمیتواند ثبتنام کند. اولین قدمِ فانل (onboarding) از کار افتاده است. این در `FIELD_TEST_REPORT.md` با «ثبتنام/ورود/خروج ✅ 100%» ثبت شده — اما تست فقط صفحهٔ لاگین و ورود با حسابِ ازپیشساخته را سنجیده، نه ثبتنامِ واقعی با OTP.
**ریشه:** `OtpService` کاملاً آماده و درست است؛ فقط `OtpRegisterController` آن را صدا نمیزند. یک «اتصال فراموششده».

### C2 — ورودِ OTP نیز استاب است
- `OtpLoginController@request` و `@verify` هر دو `501` برمیگردانند.
- روتهای `login.otp` و `login.otp.verify` در `web.php` وجود دارند و throttle هم دارند، اما هیچ کاری نمیکنند.
- اگر UI جایی «ورود با کد یکبارمصرف» را تبلیغ کند، کاربر به بنبست میخورد.

### C3 — بازیابی رمز، ایمیلش به جایی نمیرسد
- `PasswordResetLinkController@store` درست است، ولی `MAIL_MAILER=log` (طبق گزارش میدانی). یعنی لینکِ بازیابی در لاگ نوشته میشود و کاربر **هرگز ایمیلی دریافت نمیکند.**
- پیامِ موفقیتِ کاذب: «اگر حسابی با این ایمیل وجود داشته باشد، لینک ارسال میشود» — کاربر فکر میکند ایمیل رفته، ولی نرفته.

> **نتیجهٔ تجاری مشترک (پویا + آرش):** سه درگاهِ ورودِ مشتری (ثبتنام، ورود OTP، بازیابی رمز) یا شکستهاند یا بیاثر. یعنی **حتی یک مشتری هم نمیتواند بهصورت خودکار onboard شود.** این بالاترین اولویتِ اصلاح است، جلوتر از هر قابلیت جدید.

---

## 🟠 یافتههای بالا (HIGH)

### H1 — ایمیل اصلاً تأیید نمیشود (بدون email verification)
- `// use Illuminate\Contracts\Auth\MustVerifyEmail;` کامنت شده.
- `email_verified_at` نه در `$fillable` است و نه جایی ست میشود.
- ثبتنام فقط `phone_verified_at` را ست میکند. یعنی یک ایمیلِ تایپو یا غیرواقعی، بدون هیچ چک، «ایمیل اصلیِ» حساب میشود و همهٔ اطلاعرسانیهای آینده (که هنوز log-only هم هست) به آن وابستهاند.

### H2 — هیچ audit برای «ورود ناموفق» وجود ندارد
- `AuthenticatedSessionController@store` فقط موفقیت را audit میکند (`auth.login_succeeded`).
- ورودِ ناموفق (پسورد غلط، brute-force) هیچ ردی در `audit_logs` نمیگذارد.
- این **نقضِ اصل #8 خودِ محصول است** («همهٔ عملیات حساس audit trail دارند»). بدون لاگِ شکست، حملهٔ جستجوی رمز یا takeover قابلکشف نیست.

### H3 — نشتِ کد OTP در پاسخِ API (در نبودِ کلید پیامک)
- `OtpService@request`: `'code' => $isSandbox ? $code : null` و `$isSandbox = config('services.kavenegar.api_key') === ''`.
- یعنی **اگر سرور پروداکشن کلید کاوهنگار نداشته باشد** (که طبق گزارش میدانی ندارد)، کدِ واقعیِ OTP در پاسخِ JSON به کلاینت برمیگردد. فرانت هم آن را میخواند: `form.otp_code = data.code`.
- این یک «راحتیِ توسعه» است که در پروداکشنِ بدون کلید، به **نقضِ کاملِ امنیت OTP** تبدیل میشود.
- **اصلاح:** هیچگاه کدِ واقعی را برنگردان؛ در sandbox فقط برای `APP_ENV=local` آن را لاگ کن، نه در پاسخ.

---

## 🟡 یافتههای متوسط (MEDIUM)

### M1 — دو اسکیمای موازیِ MFA روی هم انباشته شده
- مایگریشن `2026_08_05_..._add_two_factor_columns_to_users_table` ستونهای `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` را اضافه میکند (سبک Fortify).
- مایگریشن `2026_08_17_..._add_mfa_to_users_table` ستونهای `mfa_secret`, `mfa_enabled`, `mfa_backup_codes`, `mfa_enabled_at` را اضافه میکند (سیستم واقعی).
- **هیچ کدی از `two_factor_*` استفاده نمیکند.** این ۳ ستون dead-column هستند؛ سردرگمی و هزینهٔ مهاجرت آینده. باید حذف شوند.

### M2 — `isSuperAdmin()` در هر درخواست پلتفرم یک کوئریِ سنگین میزند
- `User::isSuperAdmin()` → `memberships()->where('status','active')->with('role')->get()->contains(...)`.
- در `EnsureMfaVerified` روی هر روتِ `/platform/*` صدا میشود.
- `get()` همهٔ عضویتها + role را بار میکند. باید با یک `exists()` + رابطه یا کش session بهینه شود.

### M3 — انتخابِ سازمانِ پیشفرض، غیرقطعی است
- `EnsureCurrentOrganization`: اگر session خالی باشد، `orderBy('id')->first()` — یعنی سازمانِ کمترین id، نه سازمانِ «اصلی» کاربر.
- برای کاربرِ چندسازمانی، این ممکن است سازمانِ اشتباه را بهعنوان context بار کند (بعداً سوئیچ دارد، ولی پیشفرض بیمعنی است).

---

## 🟢 یافتههای جزئی (LOW) و نکاتِ مثبت

**مثبتها (باید حفظ شوند):**
- ✅ `hash_equals` برای مقایسهٔ OTP (مقاوم به timing attack).
- ✅ هشِ sha256 برای ذخیرهٔ OTP + محدودیت ۵ تلاش + TTL ۳۰۰ ثانیه.
- ✅ `session()->regenerate()` بعد از ورود و ثبتنام (ضد session fixation).
- ✅ `redact()` در audit برای کلیدهای حساس (password/token/secret/...) — بازگشتی.
- ✅ هشِ IP با HMAC (`ip_hash`) — حریمخصوصی خوب است.
- ✅ throttle روی login، OTP، و روتهای پرمصرف.
- ✅ `declare(strict_types=1)` سراسری + type-hint کامل + readonly injection.

**جزئی قابل بهبود:**
- L1: پیامِ خطای لاگین «ایمیل یا رمز عبور صحیح نیست» اطلاعاتِ وجودِ ایمیل را لو نمیدهد (خوب) ولی فرم، ایمیل را نگه میدارد (`onlyInput('email')`) — استاندارد و درست.
- L2: نرخِ محدودیتِ لاگین (`tooManyAttempts(...,5)` + decay 60s) ساده است؛ برای حسابهای حساس، escalation (افزایش تدریجیِ قفل) ندارد.
- L3: پرچم `mfa_verified` در session هیچ انقضایی ندارد (بدون re-auth دورهای برای عملیات حساسِ پلتفرم).
- L4: `RegisterController@store` بعد از `Auth::login` مستقیم به `app.onboarding` میرود؛ اگر onboarding از قبل پر باشد، باید `intended()` باشد.

---

## 🧭 نگاهِ جورنی (لیلا) — شکافِ UX در همین فاز

1. **«ثبتنام» اولین لمسِ کاربر با برند است و الان شکسته است.** اگر حتی یک مشتری واقعی امروز فرم را پر کند، در همینجا رها میکند و اعتمادش از بین میرود — بازگرداندنِ یک لیدِ ازدسترفته از یک leadِ هرگز-نیامده سختتر است.
2. **ورودِ OTP تبلیغ شده ولی غایب.** اگر در UI دکمهٔ «ورود با کد» باشد و 501 بدهد، حسِ «محصولِ نیمهکاره» را در ۵ ثانیهٔ اول منتقل میکند.
3. **بازیابی رمزِ بیصدا.** پیامِ موفقیت در حالی که ایمیلی نرفته، «سکوتِ خطرناک» است — کاربر فکر میکند مشکل از ایمیلش است، نه از ما.

---

## 📋 بکلاگِ اصلاحِ فاز ۱ (به ترتیب اولویت)

| # | یافته | شدت | مالک | پیشنهادِ دقیق |
|---|-------|-----|------|---------------|
| 1 | C1 ثبتنام شکسته | 🔴 | سارا | `OtpRegisterController@request` را به `OtpService->request($phone,'register')` وصل کن؛ پاسخ JSON برگردان. تستِ E2E بنویس (درخواست کد → ثبتنام). |
| 2 | C3 ایمیل log-only | 🔴 | رضا | SMTP واقعی وصل کن؛ در غیر این صورت بازیابی رمز را موقتاً غیرفعال و پیام صادقانه بده. |
| 3 | H3 نشت کد OTP | 🔴 | سارا | `code` را هرگز در پاسخ JSON برنگردان؛ در local فقط لاگ کن. |
| 4 | C2 ورود OTP استاب | 🟠 | سارا | `OtpLoginController` را به `OtpService` وصل کن یا روت/UI آن را موقتاً حذف کن تا «تبلیغِ مرده» نباشد. |
| 5 | H2 لاگِ ورود ناموفق | 🟠 | سارا | `auth.login_failed` با email+ip را در audit ثبت کن. |
| 6 | H1 تأیید ایمیل | 🟠 | آرش/سارا | تصمیم: MustVerifyEmail فعال یا حداقل `email_verified_at` هنگام ثبتنامِ با OTP ست شود. |
| 7 | M1 حذف two_factor_* | 🟡 | سارا | مایگریشن rollback یا در migration بعدی drop شود. |
| 8 | M2 بهینهسازی isSuperAdmin | 🟡 | سارا | `exists()` + کش session. |
| 9 | M3 سازمانِ پیشفرض | 🟡 | آرش | «سازمانِ اصلی» (primary) در membership تعریف شود. |

---

## ✅ فیکس‌های اعمال‌شده (روی کدِ کلون‌شده — این جلسه)

| # | یافته | فایل | تغییر |
|---|-------|------|--------|
| 1 | C1 ثبت‌نام شکسته | `app/Http/Controllers/Auth/OtpRegisterController.php` | بازنویسی کامل: اتصال به `OtpService->request`، اعتبارسنجی phone، رد شمارهٔ تکراری (422)، rate-limit هر شماره. |
| 2 | C2 ورود OTP استاب | `app/Http/Controllers/Auth/OtpLoginController.php` | بازنویسی کامل: `request` → ارسال OTP، `verify` → احراز + `Auth::login` + `session()->regenerate()` + audit `auth.login_otp_succeeded`. |
| 3 | H3 نشت کد OTP | `app/Domains/Identity/Services/OtpService.php` | کد واقعی فقط در محیط غیر-پروداکشن و sandbox برگردانده می‌شود (`!app()->environment('production')`). |
| 4 | H3 (تکمیلی) | `app/Domains/Platform/Sms/KavenegarSms.php` | در پروداکشن بدون کلید، `success=false` (به‌جای «موفقیت کاذب» sandbox). |
| 5 | H2 لاگ ورود ناموفق | `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | ثبت `auth.login_failed` با email در audit. |
| 6 | C3 پیام صادقانهٔ بازیابی | `app/Http/Controllers/Auth/PasswordResetLinkController.php` | در پروداکشن با `MAIL_MAILER=log`، خطای صادقانه به‌جای موفقیت کاذب (تست‌ها دست‌نخورده). |
| 7 | M1 حذف two_factor_* | `database/migrations/2026_08_25_000001_drop_legacy_two_factor_columns_from_users_table.php` | مایگریشن جدید برای drop ستون‌های مرده. |
| 8 | M2 بهینه‌سازی isSuperAdmin | `app/Models/User.php` | `get()+contains` → یک `exists()` با `whereHas('role')`. |

**تست‌های اضافه/تکمیل‌شده:**
- `tests/Feature/Auth/AuthenticationTest.php` → تست جدید `test_failed_login_is_audited`.
- `tests/Feature/Auth/OtpAuthTest.php` → assertion جدید `auth.login_otp_succeeded`.

> ⚠️ **محدودیت محیط:** PHP/Composer در این sandbox در دسترس نیست (نتورک فقط npm را می‌گذراند)، بنابراین این فیکس‌ها با بازبینیِ دقیق و هم‌جهت با تست‌های موجود (`RegisterTest`, `OtpAuthTest`, `AuthenticationTest`) اعمال شده‌اند، اما **اجرای `php artisan test` روی سرورِ خودت الزامی است** قبل از deploy. قراردادِ JSON پاسخ‌ها با آنچه تست‌ها انتظار دارند (`sent`, `code`, `success`) کاملاً منطبق است.

---

> **جمع‌بندی فاز ۱:** کدِ auth از نظر امنیتِ پایه (هش، timing-safe، session، audit-redaction) **بهتر از میانگینِ صنعت** است. اما **لایهٔ اتصال (controllers) و زیرساخت (mailer) شکستهاند** و این یعنی «درِ ورودیِ کسبوکار قفل است.» هیچچیز به اندازهٔ این فاز، سدِ «پول از همین هفته» نیست — چون اولین لید، در همین در میماند.
>
> **حرفِ آرش:** «محصولِ ما مثل یک فروشگاهِ بینقص است که درِ ورودیاش گیر کرده. مشتری میخواهد بیاید داخل، ولی قفل است. هیچ کالایی نمیفروشیم تا این در باز شود.»

---

**پایان فاز ۱.** فاز بعدی طبق توافق: **فاز ۲ — Workspace (سازمان، سایت، پروژه، مشتری) + CRUD هسته** — همان مسیری که بعد از ورود، مشتری طی میکند. بگو بروم سراغش.
