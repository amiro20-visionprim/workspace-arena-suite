<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'

const props = defineProps<{ sites?: { id: number; name: string }[] }>()

interface BulkItem {
  id: number
  keyword: string
  title: string
  status: 'pending' | 'processing' | 'completed' | 'failed' | 'needs_review'
  source: string | null
  draft_id: number | null
  quality_score: number | null
  word_count: number | null
  slug: string | null
  error: string | null
  publish_status: 'pending' | 'publishing' | 'published' | 'failed' | null
  post_id: number | null
  published_at: string | null
  publish_error: string | null
}

interface BulkJobView {
  id: number
  name: string
  site_id: number
  status: 'pending' | 'running' | 'completed' | 'partial' | 'failed'
  content_type: string
  subtype: string
  auto_publish: 'off' | 'draft' | 'publish'
  scheduled_at: string | null
  daily_publish_limit: number
  total_items: number
  completed_items: number
  failed_items: number
  needs_review_items: number
  progress: number
  started_at: string | null
  finished_at: string | null
  created_at: string | null
}

const jobs = ref<BulkJobView[]>([])
const selectedJob = ref<{ job: BulkJobView; items: BulkItem[] } | null>(null)

// P2.7 — کارت آمار انتشار
interface PublishStats {
  published: number
  publish_failed: number
  review_queue: number
  recent_published: { id: number; keyword: string; post_id: number | null; published_at: string | null; site_name: string; post_url: string | null }[]
}
const stats = ref<PublishStats>({ published: 0, publish_failed: 0, review_queue: 0, recent_published: [] })

async function loadStats() {
  try {
    const res = await fetch('/api/bulk-content/stats', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (res.ok) stats.value = (await res.json()) as PublishStats
  } catch {
    /* نادیده */
  }
}
const loading = ref(false)
const detailLoading = ref(false)
const error = ref('')
const success = ref('')

// فرم ساخت
const form = ref({
  site_id: 0,
  name: '',
  content_type: 'article',
  subtype: 'guide',
  auto_publish: 'off',
  scheduled_at: '',
  daily_publish_limit: 0,
  keywordsText: '',
})
const sites = ref<{ id: number; name: string }[]>([])
const creating = ref(false)
const publishing = ref(false)
const publishingItemIds = ref<number[]>([])
// P2.7 — فیلتر آیتم‌ها بر اساس وضعیت انتشار
const publishFilter = ref<'all' | 'published' | 'failed' | 'pending'>('all')
// P2.8 — آیتم‌های در حال بازطراحی
const reworkingItemIds = ref<number[]>([])
// P2.8 — گزارش کیفیت
interface QualityReport {
  total: number
  published: number
  rejected: number
  review_queue: number
  failed_generation: number
  avg_quality_score: number | null
  publish_rate: number
  rejection_reasons: Record<string, number>
  suggestions: string[]
}
const qualityReport = ref<QualityReport | null>(null)

let pollTimer: ReturnType<typeof setInterval> | null = null

const filteredItems = computed(() => {
  if (!selectedJob.value) return []
  if (publishFilter.value === 'all') return selectedJob.value.items
  return selectedJob.value.items.filter((i) => {
    if (publishFilter.value === 'published') return i.publish_status === 'published'
    if (publishFilter.value === 'failed') return i.publish_status === 'failed'
    return !i.publish_status || i.publish_status === 'pending'
  })
})

const statusBadge = (s: string) => {
  const map: Record<string, { tone: string; label: string }> = {
    pending: { tone: 'info', label: 'در انتظار' },
    processing: { tone: 'info', label: 'در حال پردازش' },
    completed: { tone: 'success', label: 'تکمیل شد' },
    failed: { tone: 'danger', label: 'ناموفق' },
    needs_review: { tone: 'warning', label: 'نیازمند بازبینی' },
    running: { tone: 'info', label: 'در حال اجرا' },
    partial: { tone: 'warning', label: 'ناقص' },
  }
  return map[s] ?? { tone: 'info', label: s }
}

const keywordList = computed(() =>
  form.value.keywordsText
    .split('\n')
    .map((k) => k.trim())
    .filter((k) => k.length > 1),
)

async function loadJobs() {
  try {
    const res = await fetch('/api/bulk-content/jobs', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = await res.json()
    jobs.value = data.jobs ?? []
    await loadStats()
    await loadQualityReport()
  } catch (e) {
    error.value = 'خطا در دریافت لیست دسته‌های تولید'
  }
}

/** P2.8 — گزارش کیفیت تولید گروهی */
async function loadQualityReport() {
  try {
    const res = await fetch('/api/bulk-content/quality-report', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (res.ok) qualityReport.value = (await res.json()) as QualityReport
  } catch {
    /* نادیده */
  }
}

/** P2.8 — بازطراحی/بازتولید آیتم ناقص (needs_review/failed) */
async function reworkItem(item: BulkItem, title?: string) {
  error.value = ''
  success.value = ''
  reworkingItemIds.value.push(item.id)
  try {
    const res = await fetch(`/api/bulk-content/items/${item.id}/rework`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ title: title ?? undefined }),
    })
    const data = await res.json()
    if (!res.ok) {
      error.value = data.error ?? 'بازطراحی ناموفق بود'
      return
    }
    success.value = title
      ? `«${item.keyword}» با عنوان «${title}» برای بازتولید آماده شد — «شروع پردازش» را بزنید.`
      : `«${item.keyword}» برای بازتولید آماده شد — «شروع پردازش» را بزنید.`
    if (selectedJob.value) await openJob(selectedJob.value.job.id)
  } catch {
    error.value = 'خطای شبکه در بازطراحی'
  } finally {
    reworkingItemIds.value = reworkingItemIds.value.filter((x) => x !== item.id)
  }
}

