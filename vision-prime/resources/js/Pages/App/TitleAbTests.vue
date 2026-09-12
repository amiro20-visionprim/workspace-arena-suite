<script setup lang="ts">
import AppLayout from '@/app/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'

interface Variant {
  id: number
  title: string
  impressions: number
  clicks: number
  ctr: number
  confidence: number
  is_original: boolean
  is_winner: boolean
}

interface Test {
  id: number
  original_title: string
  status: string
  variants_count: number
  started_at: string | null
  ended_at: string | null
  winner_variant_id: number | null
  created_at: string
}

interface TestDetail {
  id: number
  original_title: string
  status: string
  variants: Variant[]
  significance: {
    confidence: number
    winner_id: number | null
    details: Variant[]
  }
}

interface Summary {
  total_tests: number
  running: number
  completed: number
  avg_ctr_improvement: number
}

const tests = ref<Test[]>([])
const summary = ref<Summary | null>(null)
const loading = ref(true)
const error = ref('')
const selectedTest = ref<TestDetail | null>(null)
const showCreateModal = ref(false)
const filterStatus = ref('')

// فرم ایجاد تست
const newTest = ref({
  title: '',
  variant_titles: [''],
  min_impressions: 100,
  duration_days: 7,
  auto_update: false,
  wordpress_post_id: null as number | null,
})

const statusLabels: Record<string, string> = {
  draft: 'پیش‌نویس',
  running: 'در حال اجرا',
  paused: 'متوقف',
  completed: 'تمام شده',
  cancelled: 'لغو شده',
}

const statusColors: Record<string, string> = {
  draft: 'bg-gray-500/20 text-gray-400',
  running: 'bg-green-500/20 text-green-400',
  paused: 'bg-yellow-500/20 text-yellow-400',
  completed: 'bg-blue-500/20 text-blue-400',
  cancelled: 'bg-red-500/20 text-red-400',
}

async function fetchTests() {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams()
    if (filterStatus.value) params.set('status', filterStatus.value)

    const res = await fetch(`/api/title-ab-tests?${params}`, { headers: { Accept: 'application/json' } })
    const data = await res.json()
    summary.value = data.summary
    tests.value = data.tests
  } catch (e: any) {
    error.value = e.message || 'خطا در دریافت اطلاعات'
  } finally {
    loading.value = false
  }
}

async function fetchTestDetail(id: number) {
  try {
    const res = await fetch(`/api/title-ab-tests/${id}`, { headers: { Accept: 'application/json' } })
    selectedTest.value = await res.json()
  } catch (e: any) {
    error.value = e.message || 'خطا در دریافت جزئیات'
  }
}

async function createTest() {
  try {
    const variants = newTest.value.variant_titles.filter(v => v.trim() !== '')
    if (variants.length === 0) {
      error.value = 'حداقل یک عنوان جایگزین وارد کنید'
      return
    }

    const res = await fetch('/api/title-ab-tests', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        title: newTest.value.title,
        variant_titles: variants,
        min_impressions: newTest.value.min_impressions,
        duration_days: newTest.value.duration_days,
        auto_update: newTest.value.auto_update,
        wordpress_post_id: newTest.value.wordpress_post_id,
      }),
    })

    if (res.ok) {
      showCreateModal.value = false
      newTest.value = { title: '', variant_titles: [''], min_impressions: 100, duration_days: 7, auto_update: false, wordpress_post_id: null }
      fetchTests()
    } else {
      const data = await res.json()
      error.value = data.error || 'خطا در ایجاد تست'
    }
  } catch (e: any) {
    error.value = e.message || 'خطا در ایجاد تست'
  }
}

