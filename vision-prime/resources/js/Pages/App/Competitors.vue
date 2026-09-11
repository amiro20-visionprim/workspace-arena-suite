<script setup lang="ts">
import AppLayout from '@/app/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'

interface Competitor {
  id: number
  name: string
  url: string
  pages_crawled: number
  keywords_found: number
  crawled_at: string | null
}

interface CompetitorDetail {
  competitor: { id: number; name: string; url: string }
  pages: any[]
  keywords: any[]
  type_distribution: Record<string, number>
  avg_word_count: number
  total_pages: number
}

interface Comparison {
  our_site: { pages: number; keywords: number }
  competitors: any[]
}

const competitors = ref<Competitor[]>([])
const loading = ref(true)
const adding = ref(false)
const crawling = ref<number | null>(null)
const detail = ref<CompetitorDetail | null>(null)
const comparison = ref<Comparison | null>(null)
const showCompare = ref(false)

const newName = ref('')
const newUrl = ref('')
const error = ref('')

async function fetchCompetitors() {
  loading.value = true
  try {
    const res = await fetch('/api/competitors')
    const data = await res.json()
    competitors.value = data.competitors
  } catch (e: any) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function addCompetitor() {
  if (!newName.value || !newUrl.value) return
  adding.value = true
  error.value = ''
  try {
    const res = await fetch('/api/competitors', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ name: newName.value, url: newUrl.value }),
    })
    const data = await res.json()
    if (data.ok) {
      newName.value = ''
      newUrl.value = ''
      fetchCompetitors()
    } else {
      error.value = data.error || 'خطا در افزودن رقیب'
    }
  } catch (e: any) {
    error.value = e.message
  } finally {
    adding.value = false
  }
}

async function crawlCompetitor(id: number) {
  crawling.value = id
  try {
    const comp = competitors.value.find(c => c.id === id)
    if (comp) {
      const res = await fetch('/api/competitors', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ name: comp.name, url: comp.url }),
      })
      await res.json()
      fetchCompetitors()
    }
  } finally {
    crawling.value = null
  }
}

async function showDetail(id: number) {
  const res = await fetch(`/api/competitors/${id}`)
  detail.value = await res.json()
}

async function fetchComparison() {
  showCompare.value = true
  const res = await fetch('/api/competitors/compare')
  comparison.value = await res.json()
}