/** استخراج پیشنهادهای long-tail از پیام خطای IL5 */
function longTailSuggestions(item: BulkItem): string[] {
  if (!item.error) return []
  const match = item.error.match(/پیشنهادها:\s*(.+)/)
  if (!match) return []
  return match[1]
    .split(/[—–-]|،/)
    .map((s) => s.trim())
    .filter((s) => s.length > 3)
    .slice(0, 3)
}

async function loadSites() {
  if (props.sites && props.sites.length > 0) {
    sites.value = props.sites
    if (!form.value.site_id) form.value.site_id = sites.value[0].id
    return
  }
  try {
    const res = await fetch('/app/sites', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = await res.json()
    sites.value = (data.sites ?? data ?? []).map((s: any) => ({ id: s.id, name: s.name }))
    if (sites.value.length > 0 && !form.value.site_id) form.value.site_id = sites.value[0].id
  } catch {
    /* نادیده — سایت‌ها از prop اینتالا لود می‌شوند */
  }
}

async function createJob() {
  error.value = ''
  success.value = ''
  if (form.value.site_id === 0) {
    error.value = 'ابتدا سایت را انتخاب کنید.'
    return
  }
  if (keywordList.value.length === 0) {
    error.value = 'حداقل یک کیوورد وارد کنید (هر کیوورد در یک خط).'
    return
  }
  creating.value = true
  try {
    const res = await fetch('/api/bulk-content/jobs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        site_id: form.value.site_id,
        name: form.value.name || undefined,
        content_type: form.value.content_type,
        subtype: form.value.subtype,
        auto_publish: form.value.auto_publish,
        scheduled_at: form.value.scheduled_at ? new Date(form.value.scheduled_at).toISOString() : undefined,
        daily_publish_limit: form.value.daily_publish_limit || 0,
        keywords: keywordList.value,
      }),
    })
    const data = await res.json()
    if (!res.ok) {
      error.value = data.error ?? 'خطا در ساخت دسته'
      return
    }
    success.value = `دسته #${data.id} با ${data.total_items} کیوورد ساخته شد${data.auto_publish && data.auto_publish !== 'off' ? ` — انتشار خودکار: ${data.auto_publish === 'publish' ? 'منتشر' : 'پیش‌نویس وردپرس'}` : ''} — در انتظار شروع پردازش.`
    form.value.keywordsText = ''
    form.value.name = ''
    await loadJobs()
  } catch {
    error.value = 'خطای شبکه در ساخت دسته'
  } finally {
    creating.value = false
  }
}