async function startTest(id: number) {
  await fetch(`/api/title-ab-tests/${id}/start`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  fetchTests()
  if (selectedTest.value?.id === id) fetchTestDetail(id)
}

async function pauseTest(id: number) {
  await fetch(`/api/title-ab-tests/${id}/pause`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  fetchTests()
  if (selectedTest.value?.id === id) fetchTestDetail(id)
}

function addVariant() {
  if (newTest.value.variant_titles.length < 5) {
    newTest.value.variant_titles.push('')
  }
}

function removeVariant(index: number) {
  newTest.value.variant_titles.splice(index, 1)
}

const applyWinnerPostId = ref(0)
const applying = ref(false)

function getCtrBar(ctr: number, maxCtr: number): string {
  if (maxCtr === 0) return '0%'
  return `${Math.min(100, (ctr / maxCtr) * 100)}%`
}

async function applyWinnerToWordpress(testId: number, variantId: number) {
  if (!applyWinnerPostId.value) {
    error.value = 'شناسه پست وردپرس را وارد کنید'
    return
  }
  applying.value = true
  try {
    const res = await fetch(`/api/title-ab-tests/${testId}/apply-winner`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ variant_id: variantId, wordpress_post_id: applyWinnerPostId.value }),
    })
    const data = await res.json()
    if (res.ok) {
      selectedTest.value = null
      fetchTests()
    } else {
      error.value = data.error || 'خطا در اعمال برنده'
    }
  } catch (e: any) {
    error.value = e.message || 'خطا در اعمال برنده'
  } finally {
    applying.value = false
  }
}

onMounted(fetchTests)
</script>

