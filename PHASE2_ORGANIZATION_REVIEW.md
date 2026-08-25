# 🔬 فاز ۲ — نقدِ ریزبینانهٔ کد: Organization & Membership (سازمان، عضویت، RBAC)

**روش بررسی:** خواندنِ خط‌به‌خطِ کدِ واقعی (مدل‌ها، اکشن‌ها، کنترلرها، میدلورها، سیدر نقش‌ها).
**حاضرین نقد:** سارا (بک‌اند)، آرش (PM)، رضا (امنیت).
**حکم کلی:** پایهٔ RBAC و مدل چندسازمانی **خوب طراحی شده** (Permission keys جامع، `OrganizationPermission` سرویس، audit در همهٔ عملیات عضو). اما **یک باگ امنیتیِ جدی (escalation)** و یک شکافِ حاکمیتی (last-admin) وجود داشت که فیکس شدند.

---

## 🔴 یافتهٔ بحرانی (CRITICAL) — اسکالیشن سطح دسترسی

### C2-1 — یک agency-admin می‌توانست خود را super-admin (سطح پلتفرم) کند
**شواهد کد:**
- `OrganizationSettingsController::index` → `Role::query()->orderBy('name')->get()` — یعنی **همهٔ نقش‌ها از جمله `super-admin`** به فرانت ارسال می‌شد.
- `store` و `update` → فقط `'role_id' => ['required','integer','exists:roles,id']` — یعنی هر id نقشِ موجود (از جمله super-admin) قابل تخصیص بود.
- `EnsurePlatformAccess` (اتاق فرماندهی پلتفرم) → فقط `role?->key === 'super-admin'` را چک می‌کند.
- سیدر → `agency-admin` همهٔ permission ها را دارد **به‌جز `billing.manage`**؛ یعنی `member.manage.organization` را دارد.

**نتیجهٔ زنجیره‌ای:** یک `agency-admin` (که فقط سطح سازمان دارد) می‌توانست با یک POST ساده، نقش `super-admin` را به خودش بدهد و از آن لحظه به **اتاق فرماندهی کل پلتفرم** (مدیریت همهٔ سازمان‌ها، صورتحساب، پرداخت، impersonation، MFA، emergency stop) دسترسی کامل می‌گرفت. این یعنی فروپاشی کامل ایزوله‌سازی tenant ها.

**ریشه:** تفکیک نکردن «نقش‌های سطح سازمان» از «نقش‌های سطح پلتفرم» در نقطهٔ تخصیص.

---

## 🟠 یافتهٔ بالا (HIGH)

### H2-1 — حذف/تنزلِ آخرین مدیرِ سازمان محافظت نمی‌شد
- `destroy` فقط «حذف خود» را مسدود می‌کرد؛ `update` (تغییر نقش) هیچ محافظی نداشت.
- سناریو: آخرین `agency-admin` می‌توانست نقشِ خودش را به `seo-manager` تنزل دهد → سازمان بدون مدیر، بدون هیچ راه بازگشتی.

---

## 🟡 یافته‌های متوسط (MEDIUM)

### M2-1 — منطق تکراری و سنگینِ isSuperAdmin
- `EnsurePlatformAccess` هنوز از `get()->contains()` استفاده می‌کرد (بارگذاری همهٔ عضویت‌ها + نقش‌ها)، در حالی که `User::isSuperAdmin()` در فاز ۱ بهینه شده بود.

### M2-2 — انتخاب غیرقطعیِ سازمانِ پیش‌فرض
- `EnsureCurrentOrganization` → `orderBy('id')->first()` — رفتاری مبهم؛ برای کاربرِ چندسازمانی، سازمانِ «کمترین id» انتخاب می‌شد نه سازمانِ اصلی.

---

## 🟢 نکات مثبت (باید حفظ شوند)
- ✅ `unique(['organization_id','user_id'])` — جلوگیری از عضویت تکراری در سطح دیتابیس.
- ✅ `CreateOrganization` در یک `DB::transaction` + slug یکتا + `Str::ulid` برای public_id (بدون نشت id داخلی).
- ✅ `SwitchCurrentOrganization` عضویتِ فعال را چک می‌کند (بدون دسترسی، AuthorizationException).
- ✅ `OrganizationPermission::allows` — یک کوئری `whereHas('role.permissions')` تمیز.
- ✅ audit برای `organization.created`، `member_added`، `member_role_changed`، `member_removed`، `organization.selected`.
- ✅ `cascadeOnDelete` برای membership (با حذف سازمان، عضویت‌ها پاک می‌شوند) و `restrictOnDelete` برای role (جلوگیری از حذف نقشِ در استفاده).

---

## 📋 بک‌لاگ فاز ۲

| # | یافته | شدت | وضعیت |
|---|-------|-----|--------|
| 1 | C2-1 اسکالیشن super-admin | 🔴 | ✅ فیکس شد |
| 2 | H2-1 last-admin guard | 🟠 | ✅ فیکس شد |
| 3 | M2-1 isSuperAdmin تکراری | 🟡 | ✅ فیکس شد |
| 4 | M2-2 سازمان پیش‌فرض | 🟡 | ✅ فیکس شد |

---

## ✅ فیکس‌های اعمال‌شده (روی کدِ کلون‌شده)

| # | فایل | تغییر |
|---|------|--------|
| 1 | `app/Http/Controllers/App/Settings/OrganizationSettingsController.php` | حذف super-admin از لیست roles + `Rule::exists()->where('key','!=','super-admin')` در store/update + متدهای `assertNotLastAdminWhenDemoting/Removing` + `otherAdminExists` |
| 2 | `app/Http/Middleware/EnsurePlatformAccess.php` | استفاده از `$user->isSuperAdmin()` |
| 3 | `app/Http/Middleware/EnsureCurrentOrganization.php` | `orderBy('created_at')` به‌جای `orderBy('id')` |

**تست‌های جدید:** `tests/Feature/Organization/OrganizationMemberSecurityTest.php` (۵ مورد: تخصیص super-admin ممنوع در store/update، تنزل آخرین ادمین ممنوع، حذف خود ممنوع، حذف عضو غیرمدیر مجاز).

> ⚠️ **محدودیت محیط:** PHP فقط برای lint (سینتکس) در sandbox اجرا می‌شود؛ اجرای `php artisan test` روی سرور الزامی است (deploy-phase1.sh قابل تعمیم برای فازهای بعد).

---

## 🧭 تصمیم‌های باز (برای ثبت در Decision Log)
1. آیا کاربر باید بتواند **سازمان دوم** بسازد؟ (فعلاً فقط مسیر onboarding است؛ endpoint جدا برای «سازمان جدید» وجود ندارد.)
2. `store` عضوی که از قبل status=suspended بوده را بی‌صدا reactivate می‌کند — آیا audit خاصی می‌خواهد؟
3. نقش‌های `client-viewer`/`client-approver` هم سطح-سازمان‌اند؛ آیا باید از تخصیصِ عضویت سازمانی مستثنا شوند (چون در `client_user_assignments` مدیریت می‌شوند)؟
