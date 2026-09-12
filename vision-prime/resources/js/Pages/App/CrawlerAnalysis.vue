<script setup lang="ts">
import AppLayout from '@/app/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'

interface Summary {
  total_opportunities: number
  open_opportunities: number
  processed_opportunities: number
  total_risks: number
  open_risks: number
  avg_score: number
  total_profiles: number
  last_crawl: string | null
}

interface Opportunity {
  id: number
  type: string
  source: string
  title: string
  keyword_suggested: string | null
  score: number
  confidence: number
  status: string
  explanation: string
  url: string | null
  depth: number | null
  days_since_update: number | null
  density: number | null
}

interface Risk {
  id: number
  key: string
  severity: string
  explanation: string
  url: string | null
}

const summary = ref<Summary | null>(null)
const opportunities = ref<Opportunity[]>([])
const risks = ref<Risk[]>([])
const typeDistribution = ref<Record<string, number>>({})
const sourceDistribution = ref<Record<string, number>>({})
const loading = ref(true)
const error = ref('')

// فیلترها
const filterType = ref('')
const filterSource = ref('')
const filterMinScore = ref(0)
const filterSearch = ref('')

const typeLabels: Record<string, string> = {
  keyword_opportunity: 'کلیدواژه',
  content_gap: 'محتوا',
  interlink_opportunity: 'لینک داخلی',
  meta_optimization: 'متادیتا',
  schema_gap: 'اسکیما',
  accessibility: 'دسترسی‌پذیری',
}

const sourceLabels: Record<string, string> = {
  crawler_h2_pattern: 'هدینگ H2',
  crawler_thin_content: 'محتوای کم‌عمق',
  crawler_missing_meta: 'متادیتای خالی',
  crawler_missing_schema: 'اسکیمای ناقص',
  crawler_images_no_alt: 'تصاویر بدون Alt',
  analyzer_orphan: 'صفحه یتیم',
  analyzer_unreachable: 'غیرقابل دسترس',
  analyzer_depth: 'عمق صفحه',
  analyzer_freshness: 'تازگی محتوا',
  analyzer_density_low: 'تراکم پایین',
}

const severityColors: Record<string, string> = {
  high: 'text-red-400 bg-red-500/20',
  medium: 'text-yellow-400 bg-yellow-500/20',
  low: 'text-green-400 bg-green-500/20',
}

const typeColors: Record<string, string> = {
  keyword_opportunity: '#667eea',
  content_gap: '#764ba2',
  interlink_opportunity: '#f093fb',
  meta_optimization: '#4facfe',
  schema_gap: '#43e97b',
  accessibility: '#fa709a',
}

async function fetchData() {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams()
    if (filterType.value) params.set('type', filterType.value)
    if (filterSource.value) params.set('source', filterSource.value)
    if (filterMinScore.value > 0) params.set('min_score', String(filterMinScore.value))
    if (filterSearch.value) params.set('search', filterSearch.value)

    const res = await fetch(`/api/crawler/analysis?${params}`, { headers: { Accept: 'application/json' } })
    const data = await res.json()
    summary.value = data.summary
    opportunities.value = data.opportunities.items
    risks.value = data.risks
    typeDistribution.value = data.type_distribution
    sourceDistribution.value = data.source_distribution
  } catch (e: any) {
    error.value = e.message || 'خطا در دریافت اطلاعات'
  } finally {
    loading.value = false
  }
}

async function updateStatus(id: number, status: string) {
  await fetch(`/api/crawler/opportunities/${id}/status`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ status }),
  })
  fetchData()
}

