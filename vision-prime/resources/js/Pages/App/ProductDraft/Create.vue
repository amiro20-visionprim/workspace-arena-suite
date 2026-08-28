<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '@/app/layouts/AppLayout.vue'
import VAlert from '@/shared/ui/VAlert.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'
import VPageHeader from '@/shared/ui/VPageHeader.vue'
import VSelect from '@/shared/ui/VSelect.vue'

interface SiteOption {
  id: number
  name: string
  canonical_url: string
}
interface OutlineItem {
  heading: string
  level: 2 | 3
  note: string
}
interface DraftProfile {
  content_type?: string
  subtype?: string
  intent?: string
  title?: string
}
interface ExpertAnalysis {
  summary?: string
  strengths?: string[]
  weaknesses?: string[]
  recommendations?: string[]
}
interface SchemaItem {
  '@type': string
  [key: string]: unknown
}
interface QualityResult {
  passed: boolean
  score: number
  failures: string[]
  warnings: string[]
  readability?: { score?: number }
}
interface LinkSuggestion {
  url: string
  title: string
  anchor: string
  relevance_score: number
}
interface GeneratedResult {
  content: string
  model: string
  source: string
  meta_title: string
  meta_description: string
  schemas: SchemaItem[]
  links: LinkSuggestion[]
  quality: QualityResult
  profile: DraftProfile
  draft_id?: number
  expert_analysis?: ExpertAnalysis
}
interface PromptTemplate {
  id: number
  title: string
  content_type: string
  tone: string
  is_user_created: boolean
  tags: string[]
  usage_count: number
  avg_quality_score: number
  is_featured: boolean
}
interface SectionItem {
  heading: string
  level: number
  content: string
  regenerating: boolean
}

const p = defineProps<{
  sites: SiteOption[]
  subtypes: Record<string, string>
  standards: Record<string, unknown>
  isSuperAdmin: boolean
}>()

const page = usePage<{ flash?: { status?: string; error?: string } }>()

const studioMode = ref<'quick' | 'pro'>('quick') // استودیوی محصول v2
const step = ref<'input' | 'brief' | 'outline' | 'generating' | 'result'>('input')

// ─── بریف محصول (حالت حرفه‌ای) ───
interface ProductBrief {
  title: string
  target_query: string
  content_type: string
  subtype: string
  intent: string
  audience: string
  tone: string
  word_range: number[]
  required_elements: string[]
  gsc_queries: { query: string; impressions: number }[]
  internal_link_candidates: { url: string; title: string }[]
  woo: null | {
    title: string
    price: string | null
    regular_price: string | null
    sale_price: string | null
    currency: string | null
    stock_quantity: number | null
    stock_status: string | null
    in_stock: boolean | null
    url: string | null
  }
  notes: string
}
const brief = ref<ProductBrief | null>(null)
const briefLoading = ref(false)
const briefError = ref('')
const productUrl = ref('')
const customInstructions = ref('')

async function buildBrief(): Promise<void> {
  if (!selectedSiteId.value || !title.value.trim()) return
  briefLoading.value = true
  briefError.value = ''
  try {
    const res = await fetch('/api/content/brief', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        title: title.value,
        content_type: 'product',
        product_url: productUrl.value || undefined,
      }),
    })
    const data = (await res.json()) as { success: boolean; brief?: ProductBrief; error?: string }
    if (data.success && data.brief) {
      brief.value = data.brief
      step.value = 'brief'
    } else {
      briefError.value = data.error ?? 'ساخت بریف ناموفق بود.'
    }
  } catch {
    briefError.value = 'خطای شبکه'
  } finally {
    briefLoading.value = false
  }
}

// ─── دسته/برچسب محصول وردپرس ───
interface WpTerm {
  id: number
  name: string
  count?: number
}
const wpProductCats = ref<WpTerm[]>([])
const wpProductTags = ref<WpTerm[]>([])
const selectedCategoryIds = ref<number[]>([])
const selectedTagNames = ref<string[]>([])
const taxonomiesLoaded = ref(false)
const taxonomiesError = ref('')

