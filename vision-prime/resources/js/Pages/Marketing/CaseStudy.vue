<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ArrowUpRight, CheckCircle2, Clock, TrendingUp } from '@lucide/vue'

import MarketingPageHero from '@/marketing/components/MarketingPageHero.vue'
import SectionHeading from '@/marketing/components/SectionHeading.vue'
import MarketingLayout from '@/marketing/layouts/MarketingLayout.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'

const caseStudies = [
  {
    id: 'agency-seo',
    title: 'آژانس SEO با ۱۲ مشتری وردپرسی',
    category: 'آژانس دیجیتال',
    duration: '۳ ماه',
    result: '+340% ترافیک ارگانیک',
    challenge: 'گزارش‌دهی پراکنده، عدم شفافیت در تغییرات، و ناتوانی در اولویت‌بندی فرصت‌ها برای ۱۲ مشتری وردپرسی.',
    solution: 'اتصال همه سایت‌ها به سوئیت، استفاده از هوش فرصت‌های رشد برای اولویت‌بندی، و خودکارسازی گزارش ماهانه.',
    outcomes: [
      '۳۴۰٪ افزایش ترافیک ارگانیک در ۳ ماه',
      'کاهش ۶۵٪ زمان تولید گزارش',
      'افزایش رضایت مشتری از ۷۲٪ به ۹۸٪',
      'شناسایی ۴۸۰+ فرصت رشد',
    ],
    initials: 'م آ',
    name: 'مدیر SEO',
  },
  {
    id: 'ecommerce',
    title: 'فروشگاه ووکامرس با ۲۰۰۰+ محصول',
    category: 'فروشگاه اینترنتی',
    duration: '۴ ماه',
    result: '+127% فروش از جستجو',
    challenge: 'صفحات محصول بدون بهینه‌سازی، ترافیک پایین، و عدم تبدیل بازدیدکننده به خریدار.',
    solution: 'بهینه‌سازی صفحات درآمدزا، بهبود متا دیتا، و پیاده‌سازی Schema محصول.',
    outcomes: [
      '۱۲۷٪ افزایش فروش از طریق جستجو',
      'بهبود رتبه در ۳۲ کلمه کلیدی اصلی',
      'افزایش CTR از ۲.۱٪ به ۵.۸٪',
      'کاهش نرخ پرش ۴۵٪',
    ],
    initials: 'ب ا',
    name: 'بنیان‌گذار',
  },
  {
    id: 'clinic',
    title: 'کلینیک دندانپزشکی محلی',
    category: 'مرکز درمانی',
    duration: '۲ ماه',
    result: '+89% رزرو آنلاین',
    challenge: 'عدم حضور در نتایج جستجوی محلی، پروفایل Google Maps ناقص، و عدم مدیریت نظرات.',
    solution: 'بهینه‌سازی Google Business Profile، ساخت صفحات خدمات محلی، و مدیریت نظرات مشتریان.',
    outcomes: [
      '۸۹٪ افزایش رزرو آنلاین',
      'ورود به ۳ نتیجه اول جستجوی محلی',
      'افزایش نظرات مثبت ۳۲۰٪',
      'کاهش هزینه تبلیغات ۵۵٪',
    ],
    initials: 'د م',
    name: 'مدیر کلینیک',
  },
]
</script>

<template>
  <Head title="مطالعات موردی — VisionPrime Suite" />
  <MarketingLayout>
    <MarketingPageHero
      title="مطالعات موردی"
      description="نتایج واقعی مشتریان ما — شفاف، قابل تأیید و الهام‌بخش."
    />

    <section class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
      <div class="grid gap-8 lg:grid-cols-3">
        <article
          v-for="study in caseStudies"
          :key="study.id"
          class="border-line bg-surface shadow-card hover:border-brand-200 hover:shadow-panel rounded-2xl border p-7 transition-all duration-300 hover:-translate-y-1"
        >
          <div class="mb-4 flex items-center gap-3">
            <VBadge tone="info">{{ study.category }}</VBadge>
            <span class="text-ink-muted flex items-center gap-1 text-xs">
              <Clock class="size-3" />
              {{ study.duration }}
            </span>
          </div>

          <h2 class="text-ink-strong text-xl font-bold">{{ study.title }}</h2>
          <p class="bg-brand-50 text-brand-700 mt-3 inline-flex items-center gap-1 rounded-lg px-3 py-1 text-sm font-bold">
            <TrendingUp class="size-4" />
            {{ study.result }}
          </p>

          <div class="mt-6 space-y-4">
            <div>
              <h3 class="text-ink-strong text-sm font-bold">چالش</h3>
              <p class="text-ink-muted mt-1 text-sm leading-6">{{ study.challenge }}</p>
            </div>
            <div>
              <h3 class="text-ink-strong text-sm font-bold">راه‌حل</h3>
              <p class="text-ink-muted mt-1 text-sm leading-6">{{ study.solution }}</p>
            </div>
            <div>
              <h3 class="text-ink-strong mb-2 text-sm font-bold">نتایج</h3>
              <ul class="space-y-1.5">
                <li
                  v-for="outcome in study.outcomes"
                  :key="outcome"
                  class="flex items-start gap-2 text-sm"
                >
                  <CheckCircle2 class="text-success-600 mt-0.5 size-4 shrink-0" />
                  <span class="text-ink-muted">{{ outcome }}</span>
                </li>
              </ul>
            </div>
          </div>

          <div class="border-line mt-6 flex items-center gap-3 border-t pt-5">
            <span class="bg-gradient-brand flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white">
              {{ study.initials }}
            </span>
            <div>
              <p class="text-ink-strong text-sm font-bold">{{ study.name }}</p>
              <p class="text-ink-muted text-xs">مشتری واقعی Vision Prime Suite</p>
            </div>
          </div>
        </article>
      </div>

      <div class="mt-16 text-center">
        <VBadge tone="info" class="mb-4">پروژه بعدی شما</VBadge>
        <h2 class="text-ink-strong text-2xl font-bold sm:text-3xl">
          آماده‌اید داستان موفقیت بعدی ما باشید؟
        </h2>
        <p class="text-ink-muted mx-auto mt-4 max-w-2xl leading-7">
          با Vision Prime Suite، تیم شما هم می‌تواند نتایج مشابهی بگیرد.
          همین حالا دموی اختصاصی را درخواست کنید.
        </p>
        <div class="mt-8 flex justify-center gap-3">
          <VButton href="/demo" size="lg" variant="gradient">
            درخواست دموی اختصاصی
            <ArrowUpRight class="size-4" />
          </VButton>
          <VButton href="/contact" size="lg" variant="secondary">تماس با ما</VButton>
        </div>
      </div>
    </section>
  </MarketingLayout>
</template>