async function openJob(id: number) {
  detailLoading.value = true
  selectedJob.value = null
  try {
    const res = await fetch(`/api/bulk-content/jobs/${id}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    selectedJob.value = await res.json()
  } catch {
    error.value = 'خطا در دریافت جزئیات دسته'
  } finally {
    detailLoading.value = false
  }
}

async function runJob(id: number) {
  error.value = ''
  try {
    const res = await fetch(`/api/bulk-content/jobs/${id}/run`, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = await res.json()
    if (!res.ok) {
      error.value = data.error ?? 'خطا در شروع پردازش'
      return
    }
    success.value = 'پردازش در پس‌زمینه شروع شد — صفحه هر چند ثانیه به‌روز می‌شود.'
    if (pollTimer) clearInterval(pollTimer)
    pollTimer = setInterval(async () => {
      await loadJobs()
      if (selectedJob.value) await openJob(selectedJob.value.job.id)
    }, 5000)
  } catch {
    error.value = 'خطا در شروع پردازش'
  }
}

/** P2.6 — انتشار یک آیتم به وردپرس (با گیت کیفیت در سمت سرور) */
async function publishItem(item: BulkItem) {
  if (!item.draft_id) {
    error.value = 'این آیتم پیش‌نویسی ندارد — ابتدا پردازش را اجرا کنید.'
    return
  }
  if (item.publish_status === 'published') return
  error.value = ''
  success.value = ''
  publishingItemIds.value.push(item.id)
  try {
    const res = await fetch(`/api/bulk-content/items/${item.id}/publish`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ status: 'draft' }),
    })
    const data = await res.json()
    if (!res.ok || data.success === false) {
      error.value = `انتشار «${item.keyword}» رد شد: ${data.error ?? 'گیت کیفیت'}`
    } else {
      success.value = `«${item.keyword}» به وردپرس ارسال شد${data.post_id ? ` (پست #${data.post_id})` : ''} — وضعیت پیش‌نویس برای بررسی نهایی.`
    }
    if (selectedJob.value) await openJob(selectedJob.value.job.id)
  } catch {
    error.value = 'خطای شبکه در انتشار'
  } finally {
    publishingItemIds.value = publishingItemIds.value.filter((x) => x !== item.id)
  }
}

/** P2.6 — انتشار گروهی همهٔ آیتم‌های آمادهٔ یک دسته */
async function publishAll(jobId: number) {
  error.value = ''
  success.value = ''
  publishing.value = true
  try {
    const res = await fetch(`/api/bulk-content/jobs/${jobId}/publish`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ status: 'draft' }),
    })
    const data = await res.json()
    if (!res.ok) {
      error.value = data.error ?? 'خطا در انتشار گروهی'
      return
    }
    success.value = `انتشار گروهی: ${data.published} موفق، ${data.failed} ردشده (گیت کیفیت)، ${data.skipped} از قبل منتشرشده.`
    if (data.failed > 0) {
      error.value = (data.results ?? [])
        .filter((r: any) => !r.success)
        .map((r: any) => `«${r.keyword}»: ${r.error ?? 'نامشخص'}`)
        .join(' | ')
    }
    if (selectedJob.value) await openJob(selectedJob.value.job.id)
  } catch {
    error.value = 'خطای شبکه در انتشار گروهی'
  } finally {
    publishing.value = false
  }
}

const publishBadge = (s: string | null) => {
  const map: Record<string, { tone: string; label: string }> = {
    published: { tone: 'success', label: 'منتشر شد' },
    publishing: { tone: 'info', label: 'در حال انتشار' },
    failed: { tone: 'danger', label: 'رد شد' },
  }
  return map[s ?? ''] ?? null
}

onMounted(() => {
  loadJobs()
  loadSites()
})
onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-6 p-6">
    <div>
      <h1 class="text-2xl font-bold">تولید گروهی محتوا</h1>
      <p class="text-ink-muted mt-1 text-sm">
        چند کیوورد را یکجا تولید کنید — هر آیتم کل خط لوله (کیوورد → محتوا → لینک درون‌متنی → گیت
        کیفیت) را طی می‌کند.
      </p>
    </div>

    <div v-if="error" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">
      {{ error }}
    </div>
    <div v-if="success" class="rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-700">
      {{ success }}
    </div>

    <!-- P2.8 — کارت گزارش کیفیت -->
    <div v-if="qualityReport && qualityReport.total > 0" class="rounded-xl border p-5">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-bold">📈 گزارش کیفیت تولید گروهی</h2>
        <span class="text-ink-muted text-xs">بر اساس همهٔ آیتم‌های پردازش‌شده</span>
      </div>
      <div class="grid gap-3 sm:grid-cols-4">
        <div class="rounded-lg bg-surface-muted p-3">
          <p class="text-ink-muted text-xs font-bold">نرخ انتشار</p>
          <p class="mt-1 text-2xl font-black">{{ qualityReport.publish_rate }}%</p>
        </div>
        <div class="rounded-lg bg-surface-muted p-3">
          <p class="text-ink-muted text-xs font-bold">میانگین امتیاز کیفیت</p>
          <p class="mt-1 text-2xl font-black">{{ qualityReport.avg_quality_score ?? '—' }}</p>
        </div>
        <div class="rounded-lg bg-surface-muted p-3">
          <p class="text-ink-muted text-xs font-bold">کل آیتم‌ها</p>
          <p class="mt-1 text-2xl font-black">{{ qualityReport.total }}</p>
        </div>
        <div class="rounded-lg bg-surface-muted p-3">
          <p class="text-ink-muted text-xs font-bold">شکست تولید</p>
          <p class="mt-1 text-2xl font-black">{{ qualityReport.failed_generation }}</p>
        </div>
      </div>
      <div v-if="Object.keys(qualityReport.rejection_reasons).length > 0" class="mt-3">
        <p class="text-ink-muted mb-1 text-xs font-bold">دلایل رایج توقف/رد شدن:</p>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="(count, reason) in qualityReport.rejection_reasons"
            :key="reason"
            class="rounded-full bg-amber-50 px-2.5 py-1 text-xs text-amber-800"
          >
            {{ reason }} ({{ count }})
          </span>
        </div>
      </div>
      <div v-if="qualityReport.suggestions.length > 0" class="mt-3 rounded-lg border border-brand-200 bg-brand-50 p-3">
        <p class="mb-1 text-xs font-bold text-brand-700">💡 پیشنهادهای بهبود:</p>
        <ul class="space-y-1 text-xs text-brand-800">
          <li v-for="(s, i) in qualityReport.suggestions" :key="i">• {{ s }}</li>
        </ul>
      </div>
    </div>

    <!-- P2.7 — کارت آمار انتشار -->
    <div class="rounded-xl border p-5">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-bold">📊 آمار انتشار</h2>
        <span class="text-ink-muted text-xs">گیت کیفیت قبل از هر انتشار اجرا می‌شود</span>
      </div>
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
          <p class="text-emerald-800 text-xs font-bold">✅ منتشر شده در وردپرس</p>
          <p class="mt-1 text-2xl font-black text-emerald-700">{{ stats.published }}</p>
        </div>
        <div class="rounded-lg border border-red-200 bg-red-50 p-3">
          <p class="text-red-800 text-xs font-bold">🚫 رد شده توسط گیت</p>
          <p class="mt-1 text-2xl font-black text-red-700">{{ stats.publish_failed }}</p>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
          <p class="text-amber-800 text-xs font-bold">🟡 صف بازبینی</p>
          <p class="mt-1 text-2xl font-black text-amber-700">{{ stats.review_queue }}</p>
        </div>
      </div>
      <div v-if="stats.recent_published.length > 0" class="mt-3">
        <p class="text-ink-muted mb-1 text-xs font-bold">آخرین انتشارها:</p>
        <div class="space-y-1">
          <a
            v-for="p in stats.recent_published"
            :key="p.id"
            :href="p.post_url ?? '#'"
            target="_blank"
            rel="noopener"
            class="flex items-center justify-between rounded-md bg-surface-muted px-3 py-1.5 text-xs hover:bg-brand-50"
          >
            <span class="truncate font-medium">{{ p.keyword }}</span>
            <span class="text-ink-muted shrink-0">
              {{ p.site_name }}{{ p.post_id ? ` · پست #${p.post_id}` : '' }}
              {{ p.published_at ? ` · ${new Date(p.published_at).toLocaleDateString('fa-IR')}` : '' }}
            </span>
          </a>
        </div>
      </div>
    </div>

    <!-- فرم ساخت -->
    <div class="rounded-xl border p-5">
      <h2 class="mb-3 font-bold">➕ دستهٔ جدید</h2>
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="text-ink-muted mb-1 block text-xs">سایت</label>
          <select v-model="form.site_id" class="w-full rounded-lg border p-2 text-sm">
            <option :value="0" disabled>انتخاب سایت</option>
            <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-ink-muted mb-1 block text-xs">نام دسته (اختیاری)</label>
          <input v-model="form.name" type="text" class="w-full rounded-lg border p-2 text-sm" placeholder="مثلاً: مقالات سلامت پاییز" />
        </div>
        <div>
          <label class="text-ink-muted mb-1 block text-xs">نوع محتوا</label>
          <select v-model="form.content_type" class="w-full rounded-lg border p-2 text-sm">
            <option value="article">مقاله</option>
            <option value="product">محصول</option>
          </select>
        </div>
        <div>
          <label class="text-ink-muted mb-1 block text-xs">زیرنوع</label>
          <select v-model="form.subtype" class="w-full rounded-lg border p-2 text-sm">
            <option value="guide">راهنمای خرید / مشاوره</option>
            <option value="tutorial">آموزشی</option>
            <option value="how_to">چگونگی / راهنما</option>
            <option value="comparison">مقایسه‌ای</option>
            <option value="review">بررسی / نقد</option>
            <option value="listicle">لیستی (بهترین‌ها)</option>
            <option value="pillar">راهنمای جامع (پیلار)</option>
            <option value="faq">سؤالات متداول</option>
          </select>
        </div>
        <div>
          <label class="text-ink-muted mb-1 block text-xs">انتشار خودکار (P2.7)</label>
          <select v-model="form.auto_publish" class="w-full rounded-lg border p-2 text-sm">
            <option value="off">خاموش — فقط تولید، انتشار دستی</option>
            <option value="draft">پیش‌نویس وردپرس (امن — بررسی نهایی انسانی)</option>
            <option value="publish">منتشر مستقیم (فقط خروجی عبورکرده از گیت)</option>
          </select>
          <p class="text-ink-muted mt-1 text-xs">
            آیتم‌های عبورکرده از گیت کیفیت خودکار منتشر می‌شوند؛ ردشده‌ها در صف بازبینی می‌مانند.
          </p>
        </div>
        <div>
          <label class="text-ink-muted mb-1 block text-xs">زمان شروع پردازش (P2.8 — اختیاری)</label>
          <input v-model="form.scheduled_at" type="datetime-local" class="w-full rounded-lg border p-2 text-sm" />
          <p class="text-ink-muted mt-1 text-xs">خالی = بلافاصله · زمان‌بند کرون هر ۵ دقیقه اجرا می‌شود.</p>
        </div>
        <div>
          <label class="text-ink-muted mb-1 block text-xs">سقف انتشار روزانه (P2.8 — اختیاری)</label>
          <input
            v-model.number="form.daily_publish_limit"
            type="number"
            min="0"
            max="100"
            class="w-full rounded-lg border p-2 text-sm"
          />
          <p class="text-ink-muted mt-1 text-xs">
            ۰ = بدون محدودیت · با سقف، مازاد هر روز تولید می‌شود ولی در صف انتشار می‌ماند.
          </p>
        </div>
      </div>
      <div class="mt-3">
        <label class="text-ink-muted mb-1 block text-xs"
          >کیووردها (هر کدام در یک خط — حداکثر ۳۰)</label
        >
        <textarea
          v-model="form.keywordsText"
          rows="4"
          class="w-full rounded-lg border p-2 text-sm"
          placeholder="راهنمای خرید کرم ضد آفتاب&#10;بهترین مکمل کلاژن برای پوست&#10;مقایسه ساعت هوشمند اپل و سامسونگ"
        ></textarea>
        <p class="text-ink-muted mt-1 text-xs">تعداد: {{ keywordList.length }} کیوورد</p>
      </div>
      <button
        :disabled="creating || keywordList.length === 0"
        class="mt-3 rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
        @click="createJob"
      >
        {{ creating ? 'در حال ساخت...' : 'ساخت دسته و آیتم‌ها' }}
      </button>
    </div>

    <!-- لیست دسته‌ها -->
    <div class="rounded-xl border p-5">
      <h2 class="mb-3 font-bold">دسته‌های تولید</h2>
      <div v-if="jobs.length === 0" class="text-ink-muted py-6 text-center text-sm">
        هنوز دسته‌ای ساخته نشده — از فرم بالا شروع کنید.
      </div>
      <div v-else class="space-y-3">
        <div
          v-for="j in jobs"
          :key="j.id"
          class="cursor-pointer rounded-lg border p-3 transition-colors hover:border-brand-300"
          @click="openJob(j.id)"
        >
          <div class="flex items-center justify-between">
            <div class="flex min-w-0 items-center gap-2">
              <span class="truncate font-bold">{{ j.name }}</span>
              <span class="text-ink-muted shrink-0 text-xs">#{{ j.id }} · {{ j.content_type }}</span>
              <span
                v-if="j.auto_publish === 'publish'"
                class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700"
              >
                ⚡ انتشار خودکار: منتشر
              </span>
              <span
                v-else-if="j.auto_publish === 'draft'"
                class="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700"
              >
                ⚡ انتشار خودکار: پیش‌نویس
              </span>
              <span
                v-if="j.scheduled_at"
                class="shrink-0 rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold text-violet-700"
              >
                ⏰ {{ new Date(j.scheduled_at).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' }) }}
              </span>
              <span
                v-if="j.daily_publish_limit > 0"
                class="shrink-0 rounded-full bg-cyan-100 px-2 py-0.5 text-[10px] font-bold text-cyan-700"
              >
                📅 حداکثر {{ j.daily_publish_limit }}/روز
              </span>
            </div>
            <span
              class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-bold"
              :class="{
                'bg-blue-100 text-blue-700': ['pending', 'running'].includes(j.status),
                'bg-green-100 text-green-700': j.status === 'completed',
                'bg-amber-100 text-amber-700': j.status === 'partial',
                'bg-red-100 text-red-700': j.status === 'failed',
              }"
            >
              {{ statusBadge(j.status).label }}
            </span>
          </div>
          <div class="mt-2">
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-muted">
              <div class="h-full rounded-full bg-brand-500 transition-all" :style="{ width: j.progress + '%' }" />
            </div>
            <p class="text-ink-muted mt-1 text-xs">
              {{ j.progress }}% — موفق: {{ j.completed_items }} | نیازمند بازبینی:
              {{ j.needs_review_items }} | ناموفق: {{ j.failed_items }} | کل: {{ j.total_items }}
            </p>
          </div>
          <div class="mt-2 flex gap-2">
            <button
              v-if="['pending', 'failed', 'partial'].includes(j.status)"
              class="rounded-md bg-brand-600 px-3 py-1 text-xs font-bold text-white hover:bg-brand-700"
              @click.stop="runJob(j.id)"
            >
              ▶ شروع پردازش
            </button>
            <button
              v-else-if="j.status === 'running'"
              class="rounded-md bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700"
              @click.stop="openJob(j.id)"
            >
              ⏳ در حال اجرا...
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- جزئیات -->
    <div v-if="detailLoading" class="text-ink-muted py-8 text-center">در حال دریافت جزئیات...</div>
    <div v-else-if="selectedJob" class="rounded-xl border p-5">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-bold">📋 {{ selectedJob.job.name }} (#{{ selectedJob.job.id }})</h2>
        <div class="text-ink-muted flex items-center gap-2 text-xs">
          <span
            v-if="selectedJob.job.auto_publish === 'publish'"
            class="rounded-full bg-emerald-100 px-2 py-0.5 font-bold text-emerald-700"
          >
            ⚡ انتشار خودکار: منتشر مستقیم
          </span>
          <span
            v-else-if="selectedJob.job.auto_publish === 'draft'"
            class="rounded-full bg-blue-100 px-2 py-0.5 font-bold text-blue-700"
          >
            ⚡ انتشار خودکار: پیش‌نویس وردپرس
          </span>
          <span v-else class="rounded-full bg-surface-muted px-2 py-0.5">انتشار دستی</span>
        </div>
        <div class="flex items-center gap-2">
          <button
            :disabled="publishing || !selectedJob.items.some((i) => i.draft_id && i.publish_status !== 'published')"
            class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
            @click="publishAll(selectedJob.job.id)"
          >
            {{ publishing ? 'در حال انتشار...' : '🚀 انتشار همهٔ آماده‌ها (پیش‌نویس وردپرس)' }}
          </button>
          <button class="text-ink-muted text-sm" @click="selectedJob = null">✕ بستن</button>
        </div>
      </div>
      <div class="mb-3 flex flex-wrap gap-1.5">
        <button
          v-for="f in [
            { key: 'all', label: 'همه' },
            { key: 'published', label: '✅ منتشر شده' },
            { key: 'failed', label: '🚫 رد شده' },
            { key: 'pending', label: '⬜ در انتظار انتشار' },
          ]"
          :key="f.key"
          class="rounded-full px-3 py-1 text-xs font-bold transition-colors"
          :class="
            publishFilter === f.key
              ? 'bg-brand-600 text-white'
              : 'bg-surface-muted text-ink-muted hover:bg-brand-50'
          "
          @click="publishFilter = f.key as any"
        >
          {{ f.label }}
        </button>
      </div>
      <div class="space-y-2">
        <div
          v-for="item in filteredItems"
          :key="item.id"
          class="flex items-center justify-between rounded-lg border p-2.5 text-sm"
        >
          <div class="flex min-w-0 items-center gap-2">
            <span class="shrink-0">
              {{
                item.status === 'completed' ? '✅' : item.status === 'failed' ? '❌' : item.status === 'needs_review' ? '🟡' : item.status === 'processing' ? '⏳' : '⬜'
              }}
            </span>
            <div class="min-w-0">
              <div class="truncate font-medium">{{ item.keyword }}</div>
              <div v-if="item.error" class="text-xs text-red-600">{{ item.error }}</div>
              <div
                v-if="item.status === 'needs_review' && longTailSuggestions(item).length > 0"
                class="mt-1 flex flex-wrap items-center gap-1.5"
              >
                <span class="text-ink-muted text-xs">پیشنهاد:</span>
                <button
                  v-for="s in longTailSuggestions(item)"
                  :key="s"
                  class="rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 transition-colors hover:bg-violet-100"
                  :disabled="reworkingItemIds.includes(item.id)"
                  @click="reworkItem(item, s)"
                >
                  🔄 {{ s }}
                </button>
              </div>
              <div v-else class="text-ink-muted text-xs">
                <span v-if="item.word_count">{{ item.word_count }} واژه</span>
                <span v-if="item.quality_score"> · امتیاز: {{ item.quality_score }}</span>
                <span v-if="item.source"> · {{ item.source === 'rule_based' ? 'نسخه دمو' : item.source }}</span>
                <a
                  v-if="item.draft_id"
                  :href="`/app/ai-drafts`"
                  class="text-brand-600 mr-1 underline"
                >
                  · پیش‌نویس #{{ item.draft_id }}
                </a>
                <span v-if="item.post_id" class="mr-1 text-emerald-700">· پست وردپرس #{{ item.post_id }}</span>
              </div>
              <div v-if="item.publish_error" class="mt-0.5 text-xs text-red-600">
                🚫 {{ item.publish_error }}
              </div>
            </div>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <span
              v-if="publishBadge(item.publish_status)"
              class="rounded-full px-2 py-0.5 text-xs font-bold"
              :class="{
                'bg-green-100 text-green-700': item.publish_status === 'published',
                'bg-blue-100 text-blue-700': item.publish_status === 'publishing',
                'bg-red-100 text-red-700': item.publish_status === 'failed',
              }"
            >
              {{ publishBadge(item.publish_status)!.label }}
            </span>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-bold"
              :class="{
                'bg-green-100 text-green-700': item.status === 'completed',
                'bg-amber-100 text-amber-700': item.status === 'needs_review',
                'bg-red-100 text-red-700': item.status === 'failed',
                'bg-blue-100 text-blue-700': ['pending', 'processing'].includes(item.status),
              }"
            >
              {{ statusBadge(item.status).label }}
            </span>
            <button
              v-if="item.status === 'needs_review' && longTailSuggestions(item).length === 0"
              class="rounded-md bg-violet-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-50"
              :disabled="reworkingItemIds.includes(item.id)"
              @click="reworkItem(item)"
            >
              {{ reworkingItemIds.includes(item.id) ? '...' : '🔄 بازتولید' }}
            </button>
            <button
              v-if="item.status === 'failed'"
              class="rounded-md bg-violet-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-50"
              :disabled="reworkingItemIds.includes(item.id)"
              @click="reworkItem(item)"
            >
              {{ reworkingItemIds.includes(item.id) ? '...' : '🔄 بازتولید' }}
            </button>
            <button
              v-if="item.draft_id && item.publish_status !== 'published'"
              class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
              :disabled="publishingItemIds.includes(item.id)"
              @click="publishItem(item)"
            >
              {{ publishingItemIds.includes(item.id) ? '...' : 'انتشار' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>