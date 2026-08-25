# 🔬 فاز ۳ — نقدِ ریزبینانهٔ کد: Workspace — Site CRUD (سایت‌ها)

**روش بررسی:** خواندنِ خط‌به‌خطِ `SiteController`، `SitePolicy`، `CreateSite`/`UpdateSite`/`ArchiveSite`، مدل‌ها و request ها.
**حاضرین نقد:** سارا (بک‌اند)، رضا (امنیت)، لیلا (جورنی).
**حکم کلی:** ایزوله‌سازی tenant از طریق Policy درست است (چکِ عضویت در «سازمانِ منبع»)، اما **یک باگ عملکردی** در تغییر پروژه و **یک شکاف scoping** (دسترسی «منتسب») وجود داشت.

---

## 🟠 یافتهٔ بالا (HIGH)

### H3-1 — تغییرِ پروژهٔ سایت عملاً شکسته بود
**شواهد:**
- `SiteController::update` → `abort_unless($p->id === $site->project_id && ...)` — یعنی هر تغییری در پروژه با 422 رد می‌شد.
- `UpdateSite::handle` → `project_id` را اصلاً ذخیره نمی‌کرد.
- در حالی که `edit()` لیست `projects` را به فرم می‌فرستاد (selector نمایش داده می‌شد ولی کار نمی‌کرد).

**نتیجه:** selector پروژه در فرم ویرایش، «دکمهٔ مرده» بود. کاربر نمی‌توانست سایت را بین پروژه‌ها جابه‌جا کند.

---

## 🟡 یافتهٔ متوسط (MEDIUM)

### M3-1 — ریسک آلودگی بین‌سازمانی هنگام تغییر پروژه
- `UpdateSite::handle` برای یکتایی `canonical_url` از `$this->org->id()` (سازمانِ جاری) استفاده می‌کرد؛ اگر کاربرِ چندسازمانی روی سایتی خارج از سازمانِ جاری کار می‌کرد، چکِ یکتایی در اسکوپِ اشتباه اجرا می‌شد.
- `SiteController::update` پروژهٔ مقصد را فقط با «سازمانِ جاری» مقایسه می‌کرد؛ در سناریوی چندسازمانی، امکان انتسابِ سایتِ org B به پروژهٔ org A وجود داشت (فساد داده).

### M3-2 — شکاف scoping: «دسترسیِ منتسب» پیاده نشده
- مجوزهای `site.view.assigned`، `connector.view.assigned` و … در سیدر وجود دارند، اما فیلد `assigned_scope` در `memberships` **هیچ‌جا مقداردهی و خوانده نمی‌شود** (برخلاف `ClientAccessScope` که الگوی درستش است).
- `SitePolicy::viewAny` صرفاً وجود مجوز `site.view.assigned` را چک می‌کند، نه محدودیت به سایت‌های منتسب.
- **نتیجه:** کاربری که فقط `site.view.assigned` دارد، در عمل همهٔ سایت‌های سازمان را می‌بیند.

### M3-3 — کوئری اضافی و 404 نابه‌جا در Policy
- `SitePolicy::view` و `update` → `Organization::query()->findOrFail($site->organization_id)` به‌جای رابطهٔ `$site->organization` (یک کوئری اضافه + امکان 404 برای org حذف‌شده).

---

## 🟢 نکات مثبت (باید حفظ شوند)
- ✅ `CreateSite` → یکتایی canonical_url در اسکوپ سازمان + نرمال‌سازی URL (`CanonicalUrl`).
- ✅ `ArchiveSite` → SoftDelete + audit `site.archived`.
- ✅ `store` → پروژه باید متعلق به سازمانِ جاری باشد (چک tenant) + `Gate::authorize('create', ...)`.
- ✅ `public_id` با `Str::ulid` (بدون نشت id داخلی).
- ✅ audit برای `site.created` / `site.updated`.

---

## ✅ فیکس‌های اعمال‌شده (روی کدِ کلون‌شده)

| # | فایل | تغییر |
|---|------|--------|
| 1 | `app/Http/Controllers/App/SiteController.php` | تغییر پروژه مجاز شد (انتقال در «همان سازمانِ سایت»)؛ حذف `abort_unless($p->id === $site->project_id)` |
| 2 | `app/Domains/Workspace/Actions/UpdateSite.php` | ذخیرهٔ `project_id` + اسکوپِ یکتایی بر اساس `$site->organization_id` (نه org جاری) + حذف وابستگی بلااستفادهٔ CurrentOrganization |
| 3 | `app/Domains/Workspace/Policies/SitePolicy.php` | استفاده از رابطهٔ `$site->organization` به‌جای `findOrFail` |

**تست‌های جدید:** `test_site_can_be_moved_to_another_project_in_same_organization` و `test_site_cannot_be_moved_to_a_project_in_another_organization` در `SiteCrudTest`.

> ⚠️ اجرای `php artisan test` روی سرور الزامی است.

---

## 🧭 تصمیم‌های باز (برای Decision Log)
1. **`assigned_scope`:** آیا «دسترسیِ محدود به سایت‌های منتسب» را پیاده‌سازی کنیم (نیازمند سرویس `SiteAccessScope` مشابه `ClientAccessScope` + UI تخصیص سایت به عضو)، یا فعلاً این مجوزها را معادل «دسترسی سازمانی» نگه داریم؟
2. **بازسازی سایت بایگانی‌شده با همان URL:** چون SoftDelete ردیف را نگه می‌دارد، چکِ یکتایی `canonical_url` جلوی ساخت مجدد را می‌گیرد؛ آیا باید URL را هنگام بایگانی آزاد کرد؟
