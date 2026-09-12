<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
  Activity,
  AlertTriangle,
  ArrowUpRight,
  BarChart3,
  Brain,
  CheckCircle2,
  Clock,
  ExternalLink,
  Eye,
  FileText,
  Filter,
  Gauge,
  GitBranch,
  Globe,
  Link2,
  ListChecks,
  MousePointerClick,
  Quote,
  Route,
  Scale,
  Search,
  ShieldCheck,
  ShoppingBag,
  Sparkles,
  Target,
  Timer,
  TrendingUp,
  Users,
  Wallet,
  Zap,
} from '@lucide/vue'
import { computed } from 'vue'

import AnimatedNumber from '@/marketing/components/AnimatedNumber.vue'
import MarketingLayout from '@/marketing/layouts/MarketingLayout.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'

/**
 * پروندهٔ واقعی liuna.ir — فروشگاه آرایشی و بهداشتی، نیچ پررقابت ایرانی.
 * همهٔ عددها از Google Search Console همان دامنه استخراج شده‌اند؛ اسکرین‌شات زیر
 * بدون هیچ ویرایش یا برشی روی صفحه قرار می‌گیرد تا ادعا قابل راستی‌آزمایی بماند.
 */

/**
 * تصویر گزارش سرچ کنسول از `public/images` سرو می‌شود؛ مسیر را در متغیر نگه می‌داریم
 * تا Vite آدرس را به‌عنوان asset import حل نکند (هم‌الگو با MarketingFooter).
 */
const gscReportImage = '/images/case-study-liuna-gsc.png'

/** سایت زندهٔ مورد مطالعه — برای بازدید مستقیم و راستی‌آزمایی توسط خواننده. */
const liveSite = 'https://liuna.ir'

const fa = (value: number): string => new Intl.NumberFormat('fa-IR').format(value)

type HeadlineMetric = {
  to: number
  suffix: string
  label: string
  hint: string
  /** رقم اعشار فارسی که AnimatedNumber (که همیشه گرد می‌کند) نمایش نمی‌دهد. */
  decimals?: string
}

/** نوار متریک هدر — بی‌واسطه و بدون آرایهٔ تزئینی، چون اینجا فقط «سند» را نشان می‌دهیم. */
const headlineMetrics: HeadlineMetric[] = [
  { to: 731, suffix: '', label: 'کلیک ارگانیک', hint: 'بدون یک ریال تبلیغات' },
  { to: 9020, suffix: '', label: 'نمایش در گوگل', hint: '۹.۰۲ هزار بار دیده شدیم' },
  { to: 8, suffix: '٪', label: 'نرخ کلیک', decimals: '٫۱', hint: 'میانگین بازار: ۳٪' },
  { to: 8, suffix: '', label: 'رتبهٔ میانگین', hint: 'صفحهٔ اول گوگل' },
]

/**
 * «میدان سخت» — شرط‌هایی که این نتیجه را از یک موفقیت معمولی جدا می‌کنند.
 * هر کارت دو لایه دارد: خودِ محدودیت، و آنچه یک تیم انسانی در همان نقطه از دست می‌دهد.
 */
const constraints = [
  {
    code: '۰۱',
    icon: Target,
    title: 'نیچ اشباع، نه نیچ باز',
    text: 'آرایشی و بهداشتی از پرتراکم‌ترین بازارهای جستجوی فارسی است؛ رقبا بودجهٔ ماهانهٔ خود را صرف تبلیغات و رپورتاژ می‌کنند و کلمات کلیدی اصلی سال‌ها پیش اشغال شده‌اند.',
    loss: 'در تیم سنتی، ورود به چنین نیچی بدون بودجهٔ لینک‌سازی عملاً توصیه نمی‌شود.',
  },
  {
    code: '۰۲',
    icon: FileText,
    title: 'فقط صفحات محصول، بدون مقالهٔ پشتیبان',
    text: 'هیچ مقالهٔ راهنما، هیچ محتوای آموزشی و هیچ متن طولانی‌ای برای تغذیهٔ رشد نوشته نشد. تنها دارایی، صفحات محصول بودند: محتوای نازک، نیت تجاری و الگوی تکرارشونده.',
    loss: 'روش متعارف تیم‌ها، ساختن خوشهٔ محتوایی حول کلمهٔ کلیدی است؛ این مسیر کاملاً بسته بود.',
  },
  {
    code: '۰۳',
    icon: ShieldCheck,
    title: 'پروفایل بک‌لینک: صفر مطلق',
    text: 'نه رپورتاژ خریداری شد، نه لینک اسپانسری، نه بودجهٔ PPC. اقتدار دامنه تنها از ساختار داخلی، کیفیت صفحه و ایندکس طبیعی ساخته شد.',
    loss: 'در تحلیل سنتی، سایت بدون بک‌لینک خارجی معمولاً در صفحهٔ دوم متوقف می‌ماند.',
  },
  {
    code: '۰۴',
    icon: Filter,
    title: 'ایندکس عمداً محدود',
    text: 'همهٔ صفحات به‌صورت آگاهانه وارد ایندکس نشدند. صفحات کم‌ارزش بیرون نگه داشته شدند تا سیگنال‌های نیت و کیفیت روی مجموعهٔ هدف‌دار متمرکز بمانند.',
    loss: 'تصمیم «کدام صفحه ایندکس نشود» در تیم سنتی یا گرفته نمی‌شود یا با سلیقه گرفته می‌شود.',
  },
  {
    code: '۰۵',
    icon: Globe,
    title: 'زیرساخت محدود',
    text: 'روی سرور داخلی، با دسترسی محدود به APIهای گوگل و بدون هیچ ابزار پولی سئو — یعنی همان شرایطی که خیلی از کسب‌وکارهای ایرانی در آن کار می‌کنند.',
    loss: 'بخشی از جریان داده که ابزارهای خارجی به‌راحتی فراهم می‌کنند، اینجا باید از صفر ساخته می‌شد.',
  },
  {
    code: '۰۶',
    icon: Users,
    title: 'حذف کامل عامل انسانی',
    text: 'هیچ کارشناس سئو، هیچ مدیر محتوا و هیچ جلسهٔ تصمیم‌گیری در این پروژه دخالت نداشت. کشف، اولویت‌بندی، اجرا و سنجش را خودِ اکوسیستم انجام داد.',
    loss: 'در تیم سنتی، همین‌جا نقطهٔ گلوگاه است: ظرفیت انسانی محدود است و تصمیم‌ها به حضور افراد وابسته‌اند.',
  },
]

