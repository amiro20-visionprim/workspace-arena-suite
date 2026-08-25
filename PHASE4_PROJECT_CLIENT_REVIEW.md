# 🔬 فاز ۴ — نقدِ ریزبینانهٔ کد: Workspace — Project & Client (پروژه و مشتری)

**روش بررسی:** خواندنِ خط‌به‌خطِ `ProjectController`، `ClientController`، اکشن‌های Create/Update/Archive/Assign/Remove، و `ClientAccessScope`.
**حاضرین نقد:** سارا (بک‌اند)، رضا (امنیت).
**حکم کلی:** لایهٔ Client کاملاً درست و تمیز است (IDOR و نقش‌ها چک می‌شوند). اما **ProjectController دو باگ** داشت (یکی authorization ناسازگار، یکی تغییر مشتری) که فیکس شدند. به‌علاوه، **شکاف سیستماتیک authorization** در کل لایهٔ هوش SEO شناسایی شد.

---

## 🟠 یافتهٔ بالا (HIGH)

### H4-1 — authorization ناسازگار در ایجاد پروژه
- `ProjectController::create` → `Gate::authorize('create', [Project::class, $org])` (مجوز `project.manage.organization`).
- اما `store` → `Gate::authorize('update', $client)` (مجوز `client.manage.organization`).
- **نتیجه:** کاربری که `project.manage.organization` دارد ولی `client.manage.organization` ندارد، می‌توانست فرم ایجاد پروژه را ببیند ولی نتواند پروژه بسازد.

### H4-2 — تغییرِ مشتریِ پروژه شکسته بود
- `update` → `abort_unless($client->getKey() === $project->client_id, 422)`؛ یعنی هر تغییری در مشتری با 422 رد می‌شد.
- `UpdateProject::handle` → `client_id` را ذخیره نمی‌کرد.
- **نتیجه:** همان باگ «selector مرده» که در فاز ۳ برای سایت پیدا کردیم، اینجا هم برای پروژه وجود داشت.

---

## 🔴 یافتهٔ بحرانی (CRITICAL) — شکاف سیستماتیک در لایهٔ هوش SEO

### C4-1 — عدم‌وجود authorization در کل لایهٔ Intelligence
- `UrlProfileController`، `OpportunityController`، `MoneyPageController` (و مشابه‌ها) **هیچ Gate/Policy نداشتند**؛ فقط با `Site::where('organization_id', ...)->pluck('id')` اسکوپ سازمانی می‌کردند.
- **نتیجه:** هر عضو سازمان — حتی نقش‌های حداقلی مثل `client-viewer` — به دادهٔ هوش SEO (URL profiles، فرصت‌ها، صفحات پول‌ساز، GSC metadata) دسترسی کامل داشت.

> در این فاز، برای `UrlProfile` پالیسی ساخته و authorization اضافه شد. بقیهٔ کنترلرهای هوش SEO (Opportunity/MoneyPage/ConversionRisk/Recommendation) در **فاز ۷** به‌صورت سیستماتیک پوشش داده خواهند شد.

---

## 🟢 نکات مثبت (باید حفظ شوند)
- ✅ `ClientController` → استفادهٔ یکدست از `Gate::authorize` + `ClientPolicy`.
- ✅ `RemoveClientUserAssignment` → چک `assignment->client_id === client->id` (ضد IDOR).
- ✅ `AssignClientUser` → اعتبارسنجی عضویت فعال + تطبیق نقش پرتال (`viewer`/`approver`).
- ✅ `ClientAccessScope` → الگوی درستِ scoping (قابل تعمیم برای `assigned_scope` سایت‌ها).

---

## ✅ فیکس‌های اعمال‌شده (روی کدِ کلون‌شده)

| # | فایل | تغییر |
|---|------|--------|
| 1 | `app/Http/Controllers/App/ProjectController.php` | `store` → `Gate::authorize('create', [Project, org])` هم‌راستا با `create()`؛ `update` → تغییر مشتری مجاز در همان سازمانِ پروژه |
| 2 | `app/Domains/Workspace/Actions/UpdateProject.php` | ذخیرهٔ `client_id` + audit آن |
| 3 | `app/Domains/Content/Policies/UrlProfilePolicy.php` | پالیسی جدید (مجوز `intelligence.view.assigned` یا `site.view.organization`) |
| 4 | `app/Providers/AppServiceProvider.php` | ثبت `Gate::policy(UrlProfile::class, ...)` |
| 5 | `app/Http/Controllers/App/UrlProfileController.php` | افزودن `Gate::authorize('viewAny')` و `Gate::authorize('view')` |

**تست‌های جدید:** `test_project_can_be_moved_to_another_client_in_same_organization` و `test_project_cannot_be_moved_to_a_client_in_another_organization` در `ProjectCrudTest`.

> ⚠️ اجرای `php artisan test` روی سرور الزامی است.

---

## 🧭 تصمیم‌های باز (برای Decision Log)
1. **مجوز صحیح لایهٔ هوش SEO:** آیا `intelligence.view.assigned` برای همهٔ کنترلرهای هوش SEO (Opportunity/MoneyPage/…) gate نهایی باشد؟ (فاز ۷)
2. **نقش‌های client-viewer/client-approver** در عضویت سازمانی، دسترسی به `/app/*` را هم دارند؛ آیا باید از مسیرهای agency جدا شوند؟
