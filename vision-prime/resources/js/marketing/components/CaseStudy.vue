<script setup lang="ts">
import {
  ArrowUpRight,
  BarChart3,
  CheckCircle2,
  FileText,
  ShieldCheck,
  ShoppingBag,
  Sparkles,
  Target,
} from '@lucide/vue'

import AnimatedNumber from './AnimatedNumber.vue'
import VButton from '@/shared/ui/VButton.vue'

/**
 * بخش «پروندهٔ واقعی» در لندینگ — یک نوار تیره که عمداً از پس‌زمینهٔ روشن صفحه
 * جدا می‌شود تا این دستاورد دیده شود. جزئیات کامل در صفحهٔ /case-study است.
 */

/**
 * تصویر گزارش سرچ کنسول از `public/images` سرو می‌شود؛ مسیر را در متغیر نگه می‌داریم
 * تا Vite آدرس را به‌عنوان asset import حل نکند (هم‌الگو با MarketingFooter).
 */
const gscReportImage = '/images/case-study-liuna-gsc.png'

const metrics = [
  { to: 731, suffix: '', label: 'کلیک ارگانیک' },
  { to: 9020, suffix: '', label: 'نمایش در گوگل' },
  { to: 8, suffix: '٪', label: 'نرخ کلیک' },
  { to: 8, suffix: '', label: 'رتبهٔ میانگین' },
]

const proofs = [
  {
    icon: ShieldCheck,
    title: 'کاملاً ارگانیک',
    text: 'بدون رپورتاژ، بدون بک‌لینک خریداری‌شده و بدون تبلیغات پولی.',
  },
  {
    icon: FileText,
    title: 'صفحات محصول، نه وبلاگ',
    text: 'سخت‌ترین نوع محتوا برای رتبه‌بندی — نه مقاله و نه صفحهٔ ساده.',
  },
  {
    icon: ShoppingBag,
    title: 'خروجی، سفارش — نه ترافیک',
    text: '۱۷ سفارش واقعی از همین مسیر ارگانیک، بدون هیچ هزینهٔ جذب.',
  },
]

/**
 * شرط‌های سختی که این نتیجه در آن‌ها گرفته شد — یک نگاه، بدون بخش جداگانه.
 * عمداً کوتاه: شرح کامل هرکدام در صفحهٔ /case-study است.
 */
const handicaps = [
  'نیچ اشباع آرایشی و بهداشتی',
  'فقط صفحات محصول، بدون یک مقاله',
  'بدون رپورتاژ و بدون لینک خریداری‌شده',
  'بدون دخالت نیروی انسانی',
  'ایندکس عمداً محدود، نه کامل',
  'زیرساخت داخلی و ابزار محدود',
]
</script>

