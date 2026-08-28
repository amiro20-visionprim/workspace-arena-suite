import { ref } from 'vue'

/**
 * Image Picker — composable مشترک استودیوی محتوا v2.
 * سه منبع (استوک / تولید AI / پیشنهاد) + آپلود نهایی به رسانهٔ وردپرس در زمان انتشار.
 */
export interface StockPhoto {
  url: string
  thumb: string
  alt: string
  credit: string
  width: number | null
  height: number | null
  provider: string
}

export interface PickedImage {
  source: 'stock' | 'ai' | 'suggestion'
  assetId: number | null
  url: string | null
  alt: string
  credit: string
}

export function useImagePicker() {
  const tab = ref<'stock' | 'ai' | 'suggest'>('stock')
  const query = ref('')
  const searching = ref(false)
  const searchError = ref('')
  const results = ref<StockPhoto[]>([])
  const generating = ref(false)
  const generateError = ref('')
  const suggestions = ref<{ alt: string; query: string; aspect: string }[]>([])
  const picked = ref<PickedImage | null>(null)

  async function search(): Promise<void> {
    if (!query.value.trim()) return
    searching.value = true
    searchError.value = ''
    results.value = []
    try {
      const res = await fetch(`/api/content/images/search?q=${encodeURIComponent(query.value)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
      const data = (await res.json()) as {
        success: boolean
        results?: StockPhoto[]
        error?: string
        needs_setup?: boolean
      }
      if (data.success && data.results) {
        results.value = data.results
      } else {
        searchError.value = data.error ?? 'جستجو ناموفق بود.'
      }
    } catch {
      searchError.value = 'خطای شبکه'
    } finally {
      searching.value = false
    }
  }

  async function generate(
    prompt: string,
    size: '1536x1024' | '1024x1024',
    alt: string,
  ): Promise<void> {
    generating.value = true
    generateError.value = ''
    try {
      const res = await fetch('/api/content/images/generate', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ prompt, size, alt }),
      })
      const data = (await res.json()) as {
        success: boolean
        asset_id?: number
        url?: string | null
        error?: string
      }
      if (data.success) {
        picked.value = {
          source: 'ai',
          assetId: data.asset_id ?? null,
          url: data.url ?? null,
          alt,
          credit: 'تولید هوش مصنوعی — Vision Prime',
        }
      } else {
        generateError.value = data.error ?? 'تولید ناموفق بود.'
      }
    } catch {
      generateError.value = 'خطای شبکه'
    } finally {
      generating.value = false
    }
  }

  async function loadSuggestions(title: string, section = ''): Promise<void> {
    try {
      const res = await fetch(
        `/api/content/images/suggest?title=${encodeURIComponent(title)}&section=${encodeURIComponent(section)}`,
        { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
      )
      const data = (await res.json()) as {
        suggestions?: { alt: string; query: string; aspect: string }[]
      }
      suggestions.value = data.suggestions ?? []
    } catch {
      suggestions.value = []
    }
  }

  async function pickStock(
    photo: StockPhoto,
    draftId: number | null,
    slot: 'cover' | 'section' | 'gallery',
  ): Promise<void> {
    // ثبت دارایی + انتخاب
    try {
      const res = await fetch('/api/content/images/attach', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ photo, draft_id: draftId, register_only: true, slot }),
      })
      // attach انتظار asset_id دارد؛ برای استوک ابتدا ثبت می‌کنیم:
      void res
    } catch {
      /* best-effort */
    }
    picked.value = {
      source: 'stock',
      assetId: null,
      url: photo.url,
      alt: photo.alt,
      credit: photo.credit,
    }
  }

  return {
    tab,
    query,
    searching,
    searchError,
    results,
    generating,
    generateError,
    suggestions,
    picked,
    search,
    generate,
    loadSuggestions,
    pickStock,
  }
}