<template>
  <AppLayout>
    <Head title="A/B تست عنوان" />

    <div class="p-6 max-w-7xl mx-auto space-y-6" dir="rtl">
      <!-- هدر -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-white">A/B تست عنوان</h1>
          <p class="text-sm text-slate-400 mt-1">تست همزمان چند عنوان + اندازه‌گیری CTR + پیشنهاد خودکار</p>
        </div>
        <button
          @click="showCreateModal = true"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium transition-colors"
        >
          + تست جدید
        </button>
      </div>

      <!-- خلاصه -->
      <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
          <div class="text-2xl font-bold text-white">{{ summary.total_tests }}</div>
          <div class="text-xs text-slate-400 mt-1">کل تست‌ها</div>
        </div>
        <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
          <div class="text-2xl font-bold text-green-400">{{ summary.running }}</div>
          <div class="text-xs text-slate-400 mt-1">در حال اجرا</div>
        </div>
        <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
          <div class="text-2xl font-bold text-blue-400">{{ summary.completed }}</div>
          <div class="text-xs text-slate-400 mt-1">تمام شده</div>
        </div>
        <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
          <div class="text-2xl font-bold text-yellow-400">+{{ summary.avg_ctr_improvement }}%</div>
          <div class="text-xs text-slate-400 mt-1">میانگین بهبود CTR</div>
        </div>
      </div>

      <!-- فیلتر -->
      <div class="flex gap-2">
        <button
          v-for="status in ['', 'running', 'completed', 'draft']"
          :key="status"
          @click="filterStatus = status; fetchTests()"
          :class="[
            'px-3 py-1.5 rounded-lg text-sm transition-colors',
            filterStatus === status
              ? 'bg-indigo-600 text-white'
              : 'bg-slate-800 text-slate-400 hover:bg-slate-700'
          ]"
        >
          {{ status ? statusLabels[status] : 'همه' }}
        </button>
      </div>

      <!-- خطا -->
      <div v-if="error" class="bg-red-500/10 border border-red-500/30 rounded-lg p-3 text-red-400 text-sm">
        {{ error }}
      </div>

      <!-- لیست تست‌ها -->
      <div v-if="loading" class="text-center text-slate-400 py-8">در حال بارگذاری...</div>

      <div v-else-if="tests.length === 0" class="text-center text-slate-500 py-12">
        هیچ تستی ایجاد نشده
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="test in tests"
          :key="test.id"
          @click="fetchTestDetail(test.id)"
          class="bg-slate-800/50 rounded-xl p-4 border border-slate-700 hover:border-indigo-500/50 cursor-pointer transition-all"
        >
          <div class="flex items-center justify-between">
            <div class="flex-1">
              <div class="flex items-center gap-3">
                <span
                  :class="['px-2 py-0.5 rounded text-xs font-medium', statusColors[test.status]]"
                >
                  {{ statusLabels[test.status] }}
                </span>
                <span class="text-white font-medium">{{ test.original_title }}</span>
              </div>
              <div class="text-xs text-slate-500 mt-2">
                {{ test.variants_count }} عنوان | ایجاد: {{ new Date(test.created_at).toLocaleDateString('fa-IR') }}
                <span v-if="test.started_at"> | شروع: {{ new Date(test.started_at).toLocaleDateString('fa-IR') }}</span>
              </div>
            </div>
            <div class="flex gap-2">
              <button
                v-if="test.status === 'draft'"
                @click.stop="startTest(test.id)"
                class="px-3 py-1 bg-green-600 hover:bg-green-500 text-white rounded text-xs"
              >
                شروع
              </button>
              <button
                v-if="test.status === 'running'"
                @click.stop="pauseTest(test.id)"
                class="px-3 py-1 bg-yellow-600 hover:bg-yellow-500 text-white rounded text-xs"
              >
                توقف
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- مودال جزئیات تست -->
      <div v-if="selectedTest" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50" @click.self="selectedTest = null">
        <div class="bg-slate-900 rounded-2xl p-6 max-w-3xl w-full mx-4 max-h-[80vh] overflow-y-auto border border-slate-700">
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white">جزئیات تست</h2>
            <button @click="selectedTest = null" class="text-slate-400 hover:text-white text-2xl">&times;</button>
          </div>

          <div class="mb-4">
            <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusColors[selectedTest.status]]">
              {{ statusLabels[selectedTest.status] }}
            </span>
            <span class="text-slate-400 text-sm mr-3">عنوان اصلی: {{ selectedTest.original_title }}</span>
          </div>

          <!-- اطمینان آماری -->
          <div v-if="selectedTest.significance" class="bg-slate-800/50 rounded-lg p-4 mb-6 border border-slate-700">
            <div class="text-sm text-slate-400 mb-2">اطمینان آماری</div>
            <div class="text-3xl font-bold" :class="selectedTest.significance.confidence >= 95 ? 'text-green-400' : 'text-yellow-400'">
              {{ selectedTest.significance.confidence }}%
            </div>
            <div v-if="selectedTest.significance.confidence >= 95" class="text-xs text-green-400 mt-1">
              ✅ نتیجه قابل اعتماد — برنده مشخص شده
            </div>
            <div v-else class="text-xs text-yellow-400 mt-1">
              ⏳ هنوز به اطمینان کافی نرسیده (حداقل ۹۵٪)
            </div>
          </div>

          <!-- متغیرها -->
          <div class="space-y-3">
            <div
              v-for="variant in (selectedTest.significance?.details || selectedTest.variants)"
              :key="variant.id"
              :class="[
                'rounded-lg p-4 border',
                variant.is_winner ? 'border-green-500 bg-green-500/10' :
                variant.is_original ? 'border-slate-600 bg-slate-800/30' :
                'border-slate-700 bg-slate-800/50'
              ]"
            >
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                  <span v-if="variant.is_winner" class="text-green-400 text-sm font-bold">🏆 برنده</span>
                  <span v-if="variant.is_original" class="text-slate-500 text-xs">(اصلی)</span>
                  <span class="text-white font-medium">{{ variant.title }}</span>
                </div>
                <span class="text-lg font-bold" :class="variant.ctr > 0 ? 'text-green-400' : 'text-slate-500'">
                  {{ variant.ctr }}%
                </span>
              </div>
              <div class="flex items-center gap-4 text-xs text-slate-400">
                <span>👁 {{ variant.impressions.toLocaleString() }} نمایش</span>
                <span>👆 {{ variant.clicks.toLocaleString() }} کلیک</span>
                <span>📊 CTR: {{ variant.ctr }}%</span>
              </div>
              <!-- نوار CTR -->
              <div class="mt-2 h-2 bg-slate-700 rounded-full overflow-hidden">
                <div
                  class="h-full rounded-full transition-all"
                  :class="variant.is_winner ? 'bg-green-500' : variant.is_original ? 'bg-slate-500' : 'bg-indigo-500'"
                  :style="{ width: getCtrBar(variant.ctr, Math.max(...(selectedTest.significance?.details || selectedTest.variants).map((v: any) => v.ctr), 1)) }"
                ></div>
              </div>
            </div>
          </div>

          <div class="mt-6 space-y-4">
            <!-- اعمال برنده در وردپرس -->
            <div v-if="selectedTest.status === 'completed' && selectedTest.significance?.winner_id" class="bg-slate-800/50 rounded-lg p-4 border border-green-500/30">
              <div class="text-sm text-green-400 font-medium mb-3">🏆 اعمال عنوان برنده در وردپرس</div>
              <div class="flex gap-2">
                <input
                  v-model.number="applyWinnerPostId"
                  type="number"
                  placeholder="شناسه پست وردپرس"
                  class="flex-1 bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-green-500"
                />
                <button
                  @click="applyWinnerToWordpress(selectedTest.id, selectedTest.significance.winner_id)"
                  :disabled="applying"
                  class="px-4 py-2 bg-green-600 hover:bg-green-500 text-white rounded-lg text-sm font-medium disabled:opacity-50"
                >
                  {{ applying ? 'در حال اعمال...' : 'اعمال در وردپرس' }}
                </button>
              </div>
            </div>

            <div class="flex justify-center gap-3">
              <button
                v-if="selectedTest.status === 'completed'"
                @click="selectedTest = null"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm"
              >
                بستن
              </button>
              <button
                v-else-if="selectedTest.status === 'running'"
                @click="pauseTest(selectedTest.id); selectedTest = null"
                class="px-4 py-2 bg-yellow-600 hover:bg-yellow-500 text-white rounded-lg text-sm"
              >
                توقف تست
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- مودال ایجاد تست -->
      <div v-if="showCreateModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50" @click.self="showCreateModal = false">
        <div class="bg-slate-900 rounded-2xl p-6 max-w-lg w-full mx-4 border border-slate-700">
          <h2 class="text-xl font-bold text-white mb-6">ایجاد تست A/B</h2>

          <div class="space-y-4">
            <div>
              <label class="block text-sm text-slate-400 mb-1">عنوان اصلی</label>
              <input
                v-model="newTest.title"
                type="text"
                class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-indigo-500"
                placeholder="عنوان فعلی محتوا"
              />
            </div>

            <div>
              <label class="block text-sm text-slate-400 mb-1">عنوان‌های جایگزین</label>
              <div v-for="(variant, i) in newTest.variant_titles" :key="i" class="flex gap-2 mb-2">
                <input
                  v-model="newTest.variant_titles[i]"
                  type="text"
                  class="flex-1 bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-indigo-500"
                  :placeholder="`عنوان جایگزین ${i + 1}`"
                />
                <button
                  v-if="newTest.variant_titles.length > 1"
                  @click="removeVariant(i)"
                  class="px-2 text-red-400 hover:text-red-300"
                >
                  ✕
                </button>
              </div>
              <button
                v-if="newTest.variant_titles.length < 5"
                @click="addVariant"
                class="text-xs text-indigo-400 hover:text-indigo-300"
              >
                + افزودن عنوان
              </button>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm text-slate-400 mb-1">حداقل نمایش</label>
                <input
                  v-model.number="newTest.min_impressions"
                  type="number"
                  min="10"
                  max="10000"
                  class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-indigo-500"
                />
              </div>
              <div>
                <label class="block text-sm text-slate-400 mb-1">مدت تست (روز)</label>
                <input
                  v-model.number="newTest.duration_days"
                  type="number"
                  min="1"
                  max="30"
                  class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-indigo-500"
                />
              </div>
            </div>

            <div class="flex items-center gap-3 p-3 bg-slate-800/50 rounded-lg border border-slate-700">
              <input
                v-model="newTest.auto_update"
                type="checkbox"
                class="w-4 h-4 text-green-500 bg-slate-700 border-slate-600 rounded focus:ring-green-500"
              />
              <div>
                <div class="text-sm text-white">بروزرسانی خودکار در وردپرس</div>
                <div class="text-xs text-slate-500">وقتی برنده مشخص شد، عنوان پست خودکار آپدیت بشه</div>
              </div>
            </div>

            <div v-if="newTest.auto_update">
              <label class="block text-sm text-slate-400 mb-1">شناسه پست وردپرس</label>
              <input
                v-model.number="newTest.wordpress_post_id"
                type="number"
                min="1"
                class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-indigo-500"
                placeholder="مثلاً ۱۲۳"
              />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button
              @click="createTest"
              class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium"
            >
              ایجاد تست
            </button>
            <button
              @click="showCreateModal = false"
              class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm"
            >
              انصراف
            </button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