/** «صفرهای سند» — عددهای گردی که مرزهای کوته‌نظری را نشانه می‌گیرند. */
const zeros = [
  { value: '0', label: 'ریال هزینهٔ تبلیغات' },
  { value: '0', label: 'بک‌لینک و رپورتاژ خریداری‌شده' },
  { value: '0', label: 'نیروی انسانی در چرخهٔ اجرا' },
]

/**
 * بنچمارک چندجانبه — هر شاخص در برابر میانهٔ متعارف همان صنعت گذاشته می‌شود.
 * `oursWidth`/`marketWidth` فقط برای مقیاس بصری‌اند، نه بخشی از دادهٔ سنجش.
 */
const benchmarks = [
  {
    label: 'نرخ کلیک (CTR)',
    ours: '۸.۱٪',
    market: '۳.۰٪',
    oursWidth: 81,
    marketWidth: 30,
    note: '۲.۷ برابر میانهٔ صنعت',
    basis: 'میانهٔ CTR ارگانیک در جایگاه‌های ۱ تا ۱۰',
  },
  {
    label: 'میانگین موقعیت در گوگل',
    ours: '۸',
    market: '۱۵',
    oursWidth: 40,
    marketWidth: 75,
    note: 'کمتر بهتر — ما در صفحهٔ اول',
    basis: 'میانگین موقعیت متعارف صفحات محصول بدون لینک خارجی',
  },
  {
    label: 'ضریب رشد ترافیک، ۳ ماهه',
    ours: '۱۰x',
    market: '۲.۵x',
    oursWidth: 100,
    marketWidth: 25,
    note: '۴ برابر سرعت رشد متعارف',
    basis: 'میانگین رشد پروژه‌های سئوی محتوایی در بازهٔ ۳ ماهه',
  },
  {
    label: 'نرخ تبدیل (کلیک → سفارش)',
    ours: '۲.۳٪',
    market: '۱.۵٪',
    oursWidth: 46,
    marketWidth: 30,
    note: 'ترافیک کم‌نوسان، نیت خرید بالا',
    basis: 'میانهٔ نرخ تبدیل فروشگاه‌های اینترنتی کوچک',
  },
]

/**
 * چالش‌های ساختاری تیم انسانی — نه شکست افراد، بلکه ویژگی ذاتی هر فرایند دستی.
 * هر ردیف یک «حالت شکست» را در برابر سازوکاری می‌گذارد که آن را از میان برمی‌دارد.
 */
const failureModes = [
  {
    icon: Eye,
    name: 'گلوگاه توجه',
    traditional:
      'یک کارشناس هم‌زمان چند صفحه را می‌تواند مقایسه کند؛ باقی یافته‌ها روی کاغذ می‌مانند و فهرست اولویت‌ها همان‌جا متوقف می‌شود.',
    system: 'کل یافته‌ها یکجا وارد یک صف اولویت‌بندی‌شده می‌شوند؛ هیچ فرصتی «بی‌مخاطب» نمی‌ماند.',
  },
  {
    icon: Scale,
    name: 'تصمیم بی‌آزمون',
    traditional:
      'توصیه بر پایهٔ تجربه مطرح و بی‌درنگ اجرا می‌شود؛ اما اثر واقعی‌اش هرگز به‌صورت آماری سنجیده نمی‌شود و تکرارشده تبدیل به باور می‌شود.',
    system:
      'هر تغییر پیش از اجرا شبیه‌سازی و پس از انتشار با نرخ کلیک واقعی سنجیده می‌شود؛ توصیهٔ سنجیده‌نشده جایی در چرخه ندارد.',
  },
  {
    icon: GitBranch,
    name: 'نقطهٔ شکست فرد',
    traditional:
      'بخش عمدهٔ دانش پروژه در ذهن یک نفر است؛ خروج او یعنی توقف چرخه و شروع دوباره از صفر.',
    system:
      'دانش به‌صورت دادهٔ ساختاریافته ذخیره می‌شود؛ چرخه مستقل از حضور افراد ادامه پیدا می‌کند.',
  },
  {
    icon: Activity,
    name: 'اولویت سلیقه‌ای',
    traditional:
      'اولویت‌ها بر پایهٔ حس و تجربه تعیین می‌شوند و بعد برایشان استدلال ساخته می‌شود — نه برعکس.',
    system:
      'اولویت از نسبت اثر به ریسک می‌آید و محدودیت بودجهٔ روزانه اجازهٔ بزرگ‌نمایی هیچ فرصتی را نمی‌دهد.',
  },
  {
    icon: Gauge,
    name: 'کوری اندازه‌گیری',
    traditional:
      'بدون بستر تلمتری، بازدید و کلیک نه روزانه ثبت می‌شود و نه قابل اتکاست؛ تصمیم‌ها روی حدس می‌ایستند.',
    system:
      'جریان داده از خود سایت و از سرچ کنسول تغذیه می‌شود؛ هر تصمیم روی عدد می‌ایستد، نه روی روایت.',
  },
  {
    icon: Timer,
    name: 'دورهٔ بازخورد کند',
    traditional:
      'یک چرخهٔ کامل کشف تا سنجش، در بهترین حالت چند هفته طول می‌کشد؛ خطا دیر کشف می‌شود و دیر اصلاح.',
    system:
      'همین چرخه زمان‌بندی‌شده و روزانه اجرا می‌شود؛ خطا سریع‌تر دیده و سریع‌تر کنار گذاشته می‌شود.',
  },
]