async function deleteCompetitor(id: number) {
  if (!confirm('آیا از حذف این رقیب مطمئن هستید؟')) return
  await fetch(`/api/competitors/${id}`, { method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  detail.value = null
  fetchCompetitors()
}

onMounted(fetchCompetitors)
</script>

<template>
  <AppLayout>
    <Head title="مدیریت رقبا" />

    <div class="p-6 max-w-7xl mx-auto space-y-6" dir="rtl">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-white">🏁 مدیریت رقبا</h1>
          <p class="text-zinc-400 mt-1">کرال و تحلیل ساختار محتوای سایت‌های رقیب</p>
        </div>
        <button @click="fetchComparison" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-lg text-sm transition">
          📊 مقایسه
        </button>
      </div>

      <!-- فرم افزودن رقیب -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-5">
        <h3 class="text-white font-medium mb-3">افزودن رقیب جدید</h3>
        <form @submit.prevent="addCompetitor" class="flex gap-3 flex-wrap">
          <input v-model="newName" placeholder="نام رقیب (مثلاً: دیجی‌کالا)" required
            class="bg-zinc-800 border border-zinc-700 text-zinc-300 rounded-lg px-4 py-2 text-sm flex-1 min-w-[200px]" />
          <input v-model="newUrl" placeholder="آدرس سایت (https://example.com)" required type="url"
            class="bg-zinc-800 border border-zinc-700 text-zinc-300 rounded-lg px-4 py-2 text-sm flex-1 min-w-[300px]" />
          <button type="submit" :disabled="adding"
            class="px-6 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm transition disabled:opacity-50">
            {{ adding ? 'در حال کرال...' : '🔍 کرال و افزودن' }}
          </button>
        </form>
        <p v-if="error" class="text-red-400 text-sm mt-2">{{ error }}</p>
      </div>

      <!-- لیست رقبا -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-800">
          <h3 class="text-white font-medium">لیست رقبا ({{ competitors.length }})</h3>
        </div>
        <div v-if="loading" class="p-8 text-center text-zinc-500">در حال بارگذاری...</div>
        <div v-else-if="competitors.length === 0" class="p-8 text-center text-zinc-500">هنوز رقیبی اضافه نشده</div>
        <div v-else class="divide-y divide-zinc-800/50">
          <div v-for="comp in competitors" :key="comp.id"
            class="px-5 py-4 hover:bg-zinc-800/30 transition flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
              <h4 class="text-white font-medium">{{ comp.name }}</h4>
              <p class="text-zinc-500 text-sm truncate">{{ comp.url }}</p>
              <div class="flex gap-4 mt-1 text-xs text-zinc-400">
                <span>📄 {{ comp.pages_crawled }} صفحه</span>
                <span>🔑 {{ comp.keywords_found }} کلمه کلیدی</span>
                <span v-if="comp.crawled_at">🕐 {{ new Date(comp.crawled_at).toLocaleDateString('fa-IR') }}</span>
              </div>
            </div>
            <div class="flex gap-2 shrink-0">
              <button @click="showDetail(comp.id)"
                class="text-xs px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded transition">
                جزئیات
              </button>
              <button @click="crawlCompetitor(comp.id)" :disabled="crawling === comp.id"
                class="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded transition disabled:opacity-50">
                {{ crawling === comp.id ? '...' : '🔄 کرال مجدد' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- مقایسه -->
      <div v-if="showCompare && comparison" class="bg-zinc-900 border border-zinc-800 rounded-xl p-5">
        <h3 class="text-white font-medium mb-4">📊 مقایسه با رقبا</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-zinc-800">
                <th class="text-right text-zinc-400 py-2 px-3">معیار</th>
                <th class="text-center text-blue-400 py-2 px-3">ما</th>
                <th v-for="comp in comparison.competitors" :key="comp.id"
                  class="text-center text-zinc-300 py-2 px-3">{{ comp.name }}</th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b border-zinc-800/50">
                <td class="text-zinc-400 py-2 px-3">تعداد صفحات</td>
                <td class="text-center text-blue-400 py-2 px-3">{{ comparison.our_site.pages }}</td>
                <td v-for="comp in comparison.competitors" :key="comp.id"
                  class="text-center text-zinc-300 py-2 px-3">{{ comp.their_pages }}</td>
              </tr>
              <tr class="border-b border-zinc-800/50">
                <td class="text-zinc-400 py-2 px-3">میانگین کلمات</td>
                <td class="text-center text-blue-400 py-2 px-3">—</td>
                <td v-for="comp in comparison.competitors" :key="comp.id"
                  class="text-center text-zinc-300 py-2 px-3">{{ comp.their_avg_words }}</td>
              </tr>
              <tr class="border-b border-zinc-800/50">
                <td class="text-zinc-400 py-2 px-3">کلمات کلیدی</td>
                <td class="text-center text-blue-400 py-2 px-3">{{ comparison.our_site.keywords }}</td>
                <td v-for="comp in comparison.competitors" :key="comp.id"
                  class="text-center text-zinc-300 py-2 px-3">{{ comp.their_keywords }}</td>
              </tr>
              <tr>
                <td class="text-zinc-400 py-2 px-3">شکاف‌های محتوایی</td>
                <td class="text-center text-blue-400 py-2 px-3">—</td>
                <td v-for="comp in comparison.competitors" :key="comp.id"
                  class="text-center text-yellow-400 py-2 px-3 font-bold">{{ comp.content_gaps }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- جزئیات رقیب -->
      <div v-if="detail" class="bg-zinc-900 border border-zinc-800 rounded-xl p-5">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-white font-medium">🔍 جزئیات: {{ detail.competitor.name }}</h3>
          <div class="flex gap-2">
            <button @click="detail = null" class="text-xs px-3 py-1.5 bg-zinc-800 text-zinc-400 rounded">بستن</button>
            <button @click="deleteCompetitor(detail.competitor.id)" class="text-xs px-3 py-1.5 bg-red-600/20 text-red-400 rounded">حذف</button>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-4">
          <div class="bg-zinc-800/50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-white">{{ detail.total_pages }}</div>
            <div class="text-zinc-400 text-xs">صفحه</div>
          </div>
          <div class="bg-zinc-800/50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-white">{{ detail.avg_word_count }}</div>
            <div class="text-zinc-400 text-xs">میانگین کلمات</div>
          </div>
          <div class="bg-zinc-800/50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-white">{{ detail.keywords.length }}</div>
            <div class="text-zinc-400 text-xs">کلمه کلیدی</div>
          </div>
        </div>

        <!-- کلمات کلیدی -->
        <h4 class="text-zinc-300 text-sm font-medium mb-2">🔑 کلمات کلیدی برتر</h4>
        <div class="flex flex-wrap gap-2 mb-4">
          <span v-for="kw in detail.keywords.slice(0, 20)" :key="kw.keyword"
            class="text-xs px-2 py-1 bg-zinc-800 text-zinc-300 rounded-full">
            {{ kw.keyword }} <span class="text-zinc-500">×{{ kw.occurrences }}</span>
          </span>
        </div>

        <!-- صفحات -->
        <h4 class="text-zinc-300 text-sm font-medium mb-2">📄 صفحات ({{ detail.pages.length }})</h4>
        <div class="space-y-2 max-h-64 overflow-y-auto">
          <div v-for="page in detail.pages" :key="page.url"
            class="bg-zinc-800/30 rounded-lg p-3 flex items-center justify-between">
            <div class="flex-1 min-w-0">
              <div class="text-sm text-white truncate">{{ page.title || page.url }}</div>
              <div class="text-xs text-zinc-500 truncate">{{ page.url }}</div>
            </div>
            <div class="flex gap-3 text-xs text-zinc-400 shrink-0">
              <span>{{ page.word_count }} واژه</span>
              <span>{{ page.h2_count }} H2</span>
              <span v-if="page.has_schema" class="text-emerald-400">✓ اسکیما</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
