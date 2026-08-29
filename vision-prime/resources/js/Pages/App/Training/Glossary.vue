<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppLayout from '@/app/layouts/AppLayout.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VCard from '@/shared/ui/VCard.vue'
import VIcon from '@/shared/ui/VIcon.vue'
import VInput from '@/shared/ui/VInput.vue'
import VPageHeader from '@/shared/ui/VPageHeader.vue'

/**
 * واژه‌نامهٔ اکوسیستم (R2-3): همهٔ اصطلاحات L/R/… با توضیح فارسی ساده،
 * مثال و «چرا مهم است». سوخت کاتالوگ آموزشی و PDF.
 */
interface Term {
  key: string
  title: string
  category: 'سطح اتوماسیون' | 'ریسک' | 'چرخهٔ محتوا' | 'داده و سئو' | 'زیرساخت'
  short: string
  detail: string
  example?: string
}

const TERMS: Term[] = [
  // ─── سطح اتوماسیون ───
  {
    key: 'L0',
    title: 'L0 — فقط مشاهده',
    category: 'سطح اتوماسیون',
    short: 'سیستم فقط تحلیل و گزارش می‌دهد؛ هیچ پیشنهاد اجرایی نمی‌سازد.',
    detail:
      'مناسب شروع همکاری با سایت‌های حساس: اول داده بشناس، بعد قدم بردار. هیچ تغییری پیشنهاد یا اجرا نمی‌شود.',
    example: 'سایت بیمه‌ای که تا اعتماد کامل، فقط گزارش می‌خواهد.',
  },
  {
    key: 'L1',
    title: 'L1 — پیشنهاد با تأیید کامل',
    category: 'سطح اتوماسیون',
    short: 'سیستم پیشنهاد می‌دهد؛ اجرای هر تغییر، حتی متا، نیازمند کلیک شماست.',
    detail:
      'پیش‌فرض امن شروع. همهٔ فرمان‌ها (commands) در صف «بررسی و تأییدها» می‌آیند و بعد از تأیید انسانی ارسال می‌شوند.',
  },
  {
    key: 'L2',
    title: 'L2 — اجرای کنترل‌شده',
    category: 'سطح اتوماسیون',
    short: 'تغییرات کم‌ریسک (مثل متا) خودکار اجرا می‌شوند؛ محتوا هنوز تأیید می‌خواهد.',
    detail:
      'بهبودهای ریز متا (عنوان/توضیح) بدون انتظار اجرا می‌شوند؛ انتشار مقاله/محصول همچنان در صف تأیید است.',
  },
  {
    key: 'L3',
    title: 'L3 — خودکارسازی نظارت‌شده',
    category: 'سطح اتوماسیون',
    short: 'انتشار خودکارِ محتوا با گیت‌های چندلایه (کیفیت، گرمایش، اعتماد، سقف روزانه).',
    detail:
      'موتور اصلی محصول: مقاله تأییدشده → گیت‌ها → انتشار خودکار روی وردپرس. هر زمان Emergency Stop everything را نگه می‌دارد.',
    example: 'بعد از ۵ فرمان دستی موفق (گرمایش)، مقالات سئوشده خودکار منتشر می‌شوند.',
  },
  {
    key: 'L4',
    title: 'L4 — خلبان خودکار محدود',
    category: 'سطح اتوماسیون',
    short: 'خودکار در چارچوب سقف و دامنهٔ مجاز؛ تغییرات پرریسک همیشه تأیید انسانی.',
    detail:
      'بیشترین خودکاری. محدود به بودجهٔ روزانه و انواع محتوای مجاز؛ R3 (انتشار جدید) هرگز خودکار نمی‌شود.',
  },
  // ─── ریسک ───
  {
    key: 'R0',
    title: 'R0 — بدون ریسک',
    category: 'ریسک',
    short: 'اقداماتی که هیچ اثر پایداری ندارند (فقط خواندن/گزارش).',
    detail: 'مثل همگام‌سازی محتوا و دریافت داده GSC — همیشه مجاز.',
  },
  {
    key: 'R1',
    title: 'R1 — ریسک کم',
    category: 'ریسک',
    short: 'تغییر متادیتا (عنوان/توضیح سئو) — بازگشت‌پذیر و کم‌اثر.',
    detail: 'اولین سطحی که می‌تواند خودکار اجرا شود (از L2).',
  },
  {
    key: 'R2',
    title: 'R2 — ریسک متوسط',
    category: 'ریسک',
    short: 'ویرایش محتوای صفحهٔ موجود — اثر روی رتبه دارد ولی snapshot/rollback دارد.',
    detail:
      'هر تغییر، مقدار قبلی را snapshot می‌کند؛ بازگشت خودکار در صورت افت معیار (Rollback Monitor).',
  },
  {
    key: 'R3',
    title: 'R3 — ریسک بالا',
    category: 'ریسک',
    short: 'ساخت و انتشار محتوای جدید (مقاله/محصول) — بالاترین سطح.',
    detail: 'در L3 با گرمایش + گیت کیفیت + آستانهٔ اعتماد بالاتر (۸۵) خودکار می‌شود؛ در L4 هرگز.',
  },
  // ─── چرخهٔ محتوا ───
  {
    key: 'warmup',
    title: 'گرمایش (Warm-up)',
    category: 'چرخهٔ محتوا',
    short: '۵ فرمان دستیِ موفقِ اخیر، پیش‌شرط انتشار خودکار است.',
    detail:
      'سیستم قبل از خودکارسازی، می‌خواهد ببیند الگوی اجرای دستی شما موفق بوده (مقاله: ۵، محصول: ۳). اعتماد، خریداری می‌شود نه ادعا.',
  },
  {
    key: 'confidence',
    title: 'امتیاز اعتماد (Confidence)',
    category: 'چرخهٔ محتوا',
    short: 'عدد ۰–۱۰۰ از ۴ عامل: تازگی داده، سیگنال، توافق منابع، سابقهٔ موفقیت.',
    detail:
      'فرمان فقط وقتی خودکار می‌شود که از آستانهٔ پروفایل بگذرد (پیش‌فرض ۸۰؛ R3 → ۸۵). بدون داده GSC عمداً پایین می‌ماند.',
  },
  {
    key: 'cover-gate',
    title: 'گیت کاور (require_cover)',
    category: 'چرخهٔ محتوا',
    short: 'انتشار خودکار بدون تصویر شاخص → نگه‌داشته می‌شود برای تأیید انسانی.',
    detail:
      'سیاست پیش‌فرض روشن؛ از تنظیمات پلتفرم قابل تغییر است. کاور از استوک/انتخاب شما/AI تهیه و به رسانهٔ وردپرس آپلود می‌شود.',
  },
  {
    key: 'rollback',
    title: 'بازگشت خودکار (Rollback)',
    category: 'چرخهٔ محتوا',
    short: 'اگر بعد از تغییر، معیارها افت کنند، مقدار قبلی خودکار برمی‌گردد.',
    detail:
      'هر فرمان snapshot می‌گیرد؛ Rollback Monitor دوره‌ای افت را چک می‌کند. پست‌هایی که خود سیستم ساخته علامت‌گذاری شده‌اند.',
  },
  {
    key: 'connector',
    title: 'کانکتور (پلاگین وردپرس)',
    category: 'چرخهٔ محتوا',
    short: 'پل امن میان سوئیت و وردپرس با امضای HMAC — بدون ذخیرهٔ رمز وردپرس.',
    detail:
      'فرمان‌ها امضاشده ارسال می‌شوند، پلاگین اجرا و نتیجه را برمی‌گرداند. نسخهٔ ≥ 1.4.1 برای دسته/کاور لازم است.',
  },
  // ─── داده و سئو ───
  {
    key: 'gsc',
    title: 'GSC (سرچ کنسول)',
    category: 'داده و سئو',
    short: 'دادهٔ واقعی کلیک/نمایش/جایگاه از گوگل — مغز توصیه‌های سیستم.',
    detail:
      'بدون GSC، ۳۰٪ امتیاز اعتماد از دست می‌رود و انتشار خودکار عملاً به تأیید انسانی می‌رسد. توصیه: قبل از پایلوت وصل شود.',
  },
  {
    key: 'url-profile',
    title: 'پروفایل URL',
    category: 'داده و سئو',
    short: 'هر صفحهٔ سایت با متادیتای سئو — واحد تحلیل سیستم.',
    detail:
      'از همگام‌سازی وردپرس ساخته می‌شود؛ فرصت‌ها، ریسک‌ها و تولید محتوا روی همین واحدها سوارند.',
  },
  {
    key: 'serp',
    title: 'تحلیل SERP',
    category: 'داده و سئو',
    short: 'بررسی ساختار محتوای رقبا برای یک کلیدواژه.',
    detail:
      'در حال حاضر مبتنی بر تخمین مدل زبانی است (قابل‌باور ولی تضمین‌نشده)؛ اتصال سرویس SERP واقعی در نقشهٔ راه است.',
  },
  // ─── زیرساخت ───
  {
    key: 'command',
    title: 'فرمان (Command)',
    category: 'زیرساخت',
    short: 'واحد اجرای تغییر: از تصمیم تا اجرا و بازگشت، همه ثبت می‌شود.',
    detail:
      'چرخه: پیشنهاد → تأیید → ارسال امضاشده → نتیجه → (در صورت نیاز) rollback. رد حسابرسی کامل دارد.',
  },
  {
    key: 'emergency',
    title: 'توقف اضطراری (Emergency Stop)',
    category: 'زیرساخت',
    short: 'کلید قرمز: همهٔ فرمان‌های در جریان را نگه می‌دارد.',
    detail: 'از صفحهٔ اتوماسیون سایت یا پنل پلتفرم. صف‌ها لغو، انسانی‌ها می‌مانند تا بررسی.',
  },
  {
    key: 'quota-ai',
    title: 'سهمیهٔ AI',
    category: 'زیرساخت',
    short: 'توکن تولید متن ماهانه per پلن؛ تصویر AI هفتگی (۱۵).',
    detail:
      'استوک و موتور آفلاین همیشه آزادند. سهمیه فقط تولید را می‌گیرد و پیام ارتقا می‌دهد — سرویس نمی‌ایستد.',
  },
]