/** کشف → تصمیم؛ زوج‌هایی که نشان می‌دهند چرا این نتیجه تصادفی نبود. */
const discoveries = [
  {
    icon: BarChart3,
    metric: '۷۲۳',
    label: 'فرصت رشد شناسایی‌شده',
    found: 'عمق لینک، صفحات یتیم و مسیرهای مسدود.',
    decided: 'ورود یکجا به صف اولویت‌بندی بر پایهٔ اثر به ریسک.',
  },
  {
    icon: Link2,
    metric: '۵۵۱',
    label: 'پیوند داخلی پیشنهادی',
    found: 'نگاشت معنایی عنوان‌های H2/H3 و نسبت شباهت صفحات.',
    decided: 'توزیع هدفمند قدرت داخلی به سمت صفحات کم‌دسترس.',
  },
  {
    icon: Sparkles,
    metric: '۲۸۶',
    label: 'صفحهٔ محصول بازنویسی‌شده',
    found: 'عنوان و توضیح کم‌بازده در برابر صفحهٔ رقیب.',
    decided: 'بازنویسی صفحه‌به‌صفحه و انتشار کنترل‌شده.',
  },
  {
    icon: MousePointerClick,
    metric: 'A/B',
    label: 'آزمون عنوان و سنجش نرخ کلیک',
    found: 'اختلاف معنادار CTR میان چند عنوان روی یک صفحهٔ یکسان.',
    decided: 'انتشار خودکار عنوان برنده روی وردپرس.',
  },
  {
    icon: Route,
    metric: 'Rollback',
    label: 'پایش پس از انتشار',
    found: 'افت یا بازگشت رتبه در پی برخی تغییرها.',
    decided: 'بازگشت خودکار به وضعیت پایدار پیشین.',
  },
]

/** خط تولید تصمیم — شش گام از یافتهٔ خام تا حافظهٔ سیستم. */
const pipeline = [
  {
    icon: Search,
    step: 'کشف فرصت',
    text: 'خزش صفحه‌به‌صفحه و انطباق با دادهٔ سرچ کنسول.',
  },
  {
    icon: ListChecks,
    step: 'اولویت‌بندی ریاضی',
    text: 'رتبه‌بندی فرصت‌ها بر پایهٔ اثر به ریسک.',
  },
  {
    icon: Brain,
    step: 'شبیه‌سازی اثر',
    text: 'برآورد نتیجه پیش از اعمال تغییر.',
  },
  {
    icon: Zap,
    step: 'تأیید و اجرا',
    text: 'اعمال روی سایت و راستی‌آزمایی پس از انتشار.',
  },
  {
    icon: Gauge,
    step: 'سنجش نتیجه',
    text: 'اندازه‌گیری نرخ کلیک واقعی و مقایسه با برآورد.',
  },
  {
    icon: TrendingUp,
    step: 'یادگیری سیستم',
    text: 'بازخورد نتیجه به حافظه و اصلاح تصمیم بعدی.',
  },
]

/** خط زمانی رشد — کلیک روزانهٔ تلمتری‌شده از سرچ کنسول. */
const series = [
  { label: '۲۲ خرداد', value: 1 },
  { label: '۱ تیر', value: 2 },
  { label: '۹ تیر', value: 3 },
  { label: '۱۲ تیر', value: 9 },
  { label: '۱۸ تیر', value: 24 },
  { label: '۲۵ تیر', value: 15 },
  { label: '۵ مرداد', value: 11 },
  { label: '۱۵ مرداد', value: 9 },
  { label: '۱ شهریور', value: 12 },
  { label: '۸ شهریور', value: 10 },
]

const chartW = 1000
const chartH = 320
const padX = 26
const padTop = 30
const padBottom = 52
const maxValue = Math.max(...series.map((point) => point.value))
const stepX = (chartW - padX * 2) / (series.length - 1)
const baseY = chartH - padBottom

const points = series.map((point, index) => ({
  ...point,
  x: padX + index * stepX,
  y: padTop + (1 - point.value / maxValue) * (baseY - padTop),
}))

/** منحنی نرم (Catmull-Rom → Bézier) تا روند رشد بدون گوشهٔ تیز خوانده شود. */
const linePath = computed(() => {
  const first = points[0]
  if (!first) {
    return ''
  }
  let path = `M ${first.x} ${first.y}`
  for (let index = 0; index < points.length - 1; index += 1) {
    const prev = points[index - 1] ?? points[index]!
    const current = points[index]!
    const next = points[index + 1]!
    const after = points[index + 2] ?? next
    const c1x = current.x + (next.x - prev.x) / 6
    const c1y = current.y + (next.y - prev.y) / 6
    const c2x = next.x - (after.x - current.x) / 6
    const c2y = next.y - (after.y - current.y) / 6
    path += ` C ${c1x} ${c1y} ${c2x} ${c2y} ${next.x} ${next.y}`
  }
  return path
})

const areaPath = computed(() => {
  const first = points[0]
  const last = points[points.length - 1]
  if (!first || !last) {
    return ''
  }
  return `${linePath.value} L ${last.x} ${baseY} L ${first.x} ${baseY} Z`
})

/** نقطهٔ عطف: جهش ترافیک بعد از شروع فعالیت خودکار اکوسیستم. */
const pivot = points[4]!
</script>

