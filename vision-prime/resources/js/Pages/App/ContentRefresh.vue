<script setup lang="ts">
import AppLayout from '@/app/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'

interface TitleAnalysis {
  draft_id: number
  current_title: string
  current_score: number
  current_reasons: string[]
  alternatives: { title: string; score: number; reasons: string[] }[]
  keywords: string[]
}

interface RefreshCandidate {
  id: number
  title: string
  quality_score?: number
  days_since_update?: number
  length?: number
  reason: string
}

interface Summary {
  total_drafts: number
  avg_quality: number
  needs_refresh: number
  stale_count: number
  low_quality_count: number
  weak_title_count: number
}

const summary = ref<Summary | null>(null)
const stale = ref<RefreshCandidate[]>([])
const lowQuality = ref<RefreshCandidate[]>([])
const weakTitles = ref<RefreshCandidate[]>([])
const loading = ref(true)
const selectedDraft = ref<TitleAnalysis | null>(null)
const applyingTitle = ref<number | null>(null)

const priorityColors: Record<string, string> = {
  high: 'text-red-400 bg-red-500/20',
  medium: 'text-yellow-400 bg-yellow-500/20',
  low: 'text-green-400 bg-green-500/20',
}

const typeLabels: Record<string, string> = {
  title: 'عنوان',
  content_length: 'طول محتوا',
  quality: 'کیفیت',
}

async function fetchData() {
  loading.value = true
  try {
    const [summaryRes, staleRes] = await Promise.all([
      fetch('/api/content/refresh/summary', { headers: { Accept: 'application/json' } }),
      fetch('/api/content/refresh', { headers: { Accept: 'application/json' } }),
    ])
    summary.value = await summaryRes.json()
    const data = await staleRes.json()
    stale.value = data.stale || []
    lowQuality.value = data.low_quality || []
    weakTitles.value = data.weak_titles || []
  } finally {
    loading.value = false
  }
}

async function optimizeTitle(draftId: number) {
  const res = await fetch(`/api/content/refresh/${draftId}/optimize`)
  selectedDraft.value = await res.json()
}

async function applyTitle(draftId: number, title: string) {
  applyingTitle.value = draftId
  try {
    await fetch(`/api/content/refresh/${draftId}/apply-title`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ title }),
    })
    selectedDraft.value = null
    fetchData()
  } finally {
    applyingTitle.value = null
  }
}

function scoreColor(score: number): string {
  if (score >= 80) return 'text-emerald-400'
  if (score >= 60) return 'text-yellow-400'
  return 'text-red-400'
}

onMounted(fetchData)
</script>