<template>
  <section class="bg-inverse relative overflow-hidden text-white">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
      <div
        class="absolute inset-0 opacity-[0.14]"
        style="
          background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0);
          background-size: 30px 30px;
        "
      />
      <div
        class="absolute -top-32 right-[-6%] size-[440px] rounded-full bg-indigo-500/25 blur-3xl"
      />
      <div
        class="absolute -bottom-28 left-[-8%] size-[420px] rounded-full bg-violet-500/20 blur-3xl"
      />
    </div>

    <div class="relative mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
      <div class="grid items-center gap-12 lg:grid-cols-[0.95fr_1.05fr]">
        <!-- متن -->
        <div v-reveal>
          <span
            class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3.5 py-1.5 text-xs font-bold tracking-wide backdrop-blur"
          >
            <span
              class="size-2 rounded-full bg-emerald-400 shadow-[0_0_12px_2px_rgba(52,211,153,0.7)]"
            />
            پروندهٔ واقعی — دادهٔ خام سرچ کنسول
          </span>

          <h2
            class="font-display mt-6 text-3xl leading-[1.5] font-bold sm:text-4xl sm:leading-relaxed"
          >
            در رقابتی‌ترین نیچ ایران،
            <span class="text-gradient-on-dark">بدون یک ریال تبلیغات</span>
            به صفحهٔ اول گوگل رسیدیم.
          </h2>

          <p class="mt-5 max-w-xl leading-8 text-white/70">
            عملکرد واقعی اکوسیستم روی فروشگاه
            <span class="font-latin font-bold text-white" dir="ltr">liuna.ir</span>. تمرکز ما روی
            <span class="font-bold text-white">صفحات محصول</span> بود — سخت‌ترین نوع محتوا برای سئو
            — با رشد ۱۰ برابری در بازهٔ ۳ ماهه.
          </p>

          <!-- سه ادعای صریح -->
          <ul class="mt-7 flex flex-wrap gap-x-6 gap-y-3">
            <li class="flex items-center gap-2 text-sm text-white/80">
              <CheckCircle2 class="size-4 text-emerald-400" aria-hidden="true" />
              صفر ریال تبلیغات
            </li>
            <li class="flex items-center gap-2 text-sm text-white/80">
              <CheckCircle2 class="size-4 text-emerald-400" aria-hidden="true" />
              صفر بک‌لینک خریداری‌شده
            </li>
            <li class="flex items-center gap-2 text-sm text-white/80">
              <CheckCircle2 class="size-4 text-emerald-400" aria-hidden="true" />
              رشد ۱۰x
            </li>
          </ul>

          <!-- شرط‌های سختی — خواننده می‌بیند این نتیجه در چه میدانی گرفته شده -->
          <ul class="mt-7 flex flex-wrap gap-x-2.5 gap-y-2.5">
            <li
              v-for="item in handicaps"
              :key="item"
              class="rounded-full border border-white/15 bg-white/[0.05] px-3.5 py-1.5 text-xs font-semibold text-white/65"
            >
              {{ item }}
            </li>
          </ul>

          <div class="mt-8 flex flex-wrap items-center gap-4">
            <VButton href="/case-study" size="lg" variant="secondary">
              دیدن پروندهٔ کامل
              <ArrowUpRight class="size-4" aria-hidden="true" />
            </VButton>
            <a
              href="https://liuna.ir"
              target="_blank"
              rel="noopener noreferrer"
              class="transition-ui group inline-flex items-center gap-2 text-sm font-semibold text-white/75 hover:text-white"
            >
              مشاهدهٔ خروجی روی
              <span class="font-latin font-bold" dir="ltr">liuna.ir</span>
              <ArrowUpRight
                class="size-4 text-white/50 transition-transform duration-300 group-hover:-translate-y-0.5"
                aria-hidden="true"
              />
            </a>
          </div>
        </div>

        <!-- سند تصویری + عددها -->
        <div v-reveal="{ delay: 120 }" class="flex flex-col gap-6">
          <figure
            class="overflow-hidden rounded-2xl border border-white/15 bg-white/[0.06] shadow-[0_24px_60px_rgba(0,0,0,0.35)] backdrop-blur-sm"
          >
            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-2.5">
              <div class="flex gap-1.5" aria-hidden="true">
                <span class="size-2.5 rounded-full bg-red-400" />
                <span class="size-2.5 rounded-full bg-amber-400" />
                <span class="size-2.5 rounded-full bg-emerald-400" />
              </div>
              <span class="font-latin flex-1 text-center text-[11px] text-white/50" dir="ltr"
                >search.google.com/search-console — liuna.ir</span
              >
            </div>
            <img
              :src="gscReportImage"
              alt="گزارش عملکرد liuna.ir در Google Search Console — بدون ویرایش"
              width="1365"
              height="597"
              class="block w-full"
              loading="lazy"
              decoding="async"
            />
          </figure>

          <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div
              v-for="metric in metrics"
              :key="metric.label"
              class="rounded-xl border border-white/15 bg-white/[0.06] px-3 py-4 text-center backdrop-blur-sm"
            >
              <p class="font-display text-2xl font-bold sm:text-3xl">
                <AnimatedNumber :to="metric.to" /><span class="text-gradient-on-dark">{{
                  metric.suffix
                }}</span>
              </p>
              <p class="mt-1 text-xs font-semibold text-white/65">{{ metric.label }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- چرا این دستاورد مهم است -->
      <div class="mt-14 grid gap-5 sm:grid-cols-3">
        <article
          v-for="(proof, index) in proofs"
          :key="proof.title"
          v-reveal="{ delay: index * 110 }"
          class="group rounded-2xl border border-white/12 bg-white/[0.05] p-6 backdrop-blur-sm transition-all duration-300 hover:-translate-y-1 hover:border-white/25 hover:bg-white/[0.08]"
        >
          <span
            class="inline-flex size-11 items-center justify-center rounded-xl bg-white/12 text-white transition-transform duration-300 group-hover:scale-110"
          >
            <component :is="proof.icon" class="size-5" aria-hidden="true" />
          </span>
          <h3 class="mt-5 text-base font-bold text-white">{{ proof.title }}</h3>
          <p class="mt-2 text-sm leading-7 text-white/65">{{ proof.text }}</p>
        </article>
      </div>

      <!-- سیگنال‌های اجرایی -->
      <div
        v-reveal
        class="mt-8 flex flex-wrap items-center gap-x-8 gap-y-4 rounded-2xl border border-white/12 bg-white/[0.05] px-7 py-6 backdrop-blur-sm"
      >
        <span class="flex items-center gap-2.5 text-sm text-white/75">
          <BarChart3 class="size-4 text-white/50" aria-hidden="true" />
          <span class="font-display text-lg font-bold text-white">۷۲۳</span>
          فرصت رشد شناسایی‌شده
        </span>
        <span class="flex items-center gap-2.5 text-sm text-white/75">
          <Target class="size-4 text-white/50" aria-hidden="true" />
          <span class="font-display text-lg font-bold text-white">۲۸۶</span>
          صفحهٔ محصول بهینه‌شده
        </span>
        <span class="flex items-center gap-2.5 text-sm text-white/75">
          <ShoppingBag class="size-4 text-white/50" aria-hidden="true" />
          <span class="font-display text-lg font-bold text-white">۱۷</span>
          سفارش واقعی از همین مسیر
        </span>
        <span class="flex items-center gap-2.5 text-sm text-white/75">
          <Sparkles class="size-4 text-white/50" aria-hidden="true" />
          <span class="font-display text-lg font-bold text-white">۳ ماه</span>
          تا رسیدن به صفحهٔ اول
        </span>
      </div>
    </div>
  </section>
</template>