<template>
  <Head title="پروندهٔ واقعی: ۲۸۶ صفحهٔ محصول روی صفحهٔ اول گوگل" />
  <MarketingLayout>
    <main>
      <!-- ============ HERO — سند، نه تبلیغ ============ -->
      <section class="bg-inverse relative overflow-hidden text-white">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
          <div
            class="absolute inset-0 opacity-[0.16]"
            style="
              background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0);
              background-size: 30px 30px;
            "
          />
          <div
            class="absolute -top-40 right-[-8%] size-[520px] rounded-full bg-indigo-500/25 blur-3xl"
          />
          <div
            class="absolute top-24 left-[-10%] size-[460px] rounded-full bg-violet-500/20 blur-3xl"
          />
          <div
            class="absolute -bottom-32 left-1/3 size-[420px] rounded-full bg-sky-400/15 blur-3xl"
          />
        </div>

        <div class="relative mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-20 lg:px-10 lg:py-24">
          <div v-reveal>
            <span
              class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3.5 py-1.5 text-xs font-bold tracking-wide backdrop-blur"
            >
              <span
                class="size-2 rounded-full bg-emerald-400 shadow-[0_0_12px_2px_rgba(52,211,153,0.7)]"
              />
              پروندهٔ واقعی — دادهٔ خام سرچ کنسول، بدون ویرایش
            </span>

            <!-- هر سطر عمداً دستی شکسته شده تا عنوان روی دسکتاپ به شکل یک بیانیهٔ عمودی خوانده شود -->
            <h1
              class="font-display mt-6 max-w-4xl text-3xl leading-[1.55] font-bold sm:text-4xl sm:leading-[1.5] lg:text-5xl lg:leading-[1.45]"
            >
              <span class="text-white">۲۸۶ صفحهٔ محصول</span>
              <br />
              <span class="text-white">در رقابتی‌ترین نیچ ایران،</span>
              <br />
              <span class="text-gradient-on-dark">بدون یک ریال تبلیغات،</span>
              <br />
              <span class="text-white">به صفحهٔ اول گوگل رسیدند.</span>
            </h1>

            <p class="mt-6 max-w-2xl text-base leading-8 text-white/75 sm:text-lg">
              نه رپورتاژ خریده شد، نه بک‌لینکی خریداری شد، نه بودجه‌ای برای تبلیغات پرداخت شد. تنها
              چیزی که وارد شد، خودِ اکوسیستم بود: کشف فرصت، تصمیم‌گیری ریاضی، اجرا و سنجش — به‌صورت
              خودکار روی فروشگاه <span class="font-latin font-bold text-white">liuna.ir</span>.
            </p>

            <!-- دعوت به راستی‌آزمایی: خواننده می‌تواند خودش سایت و صفحات را ببیند -->
            <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3">
              <a
                :href="liveSite"
                target="_blank"
                rel="noopener noreferrer"
                class="transition-ui group rounded-ui inline-flex min-h-12 items-center gap-2.5 border border-white/25 bg-white/10 px-5 text-base font-semibold text-white backdrop-blur hover:border-white/40 hover:bg-white/20"
              >
                <span
                  class="size-2 rounded-full bg-emerald-400 shadow-[0_0_10px_2px_rgba(52,211,153,0.75)]"
                />
                مشاهدهٔ سایت زندهٔ
                <span class="font-latin font-bold" dir="ltr">liuna.ir</span>
                <ExternalLink
                  class="size-4 text-white/70 transition-transform duration-300 group-hover:-translate-y-0.5"
                  aria-hidden="true"
                />
              </a>
              <p class="max-w-sm text-xs leading-6 text-white/55">
                صفحات محصول را باز کنید و خروجی را مستقیم ببینید؛ لازم نیست به این صفحه اعتماد کنید.
              </p>
            </div>
          </div>

          <!-- نوار سند — عددها با خط‌چین ظریف جدا شده‌اند -->
          <div
            v-reveal
            class="mt-12 grid grid-cols-2 gap-y-8 rounded-2xl border border-white/15 bg-white/[0.06] p-7 backdrop-blur-sm sm:grid-cols-4 sm:gap-y-0"
          >
            <div
              v-for="(metric, index) in headlineMetrics"
              :key="metric.label"
              :class="[
                'px-1 text-center sm:px-6',
                index > 0 ? 'sm:border-r sm:border-white/15' : '',
              ]"
            >
              <p class="font-display text-4xl font-bold tracking-tight sm:text-5xl">
                <AnimatedNumber :to="metric.to" /><span v-if="metric.decimals">{{
                  metric.decimals
                }}</span
                ><span class="text-gradient-on-dark">{{ metric.suffix }}</span>
              </p>
              <p class="mt-2 text-sm font-bold text-white">{{ metric.label }}</p>
              <p class="mt-1 text-xs text-white/55">{{ metric.hint }}</p>
            </div>
          </div>

          <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-white/60">
            <span class="flex items-center gap-1.5">
              <CheckCircle2 class="size-3.5 text-emerald-400" aria-hidden="true" />
              بازهٔ سنجش: ۳ ماه
            </span>
            <span class="flex items-center gap-1.5">
              <CheckCircle2 class="size-3.5 text-emerald-400" aria-hidden="true" />
              منبع: Google Search Console
            </span>
            <span class="flex items-center gap-1.5">
              <CheckCircle2 class="size-3.5 text-emerald-400" aria-hidden="true" />
              خروجی: ۱۷ سفارش ثبت‌شده
            </span>
          </div>
        </div>
      </section>

      <!-- ============ THE RECEIPT — اسکرین‌شات بدون هیچ تغییر ============ -->
      <section class="border-line bg-canvas border-b">
        <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
          <div v-reveal class="mx-auto max-w-2xl text-center">
            <p class="text-gradient-brand text-sm font-bold tracking-wide">THE RECEIPT</p>
            <h2 class="text-section-title font-display text-ink-strong mt-3 font-bold sm:text-3xl">
              مدرک، بدون کوچک‌ترین ویرایش
            </h2>
            <p class="text-ink-muted mt-4 leading-8">
              این تصویر مستقیم از پنل Google Search Console گرفته شده است — بدون برش، بدون فیلتر و
              بدون دست‌کاری عددها. هرچه اینجا می‌خوانید، همان چیزی است که خود گوگل ثبت کرده.
            </p>
          </div>

          <div v-reveal class="relative mt-12">
            <div
              aria-hidden="true"
              class="bg-glow-brand pointer-events-none absolute -inset-x-6 -top-8 bottom-0 rounded-[2rem] blur-3xl"
            />
            <figure
              class="border-line bg-surface shadow-panel relative overflow-hidden rounded-2xl border"
            >
              <div class="border-line bg-surface-muted flex items-center gap-3 border-b px-4 py-3">
                <div class="flex gap-1.5" aria-hidden="true">
                  <span class="size-3 rounded-full bg-red-400" />
                  <span class="size-3 rounded-full bg-amber-400" />
                  <span class="size-3 rounded-full bg-emerald-400" />
                </div>
                <div
                  class="border-line bg-surface text-ink-muted flex-1 rounded-md border px-3 py-1 text-center text-xs"
                  dir="ltr"
                >
                  search.google.com/search-console/performance — liuna.ir
                </div>
                <VBadge tone="success" class="hidden shrink-0 sm:inline-flex">LIVE</VBadge>
              </div>
              <img
                :src="gscReportImage"
                alt="گزارش عملکرد liuna.ir در Google Search Console: ۷۳۱ کلیک، ۹,۰۲۰ نمایش، نرخ کلیک ۸.۱٪ و میانگین موقعیت ۸"
                width="1365"
                height="597"
                class="block w-full"
                loading="lazy"
                decoding="async"
              />
              <figcaption
                class="border-line bg-surface-muted text-ink-muted flex flex-wrap items-center justify-center gap-x-5 gap-y-2 border-t px-4 py-3 text-xs"
              >
                <span class="flex items-center gap-1.5">
                  <CheckCircle2 class="text-success-600 size-3.5" aria-hidden="true" />
                  تصویر اصلی، بدون تغییر
                </span>
                <span class="flex items-center gap-1.5">
                  <Clock class="size-3.5" aria-hidden="true" />
                  بازهٔ ۳ ماهه
                </span>
                <span class="flex items-center gap-1.5">
                  <Globe class="size-3.5" aria-hidden="true" />
                  <span class="font-latin" dir="ltr">liuna.ir</span>
                </span>
              </figcaption>
            </figure>
          </div>
        </div>
      </section>

      <!-- ============ میدان سخت — شرایطی که این نتیجه را سخت کرد ============ -->
      <section class="border-line bg-surface border-b">
        <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
          <div v-reveal class="max-w-2xl">
            <p class="text-gradient-brand text-sm font-bold tracking-wide">THE PLAYING FIELD</p>
            <h2 class="text-section-title font-display text-ink-strong mt-3 font-bold sm:text-3xl">
              این نتیجه در آسان‌ترین حالت گرفته نشد.
            </h2>
            <p class="text-ink-muted mt-4 leading-8">
              برای اینکه این عدد معنادار باشد، اول باید بدانیم در چه میدانی گرفته شده. شش شرط زیر
              هم‌زمان برقرار بودند — و هرکدام، در روش متعارف، چیزی را از تیم می‌گیرد.
            </p>
          </div>

          <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            <article
              v-for="(item, index) in constraints"
              :key="item.title"
              v-reveal="{ delay: index * 90 }"
              class="group border-line bg-canvas shadow-card hover:shadow-panel hover:border-brand-200 flex flex-col rounded-2xl border p-6 transition-all duration-300 hover:-translate-y-1 sm:p-7"
            >
              <div class="flex items-center justify-between">
                <span
                  class="bg-gradient-brand inline-flex size-11 items-center justify-center rounded-xl text-white shadow-md transition-transform duration-300 group-hover:scale-110"
                >
                  <component :is="item.icon" class="size-5" aria-hidden="true" />
                </span>
                <span class="font-latin text-ink-muted/50 text-xs font-bold">{{ item.code }}</span>
              </div>
              <h3 class="text-ink-strong mt-5 text-lg font-bold">{{ item.title }}</h3>
              <p class="text-ink-muted mt-2.5 flex-1 text-sm leading-7">{{ item.text }}</p>
              <p
                class="border-line text-ink-muted mt-5 flex items-start gap-2 border-t pt-4 text-xs leading-6"
              >
                <AlertTriangle
                  class="text-danger-600 mt-0.5 size-3.5 shrink-0"
                  aria-hidden="true"
                />
                <span>{{ item.loss }}</span>
              </p>
            </article>
          </div>

          <!-- خروجی تجاری: ترافیک، نه؛ سفارش -->
          <div
            v-reveal
            class="border-line from-brand-50 to-surface mt-8 grid items-center gap-8 rounded-2xl border bg-gradient-to-l p-7 sm:p-9 lg:grid-cols-[auto_1fr]"
          >
            <div class="text-center lg:pr-9 lg:text-right">
              <p class="font-display text-gradient-brand text-6xl font-bold sm:text-7xl">
                <AnimatedNumber :to="17" />
              </p>
              <p class="text-ink-strong mt-2 text-sm font-bold">سفارش واقعی ثبت‌شده</p>
            </div>
            <div class="border-line lg:border-r lg:pr-9">
              <p class="text-ink-strong text-base leading-8 font-semibold sm:text-lg">
                خروجی نهایی یک عدد ترافیک نیست؛ یک سفارش است.
              </p>
              <p class="text-ink-muted mt-3 text-sm leading-7">
                رشد بازدید وقتی به سبد خرید و پرداخت نرسد، دستاوردی نیست. در همین بازه،
                <span class="font-bold">۱۷ سفارش</span> از همین مسیر ارگانیک ثبت شد — با نرخ تبدیل
                حدود <span class="font-bold">۲.۳٪</span>، بالاتر از میانهٔ متعارف فروشگاه‌های کوچک.
                نکتهٔ مهم‌تر: هیچ‌کدام از این سفارش‌ها هزینهٔ جذب نداشتند. سهم هر سفارش از بودجهٔ
                تبلیغات، <span class="text-ink-strong font-bold">صفر</span> است.
              </p>
              <p class="text-ink-muted mt-3 flex items-start gap-2 text-xs leading-6">
                <ShoppingBag class="text-brand-700 mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                <span>
                  سفارش‌ها از همان صفحات محصولی آمده‌اند که فقط با بهینه‌سازی ساختاری و بدون محتوای
                  پشتیبان رتبه گرفتند.
                </span>
              </p>
            </div>
          </div>

          <!-- سند صفرها -->
          <div
            v-reveal
            class="border-line bg-canvas shadow-card mt-8 grid gap-6 rounded-2xl border p-7 sm:grid-cols-3 sm:p-8"
          >
            <div v-for="item in zeros" :key="item.label" class="text-center">
              <p class="font-display text-gradient-brand text-4xl font-bold sm:text-5xl">
                {{ item.value }}
              </p>
              <p class="text-ink-muted mt-2 text-sm font-semibold">{{ item.label }}</p>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ TIMELINE — نقطهٔ عطف ============ -->
      <section class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
        <div v-reveal class="max-w-2xl">
          <p class="text-gradient-brand text-sm font-bold tracking-wide">TIMELINE</p>
          <h2 class="text-section-title font-display text-ink-strong mt-3 font-bold sm:text-3xl">
            نقطهٔ عطف: از ۱ کلیک در روز به ۲۴ کلیک در روز.
          </h2>
          <p class="text-ink-muted mt-4 leading-8">
            تا پیش از شروع فعالیت خودکار اکوسیستم، ترافیک ارگانیک سایت عملاً صفر بود. بعد از آن،
            منحنی جهش کرد و روی یک سطح پایدار تکیه زد.
          </p>
        </div>

        <div
          v-reveal
          class="border-line bg-surface shadow-card mt-12 overflow-hidden rounded-3xl border p-4 sm:p-7"
        >
          <div class="w-full overflow-x-auto">
            <svg
              :viewBox="`0 0 ${chartW} ${chartH}`"
              class="h-64 w-full min-w-[560px] sm:h-80"
              role="img"
              aria-label="نمودار رشد ترافیک ارگانیک از خرداد تا شهریور"
            >
              <defs>
                <linearGradient id="vpArea" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#4f46e5" stop-opacity="0.32" />
                  <stop offset="100%" stop-color="#4f46e5" stop-opacity="0" />
                </linearGradient>
                <linearGradient id="vpLine" x1="0" y1="0" x2="1" y2="0">
                  <stop offset="0%" stop-color="#245c9b" />
                  <stop offset="55%" stop-color="#4f46e5" />
                  <stop offset="100%" stop-color="#7c3aed" />
                </linearGradient>
              </defs>

              <!-- خطوط راهنما -->
              <g stroke="currentColor" class="text-line" stroke-width="1">
                <line
                  v-for="tick in 4"
                  :key="tick"
                  :x1="padX"
                  :x2="chartW - padX"
                  :y1="padTop + ((baseY - padTop) / 4) * (tick - 1)"
                  :y2="padTop + ((baseY - padTop) / 4) * (tick - 1)"
                  stroke-dasharray="4 6"
                />
              </g>

              <!-- ناحیه زیر منحنی -->
              <path :d="areaPath" fill="url(#vpArea)" />
              <!-- منحنی -->
              <path
                :d="linePath"
                fill="none"
                stroke="url(#vpLine)"
                stroke-width="4"
                stroke-linecap="round"
                stroke-linejoin="round"
              />

              <!-- نقاط داده -->
              <g v-for="point in points" :key="point.label">
                <circle
                  :cx="point.x"
                  :cy="point.y"
                  r="5"
                  fill="#ffffff"
                  stroke="url(#vpLine)"
                  stroke-width="3"
                />
              </g>

              <!-- نقطهٔ عطف -->
              <g>
                <line
                  :x1="pivot.x"
                  :x2="pivot.x"
                  :y1="padTop - 8"
                  :y2="baseY"
                  stroke="#7c3aed"
                  stroke-width="1.5"
                  stroke-dasharray="5 5"
                />
                <circle :cx="pivot.x" :cy="pivot.y" r="9" fill="#7c3aed" fill-opacity="0.18" />
                <circle :cx="pivot.x" :cy="pivot.y" r="5" fill="#7c3aed" />
              </g>

              <!-- برچسب محور افقی — یکی درمیان تا برچسب‌های تاریخ روی هم نیفتند -->
              <g class="text-ink-muted" fill="currentColor" font-size="12" font-weight="600">
                <text
                  v-for="(point, index) in points"
                  v-show="index % 2 === 0 || index === points.length - 1"
                  :key="`x-${point.label}`"
                  :x="point.x"
                  :y="chartH - 18"
                  text-anchor="middle"
                >
                  {{ point.label }}
                </text>
              </g>
            </svg>
          </div>

          <div class="border-line mt-4 flex flex-wrap items-center gap-x-6 gap-y-3 border-t pt-5">
            <span class="flex items-center gap-2 text-xs font-semibold">
              <span class="bg-line-strong size-3 rounded-full" />
              <span class="text-ink-muted">ترافیک روزانهٔ ارگانیک (کلیک)</span>
            </span>
            <span class="flex items-center gap-2 text-xs font-semibold">
              <span class="size-3 rounded-full bg-violet-600" />
              <span class="text-ink-strong">نقطهٔ عطف: ورود اکوسیستم</span>
            </span>
            <span class="text-ink-muted ms-auto text-xs">
              اوج: <span class="text-ink-strong font-bold">{{ fa(maxValue) }} کلیک در یک روز</span>
            </span>
          </div>
        </div>
      </section>

      <!-- ============ BENCHMARK — داوری چندجانبه ============ -->
      <section class="border-line bg-surface border-y">
        <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
          <div v-reveal class="max-w-2xl">
            <p class="text-gradient-brand text-sm font-bold tracking-wide">BENCHMARK</p>
            <h2 class="text-section-title font-display text-ink-strong mt-3 font-bold sm:text-3xl">
              عدد خوب نیست؛ نسبت به بازار خوب است.
            </h2>
            <p class="text-ink-muted mt-4 leading-8">
              یک عدد سئو به‌تنهایی هیچ معنایی ندارد. ۷۳۱ کلیک برای یک سایت کوچک رقم بزرگی است و برای
              یک فروشگاه بزرگ رقم کوچکی؛ معنای واقعی وقتی روشن می‌شود که در کنار استاندارد همان صنعت
              گذاشته شود. پس داوری را روی چهار محور مستقل انجام می‌دهیم، نه یک محور.
            </p>
          </div>

          <div class="mt-12 space-y-6">
            <div
              v-for="(item, index) in benchmarks"
              :key="item.label"
              v-reveal="{ delay: index * 100 }"
              class="border-line bg-canvas shadow-card rounded-2xl border p-6 sm:p-7"
            >
              <div class="flex flex-wrap items-baseline justify-between gap-3">
                <p class="text-ink-strong text-base font-bold">{{ item.label }}</p>
                <p class="text-gradient-brand text-sm font-bold">{{ item.note }}</p>
              </div>

              <div class="mt-5 space-y-3">
                <!-- ما -->
                <div class="flex items-center gap-4">
                  <span class="text-ink-strong w-20 shrink-0 text-sm font-bold sm:w-24"
                    >اکوسیستم</span
                  >
                  <div class="bg-surface-muted h-3 flex-1 overflow-hidden rounded-full">
                    <div
                      class="bg-gradient-brand h-full rounded-full transition-[width] duration-1000 ease-out"
                      :style="{ width: `${item.oursWidth}%` }"
                    />
                  </div>
                  <span
                    class="font-display text-ink-strong w-14 shrink-0 text-right text-lg font-bold"
                    >{{ item.ours }}</span
                  >
                </div>
                <!-- بازار -->
                <div class="flex items-center gap-4">
                  <span class="text-ink-muted w-20 shrink-0 text-sm font-semibold sm:w-24"
                    >بازار</span
                  >
                  <div class="bg-surface-muted h-3 flex-1 overflow-hidden rounded-full">
                    <div
                      class="bg-line-strong h-full rounded-full"
                      :style="{ width: `${item.marketWidth}%` }"
                    />
                  </div>
                  <span
                    class="text-ink-muted font-display w-14 shrink-0 text-right text-lg font-bold"
                    >{{ item.market }}</span
                  >
                </div>
              </div>

              <p
                class="text-ink-muted border-line mt-5 flex items-start gap-2 border-t pt-3.5 text-xs leading-6"
              >
                <Scale class="text-brand-700 mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                <span>مبنای داوری: {{ item.basis }}</span>
              </p>
            </div>
          </div>

          <!-- روش‌شناسی داوری — شفافیت، عمداً بدون آرایه -->
          <div v-reveal class="border-line bg-surface-muted mt-8 rounded-2xl border p-6 sm:p-8">
            <h3 class="text-ink-strong flex items-center gap-2 text-base font-bold">
              <Scale class="text-brand-700 size-4" aria-hidden="true" />
              چگونه داوری می‌کنیم
            </h3>
            <dl class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt class="text-ink-strong text-sm font-bold">واحد سنجش</dt>
                <dd class="text-ink-muted mt-1.5 text-xs leading-6">
                  فقط کلیک ارگانیک و سفارش ثبت‌شده؛ نه بازدید تخمینی و نه عدد تزئینی.
                </dd>
              </div>
              <div>
                <dt class="text-ink-strong text-sm font-bold">مبنای مقایسه</dt>
                <dd class="text-ink-muted mt-1.5 text-xs leading-6">
                  میانه‌های متعارف صنعت برای همان شاخص، نه بهترین رکوردهای ثبت‌شده.
                </dd>
              </div>
              <div>
                <dt class="text-ink-strong text-sm font-bold">بازهٔ داوری</dt>
                <dd class="text-ink-muted mt-1.5 text-xs leading-6">
                  بازهٔ ۳ ماههٔ سرچ کنسول؛ یک پنجرهٔ کوتاه، نه یک جهش یک‌روزه.
                </dd>
              </div>
              <div>
                <dt class="text-ink-strong text-sm font-bold">محدودیت داوری</dt>
                <dd class="text-ink-muted mt-1.5 text-xs leading-6">
                  این یک مطالعهٔ موردی تک‌دامنه‌ای است؛ ادعای تعمیم آماری به همهٔ سایت‌ها را نداریم.
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </section>

      <!-- ============ چالش‌های ساختاری تیم انسانی ============ -->
      <section class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
        <div v-reveal class="max-w-3xl">
          <p class="text-gradient-brand text-sm font-bold tracking-wide">STRUCTURAL GAPS</p>
          <h2 class="text-section-title font-display text-ink-strong mt-3 font-bold sm:text-3xl">
            چالش‌هایی که در روش انسانی حل‌نشده می‌مانند.
          </h2>
          <p class="text-ink-muted mt-4 leading-8">
            هیچ‌کدام از شش مورد زیر نتیجهٔ بی‌کفایتی افراد نیست؛ ویژگی ساختاری هر فرایند دستی است.
            کاری که یک مدیر سایت با تیم انسانی و سئوی سنتی هر روز با آن دست‌وپنجه نرم می‌کند، دقیقاً
            همین‌جاست — و تفاوت در این است که آیا برایشان سازوکاری وجود دارد یا نه.
          </p>
        </div>

        <div class="mt-12 space-y-4">
          <div
            v-for="(item, index) in failureModes"
            :key="item.name"
            v-reveal="{ delay: index * 80 }"
            class="border-line bg-surface shadow-card hover:border-brand-200 rounded-2xl border p-6 transition-colors duration-300 sm:p-7"
          >
            <div class="grid gap-6 lg:grid-cols-[240px_1fr_1fr] lg:items-start">
              <div class="flex items-center gap-3">
                <span
                  class="border-line bg-canvas text-brand-700 inline-flex size-10 shrink-0 items-center justify-center rounded-xl border"
                >
                  <component :is="item.icon" class="size-5" aria-hidden="true" />
                </span>
                <p class="text-ink-strong text-base font-bold">{{ item.name }}</p>
              </div>

              <div
                class="border-line bg-canvas rounded-xl border p-4 lg:border-0 lg:bg-transparent lg:p-0"
              >
                <p class="text-danger-600 text-xs font-bold">در سئوی سنتی و تیمی</p>
                <p class="text-ink-muted mt-2 text-sm leading-7">{{ item.traditional }}</p>
              </div>

              <div
                class="border-brand-100 bg-brand-50/60 lg:border-line rounded-xl border p-4 lg:border-0 lg:border-r lg:bg-transparent lg:pr-6"
              >
                <p class="text-brand-700 text-xs font-bold">در اکوسیستم، به‌صورت ساختاری</p>
                <p class="text-ink-muted mt-2 text-sm leading-7">{{ item.system }}</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ ENGINE — کشف → تصمیم ============ -->
      <section class="border-line bg-surface border-y">
        <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
          <div v-reveal class="max-w-3xl">
            <p class="text-gradient-brand text-sm font-bold tracking-wide">THE ENGINE</p>
            <h2 class="text-section-title font-display text-ink-strong mt-3 font-bold sm:text-3xl">
              سیستم دقیقاً چه چیزی کشف کرد که بدون آن، تصمیم‌گیری ممکن نبود؟
            </h2>
            <p class="text-ink-muted mt-4 leading-8">
              «هوشمند» یک ادعا نیست؛ باید نشان داد چه یافته‌ای به چه تصمیمی منتهی شد. هر ردیف زیر،
              از یک کشف مشخص شروع می‌شود و به یک اقدام مشخص می‌رسد — زنجیره‌ای که در نهایت به آن
              منحنی رشد ختم شد.
            </p>
          </div>

          <div class="mt-12 space-y-4">
            <article
              v-for="(item, index) in discoveries"
              :key="item.label"
              v-reveal="{ delay: index * 90 }"
              class="border-line bg-canvas shadow-card rounded-2xl border p-6 sm:p-7"
            >
              <div class="grid gap-6 lg:grid-cols-[auto_1.1fr_auto_1fr] lg:items-center">
                <!-- کشف -->
                <div class="flex items-center gap-3">
                  <span
                    class="bg-gradient-brand inline-flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md"
                  >
                    <component :is="item.icon" class="size-5" aria-hidden="true" />
                  </span>
                  <div>
                    <p class="font-display text-gradient-brand text-2xl font-bold">
                      {{ item.metric }}
                    </p>
                    <p class="text-ink-strong text-xs font-bold">{{ item.label }}</p>
                  </div>
                </div>

                <div class="bg-surface-muted rounded-xl px-4 py-3">
                  <p class="text-ink-muted flex items-center gap-1.5 text-xs font-bold">
                    <Search class="size-3.5" aria-hidden="true" /> یافته
                  </p>
                  <p class="text-ink-muted mt-1.5 text-sm leading-7">{{ item.found }}</p>
                </div>

                <div class="text-brand-200 hidden justify-center lg:flex" aria-hidden="true">
                  <svg
                    viewBox="0 0 24 24"
                    class="size-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                  >
                    <path d="M19 12H5" stroke-linecap="round" />
                    <path d="m12 19-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </div>

                <div class="border-brand-100 bg-brand-50/60 rounded-xl border px-4 py-3">
                  <p class="text-brand-700 flex items-center gap-1.5 text-xs font-bold">
                    <Zap class="size-3.5" aria-hidden="true" /> تصمیم
                  </p>
                  <p class="text-ink-strong mt-1.5 text-sm leading-7 font-semibold">
                    {{ item.decided }}
                  </p>
                </div>
              </div>
            </article>
          </div>

          <!-- ===== زنجیرهٔ تأثیر — خط تولید تصمیم ===== -->
          <div v-reveal class="mt-14">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <h3 class="text-ink-strong flex items-center gap-2 text-lg font-bold">
                <Route class="text-brand-700 size-5" aria-hidden="true" />
                زنجیرهٔ تأثیر
              </h3>
              <p class="text-ink-muted text-xs font-semibold">از یافتهٔ خام تا حافظهٔ سیستم</p>
            </div>

            <div class="relative mt-8">
              <!-- ستون فقرات گرادیانی (دسکتاپ) -->
              <div
                aria-hidden="true"
                class="bg-gradient-brand absolute top-[26px] right-[6%] left-[6%] hidden h-[3px] rounded-full opacity-25 lg:block"
              />

              <ol class="relative grid gap-4 lg:grid-cols-6 lg:gap-3">
                <li
                  v-for="(stage, index) in pipeline"
                  :key="stage.step"
                  class="border-line bg-canvas shadow-card hover:shadow-panel relative rounded-2xl border p-5 transition-all duration-300 hover:-translate-y-1"
                >
                  <!-- شمارهٔ گام روی ستون فقرات -->
                  <span
                    class="border-brand-200 bg-surface text-brand-700 font-display relative z-10 inline-flex size-9 items-center justify-center rounded-full border-2 text-xs font-bold"
                  >
                    {{ ['۰۱', '۰۲', '۰۳', '۰۴', '۰۵', '۰۶'][index] }}
                  </span>
                  <component
                    :is="stage.icon"
                    class="text-brand-700 mt-4 size-5"
                    aria-hidden="true"
                  />
                  <p class="text-ink-strong mt-2.5 text-sm font-bold">{{ stage.step }}</p>
                  <p class="text-ink-muted mt-1.5 text-xs leading-6">{{ stage.text }}</p>
                  <!-- فلش بین گام‌ها -->
                  <span
                    v-if="index < pipeline.length - 1"
                    class="text-brand-200 absolute top-1/3 -left-4 hidden text-lg font-bold lg:block"
                    aria-hidden="true"
                    >←</span
                  >
                </li>
              </ol>
            </div>

            <!-- دستاورد کهنه‌نشدنی -->
            <div
              class="border-line bg-surface-muted mt-8 flex flex-wrap items-center gap-x-8 gap-y-4 rounded-2xl border px-7 py-6"
            >
              <span class="flex items-center gap-2.5 text-sm">
                <Wallet class="text-brand-700 size-4" aria-hidden="true" />
                <span class="text-ink-muted">هزینهٔ جذب هر ورودی:</span>
                <span class="text-ink-strong font-bold">صفر</span>
              </span>
              <span class="border-line hidden h-5 border-r sm:block" aria-hidden="true" />
              <span class="flex items-center gap-2.5 text-sm">
                <Timer class="text-brand-700 size-4" aria-hidden="true" />
                <span class="text-ink-muted">چرخهٔ کشف تا سنجش:</span>
                <span class="text-ink-strong font-bold">روزانه، بدون توقف</span>
              </span>
              <span class="border-line hidden h-5 border-r sm:block" aria-hidden="true" />
              <span class="flex items-center gap-2.5 text-sm">
                <Activity class="text-brand-700 size-4" aria-hidden="true" />
                <span class="text-ink-muted">وابستگی به حضور فرد:</span>
                <span class="text-ink-strong font-bold">ندارد</span>
              </span>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ CLOSING — بیانیه و دعوت ============ -->
      <section class="mx-auto max-w-7xl px-5 pb-20 sm:px-8 lg:px-10">
        <div
          class="bg-inverse relative overflow-hidden rounded-3xl px-6 py-14 text-white sm:px-12 sm:py-16"
        >
          <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-24 left-1/4 size-72 rounded-full bg-violet-500/25 blur-3xl" />
            <div
              class="absolute right-1/4 -bottom-28 size-72 rounded-full bg-indigo-500/25 blur-3xl"
            />
            <div
              class="absolute inset-0 opacity-[0.1]"
              style="
                background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0);
                background-size: 26px 26px;
              "
            />
          </div>
          <div class="relative mx-auto max-w-3xl text-center">
            <Quote class="mx-auto size-8 text-white/40" aria-hidden="true" />
            <p class="mt-6 text-xl leading-9 font-medium sm:text-2xl sm:leading-10">
              وقتی یک سیستم بتواند در سخت‌ترین نیچ ایران،
              <span class="text-gradient-on-dark font-bold">صفحات محصول واقعی</span>
              را بدون هیچ هزینهٔ تبلیغاتی به
              <span class="text-gradient-on-dark font-bold">صفحهٔ اول گوگل</span>
              برساند و از همان مسیر سفارش بگیرد، دیگر سئو یک حدس نیست؛ یک فرایند قابل اندازه‌گیری
              است.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
              <a
                :href="liveSite"
                target="_blank"
                rel="noopener noreferrer"
                class="transition-ui rounded-ui inline-flex min-h-12 items-center justify-center gap-2 border border-white/25 bg-white/10 px-5 text-base font-semibold text-white hover:bg-white/20"
              >
                مشاهدهٔ خروجی روی
                <span class="font-latin font-bold" dir="ltr">liuna.ir</span>
                <ExternalLink class="size-4" aria-hidden="true" />
              </a>
              <VButton href="/demo" size="lg" variant="secondary">
                درخواست دموی اختصاصی
                <ArrowUpRight class="size-4" aria-hidden="true" />
              </VButton>
            </div>
            <p class="mt-5 text-xs text-white/55">
              بدون تعهد • بدون کارت اعتباری • بررسی اختصاصی سایت شما
            </p>
          </div>
        </div>
      </section>
    </main>
  </MarketingLayout>
</template>