async function loadTaxonomies(): Promise<void> {
  if (!selectedSiteId.value || taxonomiesLoaded.value) return
  taxonomiesError.value = ''
  try {
    const res = await fetch(`/app/sites/${selectedSiteId.value}/taxonomies?type=product`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = (await res.json()) as {
      success: boolean
      connected?: boolean
      product_cats?: WpTerm[]
      product_tags?: WpTerm[]
      error?: string
    }
    if (data.success) {
      wpProductCats.value = data.product_cats ?? []
      wpProductTags.value = data.product_tags ?? []
      taxonomiesLoaded.value = true
    } else {
      taxonomiesError.value = data.error ?? ''
    }
  } catch {
    taxonomiesError.value = 'خطای شبکه در دریافت دسته‌های محصول'
  }
}

function toggleCategory(id: number): void {
  const i = selectedCategoryIds.value.indexOf(id)
  if (i === -1) selectedCategoryIds.value.push(id)
  else selectedCategoryIds.value.splice(i, 1)
}

function toggleTag(name: string): void {
  const i = selectedTagNames.value.indexOf(name)
  if (i === -1) selectedTagNames.value.push(name)
  else selectedTagNames.value.splice(i, 1)
}
const selectedSiteId = ref('')
const title = ref('')
const price = ref('')
const salePrice = ref('')
const stockStatus = ref('in_stock')

// Templates
const templates = ref<PromptTemplate[]>([])
const selectedTemplateId = ref<number | null>(null)
const customPrompt = ref('')
const showCustomPrompt = ref(false)
const templatesLoading = ref(false)

// Outline
const outline = ref<OutlineItem[]>([])
const outlineLoading = ref(false)
const outlineError = ref('')
const outlineModel = ref('')

// Generation
const generatingLoading = ref(false)
const generatingStatus = ref('')
const result = ref<GeneratedResult | null>(null)
const activeResultTab = ref<'content' | 'meta' | 'seo' | 'schema' | 'sections'>('content')
const errorMsg = ref('')

// Draft
const currentDraftId = ref<number | null>(null)
const draftSaved = ref(false)

// Publish
const publishing = ref(false)
interface PublishResultData {
  success?: boolean
  error?: string
  post_url?: string
}
const publishResult = ref<PublishResultData | null>(null)
const showPublishDialog = ref(false)
const copyStatus = ref('')

// Sections
const sections = ref<SectionItem[]>([])
const showSectionEdit = ref(false)
const editSectionIndex = ref<number | null>(null)
const editSectionText = ref('')

// Auto detect
const autoDetectedSubtype = ref('')
const autoDetectedTone = ref('')

// Keyword
const keywordInput = ref('')
const applyingSuggestions = ref(false)

// Computed
const wordCount = computed(() => {
  if (!result.value?.content) return 0
  const plain = result.value.content.replace(/<[^>]+>/g, ' ').trim()
  return plain ? plain.split(/\s+/).filter((w: string) => w.length > 0).length : 0
})
const seoScore = computed(() => result.value?.quality?.score ?? 0)

const keywordDensity = computed(() => {
  const kw = keywordInput.value.trim()
  if (!kw || !result.value?.content) return { count: 0, density: 0 }
  const plain = result.value.content
    .replace(/<[^>]+>/g, ' ')
    .trim()
    .toLowerCase()
  const kwLower = kw.toLowerCase()
  let count = 0,
    pos = 0
  while ((pos = plain.indexOf(kwLower, pos)) !== -1) {
    count++
    pos += kwLower.length
  }
  const words = plain.split(/\s+/).filter((w: string) => w.length > 0)
  return { count, density: words.length > 0 ? Math.round((count / words.length) * 10000) / 100 : 0 }
})

const seoChecks = computed(() => {
  if (!result.value) return []
  const r = result.value
  return [
    { label: 'طول محتوا', value: wordCount.value + ' کلمه', passed: wordCount.value >= 80 },
    {
      label: 'Meta Title',
      value: (r.meta_title?.length ?? 0) + '/60',
      passed: (r.meta_title?.length ?? 0) >= 30 && (r.meta_title?.length ?? 0) <= 60,
    },
    {
      label: 'Meta Description',
      value: (r.meta_description?.length ?? 0) + '/160',
      passed: (r.meta_description?.length ?? 0) >= 120 && (r.meta_description?.length ?? 0) <= 160,
    },
    {
      label: 'اسکیما Product',
      value: (r.schemas?.length ?? 0) + ' عدد',
      passed: (r.schemas?.length ?? 0) >= 1,
    },
    {
      label: 'امتیاز کیفیت',
      value: (r.quality?.score ?? 0) + '/100',
      passed: (r.quality?.score ?? 0) >= 70,
    },
    {
      label: 'قیمت',
      value: price.value ? price.value + ' ریال' : 'ناموجود',
      passed: !!price.value,
    },
    {
      label: 'موجودی',
      value: stockStatus.value === 'in_stock' ? 'در انبار' : 'ناموجود',
      passed: true,
    },
  ]
})

// Template functions
async function fetchTemplates() {
  templatesLoading.value = true
  try {
    const res = await fetch('/api/content/prompt-templates?content_type=product', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (res.ok) templates.value = await res.json()
  } catch {
    /* نادیده گرفته شد */
  }
  templatesLoading.value = false
}
fetchTemplates()

function selectTemplate(t: PromptTemplate) {
  selectedTemplateId.value = selectedTemplateId.value === t.id ? null : t.id
}

// Auto detect subtype from title
function autoDetect(t: string) {
  const l = t.toLowerCase()
  if (/compare|comparison|مقایسه|vs|بهترین/.test(l)) {
    autoDetectedSubtype.value = 'comparison'
    autoDetectedTone.value = 'neutral'
  } else if (/review|نقد|بررسی/.test(l)) {
    autoDetectedSubtype.value = 'review'
    autoDetectedTone.value = 'professional'
  } else if (/buy|price|sale|خرید|قیمت|فروش/.test(l)) {
    autoDetectedSubtype.value = 'long_desc'
    autoDetectedTone.value = 'persuasive'
  } else if (/spec|مشخصات|فنی/.test(l)) {
    autoDetectedSubtype.value = 'specs'
    autoDetectedTone.value = 'neutral'
  } else {
    autoDetectedSubtype.value = 'short_desc'
    autoDetectedTone.value = 'persuasive'
  }
}

watch(title, (v) => {
  if (v && v.trim().length > 5) autoDetect(v)
})

// Generate Outline
/** استودیو v2 — دو حالته: quick = مستقیم، pro = بریف. */
async function fetchOutline(mode: 'quick' | 'pro'): Promise<void> {
  if (mode === 'pro') {
    if (brief.value === null) {
      await buildBrief()
      return
    }
    await generateOutline()
    return
  }
  await generateOutline()
}

async function generateOutline() {
  if (!selectedSiteId.value || !title.value.trim()) return
  outlineLoading.value = true
  outlineError.value = ''
  try {
    const res = await fetch('/api/content/outline', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        title: title.value.trim(),
        subtype: autoDetectedSubtype.value || undefined,
        template_id: selectedTemplateId.value || undefined,
        custom_prompt: customPrompt.value || undefined,
        tone: autoDetectedTone.value || undefined,
      }),
    })
    const data = await res.json()
    if (data.error) {
      outlineError.value = data.error
      outlineLoading.value = false
      return
    }
    outline.value = data.outline ?? []
    outlineModel.value = data.model ?? ''
    if (outline.value.length === 0) {
      outlineError.value = 'Outline خالی برگشت'
      outlineLoading.value = false
      return
    }
    step.value = 'outline'
  } catch (e) {
    outlineError.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
  }
  outlineLoading.value = false
}

