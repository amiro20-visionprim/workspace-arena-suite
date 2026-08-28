<script setup lang="ts">
import { ref } from 'vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'

/**
 * انتخاب‌گر کاور (استودیوی محتوا v2):
 * سه منبع — استوک (رایگان) · تولید AI (سهمیهٔ هفتگی) · و به‌طور خودکار در انتشار،
 * به رسانهٔ وردپرس آپلود می‌شود (AutoCover دارایی متصل را اول انتخاب می‌کند).
 */
const props = defineProps<{
  draftId: number | null
  title: string
}>()

type Tab = 'stock' | 'ai'
const tab = ref<Tab>('stock')
const query = ref('')
const searching = ref(false)
const error = ref('')
const info = ref('')
interface StockPhoto {
  url: string
  thumb: string
  alt: string
  credit: string
  provider: string
}
const results = ref<StockPhoto[]>([])
const picked = ref<{ source: string; url: string | null; alt: string } | null>(null)
const busy = ref(false)

async function search(): Promise<void> {
  if (!query.value.trim()) return
  searching.value = true
  error.value = ''
  info.value = ''
  results.value = []
  try {
    const res = await fetch(`/api/content/images/search?q=${encodeURIComponent(query.value)}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = (await res.json().catch(() => ({}))) as {
      success?: boolean
      results?: StockPhoto[]
      error?: string
    }
    if (data.success && data.results) results.value = data.results
    else
      error.value =
        data.error ?? 'جستجو ناموفق بود — کلید استوک (Pexels) را در تنظیمات←یکپارچه‌سازی ثبت کنید.'
  } catch {
    error.value = 'خطای شبکه'
  } finally {
    searching.value = false
  }
}

async function pickStock(photo: StockPhoto): Promise<void> {
  if (!props.draftId) {
    error.value = 'ابتدا مقاله را تولید کنید تا پیش‌نویس ذخیره شود.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const res = await fetch('/api/content/images/attach-stock', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ photo, draft_id: props.draftId, slot: 'cover' }),
    })
    const data = (await res.json().catch(() => ({}))) as { success?: boolean; error?: string }
    if (data.success) {
      picked.value = { source: 'استوک', url: photo.url, alt: photo.alt }
      info.value = `کاور ثبت شد (${photo.credit}) — هنگام انتشار خودکار به رسانهٔ وردپرس آپلود می‌شود.`
    } else {
      error.value = data.error ?? 'ثبت کاور ناموفق بود.'
    }
  } catch {
    error.value = 'خطای شبکه'
  } finally {
    busy.value = false
  }
}

async function generateAi(): Promise<void> {
  if (!props.draftId) {
    error.value = 'ابتدا مقاله را تولید کنید تا پیش‌نویس ذخیره شود.'
    return
  }
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const res = await fetch('/api/content/images/generate', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        prompt: `کاور حرفه‌ای مقاله درباره: ${query.value || props.title}`,
        size: '1536x1024',
        alt: `کاور: ${props.title}`,
        draft_id: props.draftId,
      }),
    })
    const data = (await res.json().catch(() => ({}))) as {
      success?: boolean
      asset_id?: number
      url?: string | null
      error?: string
    }
    if (data.success && data.asset_id) {
      await fetch('/api/content/images/attach', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ asset_id: data.asset_id, draft_id: props.draftId, slot: 'cover' }),
      })
      picked.value = { source: 'هوش مصنوعی', url: data.url ?? null, alt: `کاور: ${props.title}` }
      info.value = 'کاور اختصاصی ساخته و متصل شد — هنگام انتشار به رسانهٔ وردپرس آپلود می‌شود.'
    } else {
      error.value =
        data.error ?? 'تولید ناموفق بود (سهمیهٔ هفتگی ۱۵ تصویر AI — استوک همیشه آزاد است).'
    }
  } catch {
    error.value = 'خطای شبکه'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <VCard title="🖼️ تصویر شاخص (کاور)">
    <div class="bg-surface-muted mb-4 flex rounded-xl p-1">
      <button
        type="button"
        class="flex-1 rounded-lg px-3 py-2 text-xs font-bold transition"
        :class="tab === 'stock' ? 'bg-brand-600 text-white' : 'text-ink-muted'"
        @click="tab = 'stock'"
      >
        🔎 استوک (رایگان)
      </button>
      <button
        type="button"
        class="flex-1 rounded-lg px-3 py-2 text-xs font-bold transition"
        :class="tab === 'ai' ? 'bg-brand-600 text-white' : 'text-ink-muted'"
        @click="tab = 'ai'"
      >
        ✨ تولید AI
      </button>
    </div>

    <div class="flex gap-2">
      <input
        v-model="query"
        :placeholder="
          tab === 'stock' ? 'کلیدواژه (مثلاً: سرم پوست، دکوراسیون…)' : 'موضوع کاور را بنویسید'
        "
        class="border-line focus:border-brand-600 flex-1 rounded-lg border px-3 py-2 text-sm outline-none"
        @keyup.enter="tab === 'stock' ? search() : generateAi()"
      />
      <VButton
        size="sm"
        :loading="searching || busy"
        @click="tab === 'stock' ? search() : generateAi()"
      >
        {{ tab === 'stock' ? 'جستجو' : 'تولید' }}
      </VButton>
    </div>

    <p v-if="error" class="mt-3 rounded-lg bg-red-50 p-2 text-xs leading-5 text-red-700">{{
      error
    }}</p>
    <p v-if="info" class="mt-3 rounded-lg bg-emerald-50 p-2 text-xs leading-5 text-emerald-700">{{
      info
    }}</p>

    <!-- کاور انتخاب‌شده -->
    <div v-if="picked" class="border-line mt-4 flex items-center gap-3 rounded-xl border p-3">
      <img
        v-if="picked.url"
        :src="picked.url"
        :alt="picked.alt"
        class="h-16 w-28 rounded-lg object-cover"
        loading="lazy"
      />
      <div
        v-else
        class="bg-brand-50 text-brand-700 flex h-16 w-28 items-center justify-center rounded-lg text-2xl"
      >
        ✨
      </div>
      <div class="text-xs">
        <p class="text-ink-strong font-semibold">کاور انتخاب شد — منبع: {{ picked.source }}</p>
        <p class="text-ink-muted mt-1">{{ picked.alt }}</p>
      </div>
    </div>

    <!-- نتایج استوک -->
    <div
      v-if="tab === 'stock' && results.length"
      class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4"
    >
      <button
        v-for="(p, i) in results"
        :key="p.url + i"
        type="button"
        class="group relative overflow-hidden rounded-lg"
        :disabled="busy"
        @click="pickStock(p)"
      >
        <img
          :src="p.thumb"
          :alt="p.alt"
          class="h-20 w-full object-cover transition group-hover:brightness-90"
          loading="lazy"
        />
        <span
          class="absolute inset-x-0 bottom-0 bg-black/60 px-1 py-0.5 text-[9px] text-white opacity-0 transition group-hover:opacity-100"
          >انتخاب</span
        >
      </button>
    </div>
  </VCard>
</template>