async function createBulkJob(id: number) {
  const res = await fetch(`/api/crawler/opportunities/${id}/create-job`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
  const data = await res.json()
  if (data.ok) {
    alert(`دسته تولید #${data.job_id} ساخته شد`)
    fetchData()
  }
}

function scoreColor(score: number): string {
  if (score >= 80) return 'text-emerald-400'
  if (score >= 60) return 'text-yellow-400'
  return 'text-red-400'
}

function scoreBarWidth(score: number): string {
  return `${Math.min(score, 100)}%`
}

onMounted(fetchData)
</script>

<template>
  <AppLayout>
    <Head title="تحلیل کرالر هوشمند" />

    <div class="p-6 max-w-7xl mx-auto space-y-6" dir="rtl">
      <!-- هدر -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-white">🔍 تحلیل کرالر هوشمند</h1>
          <p class="text-zinc-400 mt-1">فرصت‌ها و ریسک‌های شناسایی‌شده توسط کرالر داخلی</p>
        </div>
        <button @click="fetchData" :disabled="loading"
          class="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded-lg text-sm transition disabled:opacity-50">
          🔄 بروزرسانی
        </button>
      </div>

      <!-- کارت‌های خلاصه -->
      <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">فرصت‌های باز</div>
          <div class="text-3xl font-bold text-white mt-1">{{ summary.open_opportunities }}</div>
          <div class="text-zinc-500 text-xs mt-1">از {{ summary.total_opportunities }} کل</div>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">امتیاز میانگین</div>
          <div class="text-3xl font-bold mt-1" :class="scoreColor(summary.avg_score)">{{ summary.avg_score }}</div>
          <div class="text-zinc-500 text-xs mt-1">از ۱۰۰</div>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">ریسک‌های باز</div>
          <div class="text-3xl font-bold text-red-400 mt-1">{{ summary.open_risks }}</div>
          <div class="text-zinc-500 text-xs mt-1">نیاز به بررسی</div>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">صفحات کرال‌شده</div>
          <div class="text-3xl font-bold text-blue-400 mt-1">{{ summary.total_profiles }}</div>
          <div class="text-zinc-500 text-xs mt-1">آخرین کرال: {{ summary.last_crawl ? new Date(summary.last_crawl).toLocaleDateString('fa-IR') : '—' }}</div>
        </div>
      </div>

      <!-- نمودار توزیع + ریسک‌ها -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- نمودار نوع فرصت -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5">
          <h3 class="text-white font-medium mb-4">📊 توزیع انواع فرصت</h3>
          <div class="space-y-3">
            <div v-for="(count, type) in typeDistribution" :key="type" class="flex items-center gap-3">
              <div class="w-32 text-sm text-zinc-400 truncate">{{ typeLabels[type as string] || type }}</div>
              <div class="flex-1 h-6 bg-zinc-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all duration-500"
                  :style="{ width: `${Math.min((count as number) / (summary?.total_opportunities || 1) * 100, 100)}%`, backgroundColor: typeColors[type as string] || '#666' }">
                </div>
              </div>
              <div class="w-10 text-left text-sm text-zinc-300">{{ count }}</div>
            </div>
          </div>
        </div>

        <!-- ریسک‌ها -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5">
          <h3 class="text-white font-medium mb-4">⚠️ ریسک‌های فعال</h3>
          <div v-if="risks.length === 0" class="text-zinc-500 text-sm">ریسک فعالی وجود ندارد</div>
          <div v-else class="space-y-3">
            <div v-for="risk in risks.slice(0, 6)" :key="risk.id"
              class="bg-zinc-800/50 rounded-lg p-3 border border-zinc-700/50">
              <div class="flex items-center justify-between mb-1">
                <span class="text-sm text-white">{{ risk.key }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full" :class="severityColors[risk.severity]">
                  {{ risk.severity === 'high' ? 'بالا' : risk.severity === 'medium' ? 'متوسط' : 'پایین' }}
                </span>
              </div>
              <p class="text-xs text-zinc-400">{{ risk.explanation }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- فیلترها -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4 flex flex-wrap gap-3 items-center">
        <select v-model="filterType" @change="fetchData"
          class="bg-zinc-800 border border-zinc-700 text-zinc-300 rounded-lg px-3 py-2 text-sm">
          <option value="">همه انواع</option>
          <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filterSource" @change="fetchData"
          class="bg-zinc-800 border border-zinc-700 text-zinc-300 rounded-lg px-3 py-2 text-sm">
          <option value="">همه منابع</option>
          <option v-for="(label, key) in sourceLabels" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filterMinScore" @change="fetchData"
          class="bg-zinc-800 border border-zinc-700 text-zinc-300 rounded-lg px-3 py-2 text-sm">
          <option :value="0">همه امتیازها</option>
          <option :value="50">امتیاز ≥ ۵۰</option>
          <option :value="70">امتیاز ≥ ۷۰</option>
          <option :value="85">امتیاز ≥ ۸۵</option>
        </select>
        <input v-model="filterSearch" @input="fetchData" placeholder="جستجو..."
          class="bg-zinc-800 border border-zinc-700 text-zinc-300 rounded-lg px-3 py-2 text-sm flex-1 min-w-[200px]" />
      </div>

      <!-- لیست فرصت‌ها -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-800 flex items-center justify-between">
          <h3 class="text-white font-medium">🎯 فرصت‌های شناسایی‌شده ({{ opportunities.length }})</h3>
        </div>
        <div v-if="loading" class="p-8 text-center text-zinc-500">در حال بارگذاری...</div>
        <div v-else-if="error" class="p-8 text-center text-red-400">{{ error }}</div>
        <div v-else-if="opportunities.length === 0" class="p-8 text-center text-zinc-500">فرصتی یافت نشد</div>
        <div v-else class="divide-y divide-zinc-800/50">
          <div v-for="opp in opportunities" :key="opp.id"
            class="px-5 py-4 hover:bg-zinc-800/30 transition group">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-xs px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-400">
                    {{ typeLabels[opp.type] || opp.type }}
                  </span>
                  <span class="text-xs text-zinc-500">
                    {{ sourceLabels[opp.source] || opp.source }}
                  </span>
                </div>
                <h4 class="text-white text-sm font-medium truncate">{{ opp.title }}</h4>
                <p class="text-zinc-400 text-xs mt-1 line-clamp-2">{{ opp.explanation }}</p>
                <div v-if="opp.url" class="text-zinc-500 text-xs mt-1 truncate">🔗 {{ opp.url }}</div>
              </div>
              <div class="flex flex-col items-end gap-2 shrink-0">
                <div class="flex items-center gap-2">
                  <div class="w-16 h-2 bg-zinc-800 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all" :class="scoreColor(opp.score)"
                      :style="{ width: scoreBarWidth(opp.score), backgroundColor: 'currentColor' }"></div>
                  </div>
                  <span class="text-sm font-bold" :class="scoreColor(opp.score)">{{ opp.score }}%</span>
                </div>
                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                  <button @click="createBulkJob(opp.id)" v-if="opp.status === 'open'"
                    class="text-xs px-2 py-1 bg-blue-600 hover:bg-blue-500 text-white rounded transition">
                    تولید
                  </button>
                  <button @click="updateStatus(opp.id, 'suppressed')" v-if="opp.status === 'open'"
                    class="text-xs px-2 py-1 bg-zinc-700 hover:bg-zinc-600 text-zinc-300 rounded transition">
                    سرکوب
                  </button>
                  <button @click="updateStatus(opp.id, 'open')" v-if="opp.status !== 'open'"
                    class="text-xs px-2 py-1 bg-zinc-700 hover:bg-zinc-600 text-zinc-300 rounded transition">
                    بازگردانی
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