// Generate Article (from outline)
async function generateFromOutline() {
  if (!selectedSiteId.value || !title.value.trim()) return
  generatingLoading.value = true
  generatingStatus.value = 'تولید محتوای محصول...'
  step.value = 'generating'
  try {
    const res = await fetch('/api/content/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        keyword: title.value.trim(),
        title: title.value.trim(),
        subtype: autoDetectedSubtype.value || undefined,
        template_id: selectedTemplateId.value || undefined,
        custom_prompt: customPrompt.value || undefined,
        tone: autoDetectedTone.value || undefined,
      }),
    })
    const d = await res.json()
    if (d.error) {
      errorMsg.value = d.error
      step.value = 'input'
      generatingLoading.value = false
      return
    }
    result.value = d
    currentDraftId.value = d.draft_id || null
    activeResultTab.value = 'content'
    step.value = 'result'
    parseSections(d.content)
  } catch (e) {
    errorMsg.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
    step.value = 'input'
  }
  generatingLoading.value = false
}

// Quick Generate (skip outline)
async function quickGenerate() {
  if (!selectedSiteId.value || !title.value.trim()) return
  generatingLoading.value = true
  generatingStatus.value = 'تولید سریع محصول...'
  step.value = 'generating'
  try {
    const res = await fetch('/api/content/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        keyword: title.value.trim(),
        title: title.value.trim(),
        subtype: autoDetectedSubtype.value || undefined,
        template_id: selectedTemplateId.value || undefined,
        custom_prompt: customPrompt.value || undefined,
        tone: autoDetectedTone.value || undefined,
      }),
    })
    const d = await res.json()
    if (d.error) {
      errorMsg.value = d.error
      step.value = 'input'
      generatingLoading.value = false
      return
    }
    result.value = d
    currentDraftId.value = d.draft_id || null
    activeResultTab.value = 'content'
    step.value = 'result'
    parseSections(d.content)
  } catch (e) {
    errorMsg.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
    step.value = 'input'
  }
  generatingLoading.value = false
}

function regenerate() {
  step.value = 'input'
  result.value = null
  currentDraftId.value = null
}
function goToOutline() {
  step.value = 'outline'
}

// Section parsing
function parseSections(html: string) {
  const re = /<h([2-3])[^>]*>(.*?)<\/h\1>/gi
  const parts: SectionItem[] = []
  let lastIdx = 0
  let m: RegExpExecArray | null
  while ((m = re.exec(html)) !== null) {
    if (parts.length > 0) parts[parts.length - 1].content = html.substring(lastIdx, m.index)
    parts.push({
      heading: m[2].replace(/<[^>]+>/g, ''),
      level: parseInt(m[1]),
      content: '',
      regenerating: false,
    })
    lastIdx = re.lastIndex
  }
  if (parts.length > 0) parts[parts.length - 1].content = html.substring(lastIdx)
  sections.value = parts
}

function editSection(i: number) {
  editSectionIndex.value = i
  editSectionText.value = sections.value[i].content
  showSectionEdit.value = true
}
function saveSectionEdit() {
  if (editSectionIndex.value === null || !result.value) return
  const s = sections.value[editSectionIndex.value]
  s.content = editSectionText.value
  // Rebuild content
  let html = result.value.content
  const re = new RegExp(
    `(<h${s.level}[^>]*>${s.heading.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}<\\/h${s.level}>)[\\s\\S]*?(?=<h[2-3]|$)`,
    'i',
  )
  result.value.content = html.replace(re, `$1\n${editSectionText.value}`)
  parseSections(result.value.content)
  showSectionEdit.value = false
}

async function regenerateSection(i: number) {
  const s = sections.value[i]
  s.regenerating = true
  try {
    const res = await fetch('/api/content/regenerate-section', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        content: result.value?.content || '',
        heading: s.heading,
        keyword: title.value,
      }),
    })
    const d = await res.json()
    if (d.content) {
      result.value!.content = d.content
      parseSections(d.content)
    }
  } catch {
    /* نادیده گرفته شد */
  }
  s.regenerating = false
}

// Save / Publish
async function saveCurrentDraft() {
  if (!result.value || !selectedSiteId.value) return
  try {
    const r = await fetch('/api/content/drafts', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        draft_id: currentDraftId.value || undefined,
        site_id: Number(selectedSiteId.value),
        title: title.value,
        content: result.value.content,
        meta_title: result.value.meta_title,
        meta_description: result.value.meta_description,
        subtype: autoDetectedSubtype.value || 'short_desc',
        quality_score: result.value.quality?.score || 0,
      }),
    })
    if (r.ok) {
      const d = await r.json()
      currentDraftId.value = d.id
      draftSaved.value = true
    }
  } catch {
    /* نادیده گرفته شد */
  }
}

async function copyHtml() {
  if (!result.value?.content) return
  try {
    await navigator.clipboard.writeText(result.value.content)
    copyStatus.value = 'HTML'
    setTimeout(() => (copyStatus.value = ''), 2000)
  } catch {
    /* نادیده گرفته شد */
  }
}
async function copyPlainText() {
  if (!result.value?.content) return
  const text = result.value.content
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  try {
    await navigator.clipboard.writeText(text)
    copyStatus.value = 'TEXT'
    setTimeout(() => (copyStatus.value = ''), 2000)
  } catch {
    /* نادیده گرفته شد */
  }
}

async function publishToWordPress(status: string) {
  publishing.value = true
  publishResult.value = null
  try {
    let draftId = currentDraftId.value
    if (!draftId) {
      const dr = await fetch('/api/content/drafts?search=' + encodeURIComponent(title.value), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
      const dd = await dr.json()
      draftId = dd.drafts?.[0]?.id
    }
    if (!draftId) {
      publishResult.value = { success: false, error: 'Draft یافت نشد' }
      publishing.value = false
      return
    }
    const res = await fetch('/api/content/publish-stored', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        draft_id: draftId,
        status,
        categories: selectedCategoryIds.value.length ? selectedCategoryIds.value : undefined,
        tags: selectedTagNames.value.length ? selectedTagNames.value : undefined,
      }),
    })
    publishResult.value = await res.json()
  } catch (e) {
    publishResult.value = { success: false, error: e instanceof Error ? e.message : String(e) }
  }
  publishing.value = false
}