<template>
  <AppLayout>
    <Head title="بروزرسانی محتوا" />

    <div class="p-6 max-w-7xl mx-auto space-y-6" dir="rtl">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-white">🔄 بروزرسانی محتوا + A/B تست عنوان</h1>
          <p class="text-zinc-400 mt-1">شناسایی محتوای قدیمی و بهینه‌سازی عنوان‌ها</p>
        </div>
        <button @click="fetchData" :disabled="loading"
          class="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded-lg text-sm transition disabled:opacity-50">
          🔄 بروزرسانی
        </button>
      </div>

      <!-- کارت‌های خلاصه -->
      <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">کل پیش‌نویس‌ها</div>
          <div class="text-3xl font-bold text-white mt-1">{{ summary.total_drafts }}</div>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">امتیاز میانگین</div>
          <div class="text-3xl font-bold mt-1" :class="scoreColor(summary.avg_quality)">{{ summary.avg_quality }}</div>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">نیاز به بروزرسانی</div>
          <div class="text-3xl font-bold text-yellow-400 mt-1">{{ summary.needs_refresh }}</div>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">عناوین ضعیف</div>
          <div class="text-3xl font-bold text-red-400 mt-1">{{ summary.weak_title_count }}</div>
        </div>
      </div>

      <!-- عناوین ضعیف -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-800">
          <h3 class="text-white font-medium">📝 عناوین ضعیف ({{ weakTitles.length }})</h3>
        </div>
        <div v-if="weakTitles.length === 0" class="p-8 text-center text-zinc-500">عنوان ضعیفی یافت نشد</div>
        <div v-else class="divide-y divide-zinc-800/50">
          <div v-for="item in weakTitles" :key="item.id"
            class="px-5 py-4 hover:bg-zinc-800/30 transition flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
              <h4 class="text-white text-sm font-medium truncate">{{ item.title || '(بدون عنوان)' }}</h4>
              <p class="text-zinc-500 text-xs mt-1">{{ item.reason }}</p>
            </div>
            <button @click="optimizeTitle(item.id)"
              class="text-xs px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded transition shrink-0">
              🔍 بهینه‌سازی عنوان
            </button>
          </div>
        </div>
      </div>

      <!-- محتوای کیفیت پایین -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-800">
          <h3 class="text-white font-medium">⚠️ کیفیت پایین ({{ lowQuality.length }})</h3>
        </div>
        <div v-if="lowQuality.length === 0" class="p-8 text-center text-zinc-500">محتوای کیفیت پایینی یافت نشد</div>
        <div v-else class="divide-y divide-zinc-800/50">
          <div v-for="item in lowQuality" :key="item.id"
            class="px-5 py-4 hover:bg-zinc-800/30 transition flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
              <h4 class="text-white text-sm font-medium truncate">{{ item.title }}</h4>
              <p class="text-zinc-500 text-xs mt-1">{{ item.reason }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <span class="text-sm font-bold text-red-400">{{ item.quality_score }}/۱۰۰</span>
              <button @click="optimizeTitle(item.id)"
                class="text-xs px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded transition">
                🔍 بهینه‌سازی
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- محتوای قدیمی -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-800">
          <h3 class="text-white font-medium">🕐 قدیمی ({{ stale.length }})</h3>
        </div>
        <div v-if="stale.length === 0" class="p-8 text-center text-zinc-500">محتوای قدیمی یافت نشد</div>
        <div v-else class="divide-y divide-zinc-800/50">
          <div v-for="item in stale" :key="item.id"
            class="px-5 py-4 hover:bg-zinc-800/30 transition flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
              <h4 class="text-white text-sm font-medium truncate">{{ item.title }}</h4>
              <p class="text-zinc-500 text-xs mt-1">{{ item.reason }}</p>
            </div>
            <button @click="optimizeTitle(item.id)"
              class="text-xs px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded transition shrink-0">
              🔍 بهینه‌سازی
            </button>
          </div>
        </div>
      </div>

      <!-- پنل بهینه‌سازی عنوان -->
      <div v-if="selectedDraft" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-6 max-w-2xl w-full max-h-[80vh] overflow-y-auto">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-white font-bold text-lg">🎯 بهینه‌سازی عنوان</h3>
            <button @click="selectedDraft = null" class="text-zinc-400 hover:text-white text-2xl">&times;</button>
          </div>

          <!-- عنوان فعلی -->
          <div class="bg-zinc-800/50 rounded-xl p-4 mb-4">
            <div class="text-zinc-400 text-sm mb-1">عنوان فعلی</div>
            <div class="text-white font-medium">{{ selectedDraft.current_title }}</div>
            <div class="flex items-center gap-2 mt-2">
              <div class="w-24 h-2 bg-zinc-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full" :class="scoreColor(selectedDraft.current_score)"
                  :style="{ width: `${selectedDraft.current_score}%`, backgroundColor: 'currentColor' }"></div>
              </div>
              <span class="text-sm" :class="scoreColor(selectedDraft.current_score)">{{ selectedDraft.current_score }}/۱۰۰</span>
            </div>
            <div class="mt-2 space-y-1">
              <div v-for="reason in selectedDraft.current_reasons" :key="reason"
                class="text-xs text-zinc-500">• {{ reason }}</div>
            </div>
          </div>

          <!-- کلمات کلیدی -->
          <div v-if="selectedDraft.keywords.length" class="mb-4">
            <div class="text-zinc-400 text-sm mb-2">🔑 کلمات کلیدی</div>
            <div class="flex flex-wrap gap-2">
              <span v-for="kw in selectedDraft.keywords" :key="kw"
                class="text-xs px-2 py-1 bg-zinc-800 text-zinc-300 rounded-full">{{ kw }}</span>
            </div>
          </div>

          <!-- عنوان‌های پیشنهادی -->
          <div class="space-y-3">
            <div class="text-zinc-400 text-sm mb-2">📝 عنوان‌های پیشنهادی</div>
            <div v-for="(alt, idx) in selectedDraft.alternatives" :key="idx"
              class="bg-zinc-800/50 rounded-xl p-4 border border-zinc-700/50 hover:border-purple-500/50 transition">
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                  <div class="text-white font-medium text-sm">{{ alt.title }}</div>
                  <div class="flex items-center gap-2 mt-1">
                    <div class="w-16 h-1.5 bg-zinc-700 rounded-full overflow-hidden">
                      <div class="h-full rounded-full" :class="scoreColor(alt.score)"
                        :style="{ width: `${alt.score}%`, backgroundColor: 'currentColor' }"></div>
                    </div>
                    <span class="text-xs" :class="scoreColor(alt.score)">{{ alt.score }}/۱۰۰</span>
                  </div>
                  <div class="mt-1 space-y-0.5">
                    <div v-for="reason in alt.reasons" :key="reason"
                      class="text-xs text-zinc-500">• {{ reason }}</div>
                  </div>
                </div>
                <button @click="applyTitle(selectedDraft.draft_id, alt.title)" :disabled="applyingTitle === selectedDraft.draft_id"
                  class="text-xs px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded transition shrink-0 disabled:opacity-50">
                  {{ applyingTitle === selectedDraft.draft_id ? '...' : '✅ استفاده' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