const categories = computed(() => ['همه', ...Array.from(new Set(TERMS.map((t) => t.category)))])
const active = ref('همه')
const q = ref('')

const filtered = computed(() =>
  TERMS.filter(
    (t) =>
      (active.value === 'همه' || t.category === active.value) &&
      (q.value.trim() === '' ||
        (t.title + t.short + t.detail + (t.example ?? ''))
          .toLowerCase()
          .includes(q.value.trim().toLowerCase())),
  ),
)
</script>

<template>
  <Head title="واژه‌نامهٔ اکوسیستم" />
  <AppLayout>
    <VPageHeader
      title="واژه‌نامهٔ اکوسیستم"
      subtitle="هر اصطلاحی که در سوئیت می‌بینید — به زبان ساده، با مثال"
    />

    <div class="mb-5 flex flex-wrap items-center gap-2">
      <VInput v-model="q" placeholder="جستجو در واژه‌نامه…" class="w-full sm:w-72" />
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="c in categories"
          :key="c"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
          :class="
            active === c
              ? 'border-brand-600 bg-brand-50 text-brand-700'
              : 'border-line text-ink-muted hover:border-brand-400'
          "
          @click="active = c"
        >
          {{ c }}
        </button>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <VCard v-for="t in filtered" :key="t.key">
        <div class="flex items-start justify-between gap-2">
          <div class="flex items-center gap-2">
            <VIcon name="info" tone="brand" size="sm" />
            <p class="text-ink-strong text-sm font-bold">{{ t.title }}</p>
          </div>
          <VBadge tone="neutral" size="sm">{{ t.category }}</VBadge>
        </div>
        <p class="text-ink mt-2 text-xs leading-6">{{ t.short }}</p>
        <p class="text-ink-muted mt-1.5 text-xs leading-6">{{ t.detail }}</p>
        <p v-if="t.example" class="bg-surface-muted mt-3 rounded-lg p-2 text-[11px] leading-5">
          <span class="font-semibold">مثال:</span> {{ t.example }}
        </p>
      </VCard>
    </div>
    <p v-if="filtered.length === 0" class="text-ink-muted py-10 text-center text-sm">
      چیزی پیدا نشد — عبارت دیگری امتحان کنید.
    </p>
  </AppLayout>
</template>