async function applySuggestions(suggestions: string[]) {
  if (!result.value || suggestions.length === 0) return
  applyingSuggestions.value = true
  try {
    const r = await fetch('/api/content/apply-suggestions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        content: result.value.content,
        suggestions,
        title: title.value,
        keyword: title.value,
      }),
    })
    const d = await r.json()
    if (d.content) {
      result.value.content = d.content
      parseSections(d.content)
    }
  } catch {
    /* نادیده گرفته شد */
  }
  applyingSuggestions.value = false
}
</script>

<template>
  <Head title="تولید محصول هوشمند" />
  <AppLayout>
    <VPageHeader
      title="تولید محصول هوشمند"
      description="توضیح محصول با Meta Title/Description، اسکیمای Product و لینک‌سازی داخلی."
    />

    <VAlert v-if="page.props.flash?.status" tone="success" class="mt-6">{{
      page.props.flash.status
    }}</VAlert>
    <VAlert v-if="errorMsg" tone="danger" class="mt-6">{{ errorMsg }}</VAlert>

    <!-- Breadcrumb -->
    <div class="text-ink-muted mt-6 flex items-center gap-2 text-sm">
      <span :class="step === 'input' ? 'text-brand-700 font-bold' : ''">۱. ورودی</span>
      <span>›</span>
      <span :class="step === 'outline' ? 'text-brand-700 font-bold' : ''">۲. Outline</span>
      <span>›</span>
      <span :class="step === 'generating' || step === 'result' ? 'text-brand-700 font-bold' : ''"
        >۳. نتیجه</span
      >
    </div>

    <!-- STEP 1: INPUT -->
    <div v-if="step === 'input'" class="mx-auto mt-6 max-w-2xl">
      <!-- ═══ استودیوی محصول v2: انتخاب حالت ═══ -->
      <VCard v-if="step === 'input'" class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-ink-strong text-sm font-bold">حالت تولید محصول را انتخاب کنید</p>
            <p class="text-ink-muted mt-1 text-xs leading-6">
              ⚡ <b>سرعتی:</b> فقط نام محصول. &nbsp;·&nbsp; 🎯 <b>حرفه‌ای:</b> بریف + قیمت/موجودی
              واقعی ووکامرس + دسته‌بندی محصول.
            </p>
          </div>
          <div class="bg-surface-muted flex rounded-xl p-1">
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-xs font-bold transition"
              :class="studioMode === 'quick' ? 'bg-brand-600 text-white' : 'text-ink-muted'"
              @click="studioMode = 'quick'"
            >
              ⚡ سرعتی
            </button>
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-xs font-bold transition"
              :class="studioMode === 'pro' ? 'bg-brand-600 text-white' : 'text-ink-muted'"
              @click="studioMode = 'pro'"
            >
              🎯 حرفه‌ای
            </button>
          </div>
        </div>
        <!-- URL محصول برای بافت ووکامرس (حالت حرفه‌ای) -->
        <div v-if="studioMode === 'pro'" class="border-line mt-4 border-t pt-4">
          <label class="text-ink-muted mb-1 block text-xs font-medium"
            >نشانی محصول در وردپرس (اختیاری — برای خواندن قیمت/موجودی واقعی)</label
          >
          <input
            v-model="productUrl"
            dir="ltr"
            placeholder="https://shop.ir/product/serum/"
            class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
          />
        </div>
      </VCard>

      <VCard title="مشخصات محصول را وارد کنید">
        <div class="space-y-4">
          <VSelect
            v-model="selectedSiteId"
            label="سایت"
            :options="p.sites.map((s) => ({ label: s.name, value: String(s.id) }))"
            placeholder="انتخاب سایت"
          />

          <div>
            <label class="text-ink-strong text-sm font-semibold">عنوان محصول</label>
            <input
              v-model="title"
              dir="auto"
              placeholder="مثال: هدفون بی‌سیم پرو مکس"
              class="border-line mt-1 w-full rounded-xl border px-4 py-2.5 text-sm"
            />
            <p class="text-ink-muted mt-1 text-xs">
              بعد از وارد کردن عنوان، سیستم خودکار زیرنوع و لحن را تشخیص می‌دهد.
            </p>
          </div>

          <!-- Auto detect badge -->
          <div v-if="autoDetectedSubtype" class="flex items-center gap-2 text-xs">
            <span class="text-ink-muted">تشخیص خودکار:</span>
            <VBadge tone="info" size="sm">{{ autoDetectedSubtype }}</VBadge>
            <VBadge tone="success" size="sm">{{ autoDetectedTone }}</VBadge>
          </div>

          <!-- Product-specific fields -->
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="text-ink-strong text-sm font-semibold">قیمت (ریال)</label>
              <input
                v-model="price"
                dir="ltr"
                placeholder="2500000"
                class="border-line mt-1 w-full rounded-xl border px-4 py-2.5 text-sm"
              />
            </div>
            <div>
              <label class="text-ink-strong text-sm font-semibold">قیمت تخفیفی</label>
              <input
                v-model="salePrice"
                dir="ltr"
                placeholder="اختیاری"
                class="border-line mt-1 w-full rounded-xl border px-4 py-2.5 text-sm"
              />
            </div>
            <div>
              <label class="text-ink-strong text-sm font-semibold">وضعیت موجودی</label>
              <select
                v-model="stockStatus"
                class="border-line mt-1 w-full rounded-xl border px-4 py-2.5 text-sm"
              >
                <option value="in_stock">در انبار</option>
                <option value="out_of_stock">ناموجود</option>
              </select>
            </div>
          </div>

          <!-- Prompt Template -->
          <div>
            <label class="text-ink-strong text-sm font-semibold">قالب پرامپت (اختیاری)</label>
            <p class="text-ink-muted mb-2 text-xs">
              یک قالب حرفه‌ای انتخاب کنید یا خودتان پرامپت بنویسید.
            </p>
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="t in templates"
                :key="t.id"
                type="button"
                class="rounded-xl border p-3 text-right text-sm transition-all"
                :class="
                  selectedTemplateId === t.id
                    ? 'border-brand-500 bg-brand-50 ring-brand-200 ring-2'
                    : 'border-surface-muted hover:border-brand-300'
                "
                @click="selectTemplate(t)"
              >
                <div class="flex items-center justify-between">
                  <span class="text-ink-strong font-medium">{{ t.title }}</span>
                  <VBadge v-if="t.is_featured" tone="warning" size="sm">⭐</VBadge>
                </div>
                <div class="text-ink-muted mt-1 text-xs">{{ t.tone }}</div>
              </button>
            </div>
            <button
              type="button"
              class="text-brand-700 mt-2 text-xs"
              @click="showCustomPrompt = !showCustomPrompt"
            >
              {{ showCustomPrompt ? 'بستن پرامپت اختیاری' : '✏️ نوشتن پرامپت اختیاری' }}
            </button>
            <textarea
              v-if="showCustomPrompt"
              v-model="customPrompt"
              dir="auto"
              rows="4"
              class="border-line mt-2 w-full rounded-xl border px-4 py-2.5 text-sm"
              placeholder="پرامپت اختیاری خود را اینجا بنویسید..."
            />
          </div>

          <div class="flex gap-2">
            <VButton
              :loading="generatingLoading"
              :disabled="!selectedSiteId || !title.trim()"
              variant="primary"
              size="lg"
              class="flex-1"
              @click="quickGenerate"
            >
              {{ generatingLoading ? 'در حال تولید...' : '⚡ تولید سریع' }}
            </VButton>
            <VButton
              :loading="outlineLoading"
              :disabled="!selectedSiteId || !title.trim()"
              variant="secondary"
              size="lg"
              class="flex-1"
              @click="generateOutline"
            >
              {{ outlineLoading ? 'در حال تحلیل...' : '📋 با Outline' }}
            </VButton>
          </div>
          <VAlert v-if="outlineError" tone="danger">{{ outlineError }}</VAlert>
        </div>
      </VCard>
    </div>

    <!-- STEP 2: OUTLINE -->
    <div v-if="step === 'outline'" class="mx-auto mt-6 max-w-3xl">
      <VCard title="ساختار پیشنهادی محصول">
        <div class="text-ink-muted mb-4 flex items-center gap-3 text-sm">
          <span
            >مدل: <VBadge tone="info" size="sm">{{ outlineModel }}</VBadge></span
          >
          <span>{{ outline.length }} بخش</span>
        </div>
        <div class="space-y-2">
          <div
            v-for="(item, i) in outline"
            :key="i"
            class="border-surface-muted bg-surface hover:border-brand-300 rounded-xl border p-3 transition-all"
            :class="item.level === 3 ? 'me-6' : ''"
          >
            <div class="flex items-center gap-2">
              <VBadge :tone="item.level <= 2 ? 'brand' : 'info'" size="sm"
                >H{{ item.level }}</VBadge
              >
              <span class="text-ink-strong text-sm font-medium">{{ item.heading }}</span>
            </div>
            <p v-if="item.note" class="text-ink-muted mt-1 text-xs">{{ item.note }}</p>
          </div>
        </div>
        <div class="mt-4 flex gap-2">
          <VButton
            :loading="generatingLoading"
            variant="primary"
            size="lg"
            @click="generateFromOutline"
            >🚀 تولید محتوا</VButton
          >
          <VButton variant="secondary" @click="goToOutline">بازگشت</VButton>
        </div>
      </VCard>
    </div>

    <!-- STEP 3: GENERATING -->
    <div v-if="step === 'generating'" class="mx-auto mt-6 max-w-2xl py-16 text-center">
      <div class="mb-4 animate-pulse text-6xl">🤖</div>
      <h2 class="text-ink-strong text-xl font-bold">در حال تولید توضیح محصول...</h2>
      <p class="text-ink-muted mt-2">{{ generatingStatus }}</p>
      <div class="text-ink-muted mt-8 space-y-2 text-sm">
        <p>✅ تحلیل ساختار پیشنهادی</p>
        <p>✅ اعمال گاردرایل‌ها و استانداردها</p>
        <p class="animate-pulse">در انتظار تولید محتوا با AI...</p>
      </div>
    </div>

    <!-- STEP 4: RESULT -->
    <div v-if="step === 'result' && result" class="mt-6">
      <VCard class="mb-6">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="text-2xl">✅</span>
            <div>
              <h3 class="text-ink-strong font-bold">توضیح محصول تولید شد!</h3>
              <p class="text-ink-muted text-sm">
                مدل: {{ result.model }} | منبع: {{ result.source }} | کلمات: {{ wordCount }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <VBadge
              :tone="seoScore >= 70 ? 'success' : seoScore >= 40 ? 'warning' : 'danger'"
              size="lg"
              >امتیاز: {{ seoScore }}/100</VBadge
            >
            <VButton variant="secondary" @click="goToOutline">بازگشت</VButton>
            <VButton variant="secondary" @click="regenerate">🔄 تولید مجدد</VButton>
          </div>
        </div>
      </VCard>

      <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">
          <div class="border-line flex gap-1 border-b">
            <button
              v-for="tab in [
                { id: 'content', label: '✏️ محتوا' },
                { id: 'meta', label: '🏷️ Meta' },
                { id: 'seo', label: '📊 امتیاز' },
                { id: 'schema', label: '📊 اسکیما' },
                { id: 'sections', label: '📝 ویرایش بخش‌ها' },
              ]"
              :key="tab.id"
              type="button"
              class="border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
              :class="
                activeResultTab === tab.id
                  ? 'border-brand-600 text-brand-700'
                  : 'text-ink-muted hover:text-ink-strong border-transparent'
              "
              @click="activeResultTab = tab.id"
            >
              {{ tab.label }}
            </button>
          </div>

          <!-- Content Tab -->
          <VCard v-if="activeResultTab === 'content'">
            <!-- eslint-disable-next-line vue/no-v-html -- محتوا توسط موتور خودِ پلتفرم تولید شده (نه ورودی کاربر) و صرفاً پیش‌نمایش است -->
            <div class="prose prose-sm max-w-none" dir="auto" v-html="result.content" />
          </VCard>

          <!-- Meta Tab -->
          <VCard v-if="activeResultTab === 'meta'">
            <div class="space-y-4">
              <div>
                <label class="text-ink-strong text-sm font-semibold">Meta Title</label>
                <input
                  v-model="result.meta_title"
                  dir="auto"
                  maxlength="70"
                  class="border-line mt-1 w-full rounded-xl border px-4 py-2.5 text-sm"
                />
                <div class="mt-1 flex items-center gap-2">
                  <div class="bg-surface-muted h-1.5 flex-1 overflow-hidden rounded-full">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="
                        (result.meta_title?.length ?? 0) >= 30 &&
                        (result.meta_title?.length ?? 0) <= 60
                          ? 'bg-green-500'
                          : 'bg-red-500'
                      "
                      :style="{
                        width: Math.min(100, ((result.meta_title?.length ?? 0) / 60) * 100) + '%',
                      }"
                    />
                  </div>
                  <span class="text-ink-muted text-xs"
                    >{{ result.meta_title?.length ?? 0 }}/60</span
                  >
                </div>
              </div>
              <div>
                <label class="text-ink-strong text-sm font-semibold">Meta Description</label>
                <textarea
                  v-model="result.meta_description"
                  dir="auto"
                  rows="3"
                  maxlength="200"
                  class="border-line mt-1 w-full rounded-xl border px-4 py-2.5 text-sm"
                />
                <div class="mt-1 flex items-center gap-2">
                  <div class="bg-surface-muted h-1.5 flex-1 overflow-hidden rounded-full">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="
                        (result.meta_description?.length ?? 0) >= 120 &&
                        (result.meta_description?.length ?? 0) <= 160
                          ? 'bg-green-500'
                          : 'bg-red-500'
                      "
                      :style="{
                        width:
                          Math.min(100, ((result.meta_description?.length ?? 0) / 160) * 100) + '%',
                      }"
                    />
                  </div>
                  <span class="text-ink-muted text-xs"
                    >{{ result.meta_description?.length ?? 0 }}/160</span
                  >
                </div>
              </div>
            </div>
          </VCard>

          <!-- SEO Tab -->
          <VCard v-if="activeResultTab === 'seo'">
            <div class="space-y-3">
              <div
                v-for="check in seoChecks"
                :key="check.label"
                class="flex items-center justify-between"
              >
                <span class="text-ink-strong text-sm">{{ check.label }}</span>
                <VBadge :tone="check.passed ? 'success' : 'danger'">{{ check.value }}</VBadge>
              </div>
              <div v-if="result.quality?.failures?.length" class="mt-4">
                <p class="text-sm font-semibold text-red-600">مشکلات:</p>
                <ul class="mt-1 space-y-1">
                  <li v-for="f in result.quality.failures" :key="f" class="text-xs text-red-500">
                    • {{ f }}
                  </li>
                </ul>
              </div>
              <div v-if="result.quality?.warnings?.length" class="mt-4">
                <p class="text-sm font-semibold text-yellow-600">نکات:</p>
                <ul class="mt-1 space-y-1">
                  <li v-for="w in result.quality.warnings" :key="w" class="text-xs text-yellow-500">
                    • {{ w }}
                  </li>
                </ul>
              </div>
            </div>
          </VCard>

          <!-- Schema Tab -->
          <VCard v-if="activeResultTab === 'schema'">
            <div class="space-y-2">
              <div v-for="(schema, i) in result.schemas || []" :key="i">
                <pre class="bg-surface-muted overflow-x-auto rounded-xl p-4 text-xs" dir="ltr">{{
                  JSON.stringify(schema, null, 2)
                }}</pre>
              </div>
            </div>
          </VCard>

          <!-- Section Edit Tab -->
          <VCard v-if="activeResultTab === 'sections'">
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <h4 class="text-ink-strong text-sm font-semibold">ویرایش بخش‌به‌بخش</h4>
                <span class="text-ink-muted text-xs">{{ sections.length }} بخش</span>
              </div>
              <div
                v-for="(sec, i) in sections"
                :key="i"
                class="border-surface-muted bg-surface hover:border-brand-300 rounded-xl border p-3 transition-all"
              >
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <VBadge :tone="sec.level <= 2 ? 'brand' : 'info'" size="sm"
                      >H{{ sec.level }}</VBadge
                    >
                    <span class="text-ink-strong text-sm font-medium">{{ sec.heading }}</span>
                  </div>
                  <div class="flex items-center gap-1">
                    <button
                      type="button"
                      class="text-ink-muted hover:bg-surface-muted rounded p-1.5 text-xs"
                      @click="editSection(i)"
                    >
                      ✏️
                    </button>
                    <button
                      type="button"
                      class="text-ink-muted rounded p-1.5 text-xs hover:bg-blue-100 hover:text-blue-600"
                      :disabled="sec.regenerating"
                      @click="regenerateSection(i)"
                    >
                      <span v-if="sec.regenerating" class="animate-pulse">⏳</span
                      ><span v-else>🔄</span>
                    </button>
                  </div>
                </div>
                <div class="text-ink-muted mt-2 max-h-20 overflow-hidden text-xs" dir="auto">
                  {{ sec.content.replace(/<[^>]+>/g, ' ').substring(0, 200) }}...
                </div>
              </div>
            </div>
          </VCard>

          <!-- Section Edit Modal -->
          <div
            v-if="showSectionEdit"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
          >
            <div
              class="max-h-[80vh] w-full max-w-2xl overflow-auto rounded-2xl bg-white p-6 shadow-2xl"
            >
              <div class="mb-4 flex items-center justify-between">
                <h4 class="text-ink-strong font-bold">
                  ویرایش بخش:
                  {{ editSectionIndex !== null ? sections[editSectionIndex]?.heading : '' }}
                </h4>
                <button
                  type="button"
                  class="text-ink-muted hover:text-ink-strong"
                  @click="showSectionEdit = false"
                >
                  ✕
                </button>
              </div>
              <textarea
                v-model="editSectionText"
                dir="auto"
                rows="12"
                class="border-line w-full rounded-xl border px-4 py-3 text-sm"
              />
              <div class="mt-4 flex justify-end gap-2">
                <VButton variant="secondary" @click="showSectionEdit = false">لغو</VButton>
                <VButton variant="primary" @click="saveSectionEdit">ذخیره</VButton>
              </div>
            </div>
          </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
          <VCard title="📊 خلاصه">
            <div class="space-y-2 text-sm">
              <div class="flex justify-between">
                <span class="text-ink-muted">کلمات:</span
                ><span class="font-medium">{{ wordCount }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-ink-muted">امتیاز:</span
                ><span class="font-medium">{{ seoScore }}/100</span>
              </div>
              <div class="flex justify-between">
                <span class="text-ink-muted">لینک‌ها:</span
                ><span class="font-medium">{{ result.links?.length ?? 0 }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-ink-muted">اسکیما:</span
                ><span class="font-medium">{{ result.schemas?.length ?? 0 }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-ink-muted">قیمت:</span
                ><span class="font-medium">{{ price ? price + ' ریال' : '—' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-ink-muted">موجودی:</span
                ><VBadge :tone="stockStatus === 'in_stock' ? 'success' : 'danger'" size="sm">{{
                  stockStatus === 'in_stock' ? 'در انبار' : 'ناموجود'
                }}</VBadge>
              </div>
            </div>
          </VCard>

          <!-- Keyword Density -->
          <VCard title="🎯 تراکم کلیدواژه">
            <div class="space-y-3">
              <input
                v-model="keywordInput"
                dir="auto"
                class="border-line w-full rounded-xl border px-3 py-2 text-sm"
                placeholder="کلیدواژه را وارد کنید..."
              />
              <div v-if="keywordInput.trim() && result?.content" class="space-y-2">
                <div class="flex items-center justify-between">
                  <span class="text-ink-muted text-xs">تعداد تکرار:</span
                  ><span class="text-ink-strong font-bold">{{ keywordDensity.count }}</span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-ink-muted text-xs">تراکم:</span
                  ><VBadge
                    :tone="
                      keywordDensity.density >= 1 && keywordDensity.density <= 3
                        ? 'success'
                        : keywordDensity.density > 3
                          ? 'danger'
                          : 'warning'
                    "
                    size="sm"
                    >{{ keywordDensity.density }}%</VBadge
                  >
                </div>
              </div>
            </div>
          </VCard>

          <!-- Expert Analysis -->
          <VCard v-if="result.expert_analysis" title="🧠 تحلیل متخصص SEO">
            <div class="space-y-3">
              <p class="text-ink-strong text-sm font-medium">
                {{ result.expert_analysis.summary }}
              </p>
              <div v-if="result.expert_analysis.strengths?.length" class="space-y-1">
                <p class="text-xs font-semibold text-green-600">✅ نقاط قوت:</p>
                <p
                  v-for="s in result.expert_analysis.strengths"
                  :key="s"
                  class="text-xs text-green-500"
                >
                  • {{ s }}
                </p>
              </div>
              <div v-if="result.expert_analysis.weaknesses?.length" class="space-y-1">
                <p class="text-xs font-semibold text-red-600">⚠️ نقاط ضعف:</p>
                <p
                  v-for="w in result.expert_analysis.weaknesses"
                  :key="w"
                  class="text-xs text-red-500"
                >
                  • {{ w }}
                </p>
              </div>
              <div v-if="result.expert_analysis.recommendations?.length" class="space-y-1">
                <p class="text-xs font-semibold text-blue-600">💡 توصیه‌ها:</p>
                <p
                  v-for="r in result.expert_analysis.recommendations"
                  :key="r"
                  class="text-xs text-blue-500"
                >
                  • {{ r }}
                </p>
              </div>
              <div class="mt-3 flex gap-2">
                <VButton
                  size="sm"
                  variant="primary"
                  :loading="applyingSuggestions"
                  @click="applySuggestions(result.expert_analysis.recommendations || [])"
                  >اعمال همه پیشنهادات</VButton
                >
              </div>
            </div>
          </VCard>

          <!-- Profile -->
          <VCard v-if="result.profile" title="🎯 پروفایل">
            <div class="space-y-1 text-xs">
              <div>نوع: {{ result.profile.content_type }}</div>
              <div>زیرنوع: {{ result.profile.subtype }}</div>
              <div>قصد: {{ result.profile.intent }}</div>
            </div>
          </VCard>

          <!-- Action Buttons -->
          <VCard title="🚀 اقدامات">
            <div class="space-y-3">
              <div class="flex flex-wrap gap-2">
                <VButton variant="primary" size="sm" @click="saveCurrentDraft">💾 ذخیره</VButton>
                <VButton variant="secondary" size="sm" @click="copyHtml">📋 کپی HTML</VButton>
                <VButton variant="secondary" size="sm" @click="copyPlainText">📝 کپی متن</VButton>
                <VButton variant="secondary" size="sm" @click="showPublishDialog = true"
                  >🚀 انتشار در وردپرس</VButton
                >
              </div>
              <p v-if="draftSaved" class="text-xs text-green-600">✅ Draft ذخیره شد</p>
              <p v-if="copyStatus === 'HTML'" class="text-xs text-blue-600">📋 HTML کپی شد!</p>
              <p v-if="copyStatus === 'TEXT'" class="text-xs text-blue-600">📝 متن کپی شد!</p>
              <p v-if="publishResult?.success" class="text-xs text-green-600">
                ✅ منتشر شد!
                <a :href="publishResult.post_url" target="_blank" class="underline">مشاهده</a>
              </p>
              <p v-if="publishResult?.error" class="text-xs text-red-600">
                ❌ {{ publishResult.error }}
              </p>
            </div>
          </VCard>
        </div>
      </div>
    </div>

    <!-- WP Publish Dialog -->
    <div
      v-if="showPublishDialog"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <h4 class="text-ink-strong font-bold">🚀 انتشار در وردپرس</h4>
          <button class="text-ink-muted hover:text-ink-strong" @click="showPublishDialog = false">
            ✕
          </button>
        </div>
        <p class="text-ink-muted mb-4 text-sm">
          محصول با اتصال خودکار به سایت وردپرس منتشر می‌شود.
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <VButton variant="secondary" @click="showPublishDialog = false">لغو</VButton>
          <VButton variant="secondary" :loading="publishing" @click="publishToWordPress('draft')"
            >پیش‌نویس</VButton
          >
          <VButton variant="primary" :loading="publishing" @click="publishToWordPress('publish')"
            >انتشار</VButton
          >
        </div>
      </div>
    </div>
    <!-- ═══ گام بریف محصول (حرفه‌ای) ═══ -->
    <VCard v-if="step === 'brief' && brief" title="📋 بریف محصول — بازبینی و ویرایش">
      <div class="grid gap-4 md:grid-cols-2">
        <div class="space-y-3">
          <div>
            <label class="text-ink-muted mb-1 block text-xs font-medium">نام محصول</label>
            <input
              v-model="brief.title"
              class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
            />
          </div>
          <div>
            <label class="text-ink-muted mb-1 block text-xs font-medium">کلیدواژهٔ هدف</label>
            <input
              v-model="brief.target_query"
              class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-ink-muted mb-1 block text-xs font-medium">مخاطب</label>
              <input
                v-model="brief.audience"
                class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
              />
            </div>
            <div>
              <label class="text-ink-muted mb-1 block text-xs font-medium">لحن</label>
              <input
                v-model="brief.tone"
                class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
              />
            </div>
          </div>
          <div>
            <label class="text-ink-muted mb-1 block text-xs font-medium"
              >دستورالعمل سفارشی (اختیاری)</label
            >
            <textarea
              v-model="customInstructions"
              rows="3"
              placeholder="ویژگی‌های کلیدی، مزیت‌ها، الزامات برند…"
              class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
            />
          </div>
        </div>
        <div class="space-y-3">
          <div class="bg-surface-muted rounded-xl p-3 text-xs leading-6">
            <p class="text-ink-strong mb-1 font-bold">سیستم پیشنهاد می‌دهد</p>
            <p
              >· زیرنوع: <b>{{ brief.subtype }}</b> · قصد: <b>{{ brief.intent }}</b></p
            >
            <p
              >· طول هدف:
              <b dir="ltr">{{ brief.word_range[0] }}–{{ brief.word_range[1] }}</b> کلمه</p
            >
            <p
              >· عناصر الزامی: <b>{{ brief.required_elements.join(' + ') }}</b></p
            >
          </div>
          <!-- پنل ووکامرس: دادهٔ واقعی -->
          <div
            v-if="brief.woo"
            class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs leading-6"
          >
            <p class="mb-1 font-bold text-emerald-800">🛒 دادهٔ واقعی ووکامرس</p>
            <p>
              قیمت:
              <b dir="ltr"
                >{{ brief.woo.sale_price ?? brief.woo.price }} {{ brief.woo.currency }}</b
              >
              <span
                v-if="brief.woo.regular_price && brief.woo.sale_price"
                class="line-through opacity-60"
                dir="ltr"
              >
                {{ brief.woo.regular_price }}</span
              >
            </p>
            <p>
              موجودی:
              <b>{{ brief.woo.in_stock ? 'موجود' : 'ناموجود' }}</b>
              <span v-if="brief.woo.stock_quantity !== null">
                ({{ brief.woo.stock_quantity }})
              </span>
            </p>
            <p class="opacity-70">توضیحات با قیمت/موجودی واقعی هماهنگ تولید می‌شود.</p>
          </div>
          <p v-else class="text-ink-muted text-[11px] leading-5">
            💡 برای هماهنگی توضیحات با قیمت واقعی، URL محصول را در گام قبل وارد کنید (اتصال پلاگین
            لازم است).
          </p>
        </div>
      </div>

      <!-- دسته/برچسب محصول -->
      <div class="border-line mt-5 border-t pt-4">
        <div class="mb-2 flex items-center justify-between">
          <p class="text-ink-strong text-xs font-bold">🗂️ دسته و برچسب محصول (ووکامرس)</p>
          <button
            class="text-brand-700 text-[11px] underline"
            type="button"
            @click="loadTaxonomies"
          >
            دریافت از فروشگاه
          </button>
        </div>
        <p v-if="taxonomiesError" class="text-warning-700 text-[11px]">{{ taxonomiesError }}</p>
        <template v-else-if="taxonomiesLoaded">
          <div class="flex flex-wrap gap-2">
            <button
              v-for="c in wpProductCats"
              :key="c.id"
              type="button"
              class="rounded-full border px-3 py-1 text-[11px] font-medium transition"
              :class="
                selectedCategoryIds.includes(c.id)
                  ? 'border-brand-600 bg-brand-50 text-brand-700'
                  : 'border-line text-ink-muted hover:border-brand-400'
              "
              @click="toggleCategory(c.id)"
            >
              {{ c.name }} <span class="opacity-60">({{ c.count }})</span>
            </button>
          </div>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              v-for="t in wpProductTags"
              :key="t.id"
              type="button"
              class="rounded-full border px-3 py-1 text-[11px] transition"
              :class="
                selectedTagNames.includes(t.name)
                  ? 'border-success-600 bg-success-50 text-success-700'
                  : 'border-line text-ink-muted'
              "
              @click="toggleTag(t.name)"
            >
              #{{ t.name }}
            </button>
          </div>
        </template>
        <p v-else class="text-ink-muted text-[11px]">
          دسته‌های محصول را از ووکامرس بگیرید تا محصول در جای درست فروشگاه قرار بگیرد.
        </p>
      </div>

      <div class="border-line mt-5 flex flex-wrap gap-2 border-t pt-4">
        <button
          class="rounded-lg border px-4 py-2 text-xs font-medium"
          type="button"
          @click="step = 'input'"
        >
          → بازگشت
        </button>
        <button
          class="bg-brand-600 hover:bg-brand-700 rounded-lg px-4 py-2 text-xs font-bold text-white"
          type="button"
          :disabled="outlineLoading"
          @click="fetchOutline('pro')"
        >
          ادامه — ساخت پیش‌نویس ساختار ▶
        </button>
      </div>
    </VCard>
  </AppLayout>
</template>
