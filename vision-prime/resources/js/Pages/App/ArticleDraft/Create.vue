<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '@/app/layouts/AppLayout.vue'
import VAlert from '@/shared/ui/VAlert.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'
import VIcon from '@/shared/ui/VIcon.vue'
import CoverPicker from '@/Pages/App/ContentStudio/CoverPicker.vue'
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
interface SchemaItem {
  '@type': string
  [key: string]: unknown
}
interface QualityResult {
  passed: boolean
  score: number
  failures: string[]
  warnings: string[]
  readability?: ReadabilityResult | null
}
interface ReadabilityResult {
  score: number
  label: string
  sentence_avg_length: number
  word_avg_length: number
  long_sentences_pct: number
  complex_words_pct: number
  details: string
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
  profile: { content_type: string; subtype: string; intent: string } | null
  draft_id?: number
  expert_analysis?: {
    summary?: string
    strengths?: string[]
    weaknesses?: string[]
    recommendations?: string[]
  } | null
}

interface PromptTemplate {
  id: number
  title: string
  content_type: string
  subtype: string
  tone: string
  system_prompt: string
  user_prompt_template: string
  usage_count: number
  avg_quality_score: number
  is_featured: boolean
  is_user_created: boolean
  tags: string[]
}
interface DuplicateDraft {
  id: number
  title: string
  status: string
  quality_score: number
  similarity: number
  created_at: string
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

const studioMode = ref<'quick' | 'pro'>('quick') // استودیو v2: سرعتی | حرفه‌ای
const step = ref<'input' | 'brief' | 'outline' | 'generating' | 'result'>('input')

// ─── بریف محتوا (حالت حرفه‌ای) ───
interface BriefData {
  title: string
  target_query: string
  suggested_title: string
  subtype: string
  intent: string
  audience: string
  tone: string
  word_range: number[]
  required_elements: string[]
  gsc_queries: { query: string; impressions: number }[]
  internal_link_candidates: { url: string; title: string }[]
  notes: string
}
const brief = ref<BriefData | null>(null)
const briefLoading = ref(false)
const briefError = ref('')
const customInstructions = ref('')
const userWordCount = ref(0)
const userTone = ref('')

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
      body: JSON.stringify({ site_id: Number(selectedSiteId.value), title: title.value }),
    })
    const data = (await res.json()) as { success: boolean; brief?: BriefData; error?: string }
    if (data.success && data.brief) {
      brief.value = data.brief
      subtype.value = data.brief.subtype
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

// ─── دسته/برچسب وردپرس ───
interface WpTerm {
  id: number
  name: string
  count?: number
}
const wpCategories = ref<WpTerm[]>([])
const wpTags = ref<WpTerm[]>([])
const selectedCategoryIds = ref<number[]>([])
const selectedTagNames = ref<string[]>([])
const taxonomiesLoaded = ref(false)
const taxonomiesError = ref('')

async function loadTaxonomies(): Promise<void> {
  if (!selectedSiteId.value || taxonomiesLoaded.value) return
  taxonomiesError.value = ''
  try {
    const res = await fetch(`/app/sites/${selectedSiteId.value}/taxonomies`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = (await res.json()) as {
      success: boolean
      connected?: boolean
      categories?: WpTerm[]
      tags?: WpTerm[]
      error?: string
    }
    if (data.success) {
      wpCategories.value = data.categories ?? []
      wpTags.value = data.tags ?? []
      taxonomiesLoaded.value = true
    } else {
      taxonomiesError.value = data.error ?? ''
    }
  } catch {
    taxonomiesError.value = 'خطای شبکه در دریافت دسته‌ها'
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

function removeSmartTag(name: string): void {
  const i = selectedTagNames.value.indexOf(name)
  if (i !== -1) selectedTagNames.value.splice(i, 1)
}

function removeSmartCategory(id: number): void {
  const i = selectedCategoryIds.value.indexOf(id)
  if (i !== -1) selectedCategoryIds.value.splice(i, 1)
}

const selectedSiteId = ref('')
const title = ref('')
const subtype = ref('how_to_guide')

// ─── پیشنهاد هوشمند تگ و دسته ───
interface SmartSuggestion {
  name: string
  score: number
  reason: string
  existing?: boolean
  wp_id?: number
  similar_to?: string
}
interface SmartCategorySuggestion {
  id: number
  name: string
  score: number
  confidence: string
  reason: string
}
const smartTags = ref<SmartSuggestion[]>([])
const smartCategories = ref<SmartCategorySuggestion[]>([])
const smartLoading = ref(false)
const smartNewCategory = ref<{ suggested: boolean; name: string | null }>({ suggested: false, name: null })
const smartNote = ref<string | null>(null)
const autoApplySmart = ref(true) // خودکار اعمال پیشنهادات

async function fetchSmartSuggestions(): Promise<void> {
  if (!selectedSiteId.value || !title.value.trim() || title.value.trim().length < 5) return
  smartLoading.value = true
  try {
    const res = await fetch('/api/content/smart-suggest', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        title: title.value.trim(),
        keyword: title.value.trim(),
      }),
    })
    const data = (await res.json()) as {
      success: boolean
      tags?: SmartSuggestion[]
      categories?: SmartCategorySuggestion[]
      best_category_id?: number | null
      new_category_suggested?: boolean
      new_category_name?: string | null
      note?: string | null
    }
    if (data.success) {
      smartTags.value = data.tags ?? []
      smartCategories.value = data.categories ?? []
      smartNewCategory.value = {
        suggested: data.new_category_suggested ?? false,
        name: data.new_category_name ?? null,
      }
      smartNote.value = data.note ?? null

      // خودکار اعمال بهترین پیشنهادات
      if (autoApplySmart.value) {
        // اعمال بهترین دسته
        if (data.best_category_id && !selectedCategoryIds.value.includes(data.best_category_id)) {
          selectedCategoryIds.value.push(data.best_category_id)
        }
        // اعمال تگ‌های با امتیاز بالا
        for (const tag of (data.tags ?? []).slice(0, 5)) {
          if (tag.score >= 60 && !selectedTagNames.value.includes(tag.name)) {
            selectedTagNames.value.push(tag.name)
          }
        }
      }
    }
  } catch {
    /* نادیده گرفته شد */
  }
  smartLoading.value = false
}

function acceptSmartTag(tag: SmartSuggestion): void {
  if (!selectedTagNames.value.includes(tag.name)) {
    selectedTagNames.value.push(tag.name)
  }
}

function acceptSmartCategory(cat: SmartCategorySuggestion): void {
  if (!selectedCategoryIds.value.includes(cat.id)) {
    selectedCategoryIds.value.push(cat.id)
  }
}

// Debounce برای جلوگیری از درخواست‌های زیاد
let smartDebounceTimer: ReturnType<typeof setTimeout> | null = null
watch(title, (v) => {
  if (smartDebounceTimer) clearTimeout(smartDebounceTimer)
  smartDebounceTimer = setTimeout(() => {
    if (v && v.trim().length > 5 && selectedSiteId.value) {
      fetchSmartSuggestions()
    }
  }, 800) // 800ms debounce
})

const outline = ref<OutlineItem[]>([])
const outlineLoading = ref(false)
const outlineError = ref('')
const outlineModel = ref('')
const dragging = ref<number | null>(null)

const generatingLoading = ref(false)
const generatingStatus = ref('')
const result = ref<GeneratedResult | null>(null)
const activeResultTab = ref<'content' | 'meta' | 'seo' | 'schema' | 'sections'>('content')
const errorMsg = ref('')

// Duplicate check
const duplicates = ref<DuplicateDraft[]>([])
const showDuplicates = ref(false)
const duplicateLoading = ref(false)
// Prompt templates
const templates = ref<PromptTemplate[]>([])
const selectedTemplateId = ref<number | null>(null)
const templatesLoading = ref(false)
const customPrompt = ref('')
const showCustomPrompt = ref(false)
const savingTemplate = ref(false)
const newTemplateName = ref('')
const showSaveDialog = ref(false)
interface GscSummary {
  total_queries?: number
  total_clicks?: number
  total_impressions?: number
  avg_ctr?: number
}
interface GscContextData {
  has_data?: boolean
  summary?: GscSummary
}
const gscContext = ref<GscContextData | null>(null)
const gscLoading = ref(false)
const autoDetectedSubtype = ref('')
const autoDetectedTone = ref('')
const applyingSuggestions = ref(false)
const publishing = ref(false)
interface PublishResultData {
  success?: boolean
  error?: string
  post_url?: string
}
const publishResult = ref<PublishResultData | null>(null)
const showPublishDialog = ref(false)
const copyStatus = ref('')

// Section editing
const sections = ref<SectionItem[]>([])
const showSectionEdit = ref(false)
const editSectionIndex = ref<number | null>(null)
const editSectionText = ref('')

// Keyword density
const keywordInput = ref('')

const wordCount = computed(() => {
  if (!result.value?.content) return 0
  const plain = result.value.content.replace(/<[^>]+>/g, ' ').trim()
  return plain ? plain.split(/\s+/).filter((w) => w.length > 0).length : 0
})

const seoScore = computed(() => result.value?.quality?.score ?? 0)

const readability = computed<ReadabilityResult | null>(
  () => result.value?.quality?.readability ?? null,
)

const keywordDensity = computed(() => {
  const kw = keywordInput.value.trim()
  if (!kw || !result.value?.content) return { count: 0, density: 0 }
  const plain = result.value.content
    .replace(/<[^>]+>/g, ' ')
    .trim()
    .toLowerCase()
  const kwLower = kw.toLowerCase()
  let count = 0
  let pos = 0
  while ((pos = plain.indexOf(kwLower, pos)) !== -1) {
    count++
    pos += kwLower.length
  }
  const words = plain.split(/\s+/).filter((w) => w.length > 0)
  const density = words.length > 0 ? (count / words.length) * 100 : 0
  return { count, density: Math.round(density * 100) / 100 }
})

const keywordDensityStatus = computed(() => {
  const d = keywordDensity.value.density
  if (d === 0) return { label: 'ناموجود', color: 'text-red-500', bg: 'bg-red-50' }
  if (d < 0.5) return { label: 'خیلی کم', color: 'text-yellow-600', bg: 'bg-yellow-50' }
  if (d <= 3) return { label: 'ایده‌آل', color: 'text-green-600', bg: 'bg-green-50' }
  if (d <= 5) return { label: 'زیاد', color: 'text-orange-600', bg: 'bg-orange-50' }
  return { label: 'اسپم', color: 'text-red-600', bg: 'bg-red-50' }
})

const seoChecks = computed(() => {
  if (!result.value) return []
  const r = result.value
  return [
    { label: 'طول محتوا', value: wordCount.value + ' کلمه', passed: wordCount.value >= 400 },
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
      label: 'لینک‌های داخلی',
      value: (r.links?.length ?? 0) + ' عدد',
      passed: (r.links?.length ?? 0) >= 2,
    },
    {
      label: 'اسکیما',
      value: (r.schemas?.length ?? 0) + ' عدد',
      passed: (r.schemas?.length ?? 0) >= 1,
    },
    { label: 'امتیاز کیفیت', value: r.quality?.score ?? 0, passed: (r.quality?.score ?? 0) >= 70 },
    ...(readability.value
      ? [
          {
            label: 'خوانایی',
            value: readability.value.label + ' (' + readability.value.score + '/100)',
            passed: readability.value.score >= 40,
          },
        ]
      : []),
  ]
})

const outlineH2Count = computed(() => outline.value.filter((i) => i.level === 2).length)
const outlineH3Count = computed(() => outline.value.filter((i) => i.level === 3).length)

// SERP Intelligence
interface SerpCompetitor {
  title: string
  url: string
  headings: string[]
  word_count: number
  snippet: string
}
interface SerpAnalysis {
  competitors: SerpCompetitor[]
  avg_word_count: number
  common_headings: string[]
  content_gaps: string[]
  recommendations: string[]
  model: string
}
const serpAnalysis = ref<SerpAnalysis | null>(null)
const serpLoading = ref(false)
const serpError = ref('')
const showSerpPanel = ref(false)

// === API Methods ===

async function fetchTemplates() {
  templatesLoading.value = true
  try {
    const res = await fetch('/api/content/prompt-templates?content_type=article', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (res.ok) templates.value = await res.json()
  } catch {
    /* ignore */
  }
  templatesLoading.value = false
}
async function checkDuplicate() {
  if (!title.value.trim() || !selectedSiteId.value) return
  duplicateLoading.value = true
  try {
    const res = await fetch('/api/content/check-duplicate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ title: title.value.trim(), site_id: Number(selectedSiteId.value) }),
    })
    const data = await res.json()
    if (data.has_duplicate) {
      duplicates.value = data.similar_drafts
      showDuplicates.value = true
    } else {
      duplicates.value = []
      showDuplicates.value = false
    }
  } catch {
    /* نادیده گرفته شد */
  }
  duplicateLoading.value = false
}

// ─── مدیریت کتابخانهٔ پرامپت (ویرایش/حذف قالب‌های کاربر) ───
const deletingTemplateId = ref<number | null>(null)

async function deleteTemplate(id: number): Promise<void> {
  if (!confirm('این قالب حذف شود؟')) return
  deletingTemplateId.value = id
  try {
    const res = await fetch(`/api/content/prompt-templates/${id}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (res.ok) {
      templates.value = templates.value.filter((t) => t.id !== id)
      if (selectedTemplateId.value === id) selectedTemplateId.value = null
    }
  } catch {
    /* نادیده گرفته شد */
  } finally {
    deletingTemplateId.value = null
  }
}

function editTemplate(id: number): void {
  const t = templates.value.find((x) => x.id === id)
  if (!t) return
  const name = prompt('عنوان قالب:', t.title)
  if (name === null) return
  const body = prompt(
    'متن پرامپت (از {title} برای جای عنوان استفاده کنید):',
    t.user_prompt_template || '',
  )
  if (body === null) return
  void (async () => {
    const res = await fetch(`/api/content/prompt-templates/${id}`, {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        title: name || t.title,
        user_prompt_template: body,
        content_type: t.content_type,
        tone: t.tone,
      }),
    })
    if (res.ok) await fetchTemplates()
  })()
}

function dismissDuplicates() {
  showDuplicates.value = false
}

async function quickGenerate() {
  if (!selectedSiteId.value || !title.value.trim()) return
  generatingLoading.value = true
  generatingStatus.value = 'تولید سریع...'
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
        word_count: wordCount.value || undefined,
      }),
    })
    const d = (await res.json().catch(() => ({}))) as Record<string, unknown>
    if (!res.ok) {
      const errs = (d.errors ?? {}) as Record<string, string[]>
      const planMsg = Array.isArray(errs.plan_limit) ? errs.plan_limit[0] : undefined
      const errText = (d.error as string) ?? undefined
      const msgText = (d.message as string) ?? undefined
      const msg =
        planMsg ??
        errText ??
        msgText ??
        (res.status === 429
          ? 'تعداد درخواست‌ها زیاد است — کمی بعد دوباره تلاش کنید.'
          : 'خطای سرور (' + res.status + ')')
      errorMsg.value = msg
      step.value = 'input'
      return
    }
    if (d.error) {
      errorMsg.value = String(d.error)
      step.value = 'input'
      return
    }
    result.value = d as unknown as typeof result.value
    currentDraftId.value = (d.draft_id as number | undefined) ?? null
    activeResultTab.value = 'content'
    step.value = 'result'
    parseSections(d.content as string)
  } catch (e) {
    errorMsg.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
    step.value = 'input'
  }
  generatingLoading.value = false
}

async function fetchGscContext() {
  if (!selectedSiteId.value || !title.value.trim()) return
  gscLoading.value = true
  try {
    const url =
      '/api/content/gsc-context?site_id=' +
      selectedSiteId.value +
      '&title=' +
      encodeURIComponent(title.value)
    const r = await fetch(url, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (r.ok) gscContext.value = await r.json()
  } catch {
    /* نادیده گرفته شد */
  }
  gscLoading.value = false
}
function autoDetect(t: string) {
  const l = t.toLowerCase()
  if (/comp|compare|comparison|مقایسه|vs|بهترین/.test(l)) {
    autoDetectedSubtype.value = 'comparison'
    autoDetectedTone.value = 'neutral'
  } else if (/tutorial|how.to|guide|آموزش|چگونه|راهنمای/.test(l)) {
    autoDetectedSubtype.value = 'tutorial'
    autoDetectedTone.value = 'informative'
  } else if (/review|نقد|بررسی/.test(l)) {
    autoDetectedSubtype.value = 'review'
    autoDetectedTone.value = 'professional'
  } else if (/buy|price|sale|خرید|قیمت|فروش/.test(l)) {
    autoDetectedSubtype.value = 'sales'
    autoDetectedTone.value = 'persuasive'
  } else {
    autoDetectedSubtype.value = 'tutorial'
    autoDetectedTone.value = 'informative'
  }
}
async function saveAsTemplate() {
  if (!newTemplateName.value.trim()) return
  savingTemplate.value = true
  try {
    const r = await fetch('/api/content/save-user-template', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        title: newTemplateName.value.trim(),
        system_prompt: customPrompt.value || 'system',
        user_prompt_template: customPrompt.value || 'write {title}',
        tone: autoDetectedTone.value || 'informative',
        content_type: 'article',
      }),
    })
    if (r.ok) {
      await fetchTemplates()
      showSaveDialog.value = false
      newTemplateName.value = ''
    }
  } catch {
    /* نادیده گرفته شد */
  }
  savingTemplate.value = false
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
      parseSections(d.content as string)
    }
  } catch {
    /* نادیده گرفته شد */
  }
  applyingSuggestions.value = false
}

/** استودیو v2 — ورودی دوحالته: quick = تولید مستقیم، pro = مسیر بریف. */
async function fetchOutline(mode: 'quick' | 'pro'): Promise<void> {
  if (mode === 'pro') {
    if (brief.value === null) {
      await buildBrief() // بریف می‌سازد و به گام brief می‌رود
      return
    }
    await generateOutline()
    return
  }
  await generateOutline()
}

async function generateOutline() {
  if (!selectedSiteId.value || !title.value.trim()) return
  // Check duplicates first
  await checkDuplicate()
  if (duplicates.value.length > 0) {
    // Show warning but allow proceeding
  }
  outlineLoading.value = true
  outlineError.value = ''
  try {
    const res = await fetch('/api/content/outline', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        title: title.value.trim(),
        subtype: subtype.value || undefined,
      }),
    })
    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>
    if (!res.ok) {
      const errs = (data.errors ?? {}) as Record<string, string[]>
      const planMsg = Array.isArray(errs.plan_limit) ? errs.plan_limit[0] : undefined
      const errText = (data.error as string) ?? undefined
      const msgText = (data.message as string) ?? undefined
      const msg =
        planMsg ??
        errText ??
        msgText ??
        (res.status === 429
          ? 'تعداد درخواست‌ها زیاد است — کمی بعد دوباره تلاش کنید.'
          : 'خطای سرور (' + res.status + ')')
      errorMsg.value = msg
      step.value = 'input'
      return
    }
    if (data.error) {
      outlineError.value = String(data.error)
      return
    }
    outline.value = (data.outline ?? []) as typeof outline.value
    outlineModel.value = String(data.model ?? '')
    if (outline.value.length === 0) {
      outlineError.value = 'Outline خالی برگشت — دوباره تلاش کنید'
      return
    }
    step.value = 'outline'
  } catch (e: unknown) {
    outlineError.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
  }
  outlineLoading.value = false
}

function addOutlineItem(level: 2 | 3) {
  outline.value.push({ heading: '', level, note: '' })
}

function removeOutlineItem(index: number) {
  outline.value.splice(index, 1)
}

function moveOutlineItem(index: number, direction: -1 | 1) {
  const newIndex = index + direction
  if (newIndex < 0 || newIndex >= outline.value.length) return
  const temp = outline.value[index]
  outline.value[index] = outline.value[newIndex]
  outline.value[newIndex] = temp
}

function startDrag(index: number) {
  dragging.value = index
}

function onDragOver(event: DragEvent, index: number) {
  event.preventDefault()
  if (dragging.value === null || dragging.value === index) return
  const item = outline.value.splice(dragging.value, 1)[0]
  outline.value.splice(index, 0, item)
  dragging.value = index
}

function endDrag() {
  dragging.value = null
}

async function generateWithOutline() {
  if (outline.value.length === 0) return
  step.value = 'generating'
  generatingLoading.value = true
  generatingStatus.value = 'تحلیل outline و اعمال گاردرایل‌ها...'
  errorMsg.value = ''
  try {
    const res = await fetch('/api/content/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        site_id: Number(selectedSiteId.value),
        keyword: title.value.trim(),
        title: title.value.trim(),
        subtype: subtype.value || undefined,
        outline: outline.value.map((i) => i.heading),
      }),
    })
    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>
    if (!res.ok) {
      const errs = (data.errors ?? {}) as Record<string, string[]>
      const planMsg = Array.isArray(errs.plan_limit) ? errs.plan_limit[0] : undefined
      const errText = (data.error as string) ?? undefined
      const msgText = (data.message as string) ?? undefined
      const msg =
        planMsg ??
        errText ??
        msgText ??
        (res.status === 429
          ? 'تعداد درخواست‌ها زیاد است — کمی بعد دوباره تلاش کنید.'
          : 'خطای سرور (' + res.status + ')')
      errorMsg.value = msg
      step.value = 'input'
      return
    }
    if (data.error) {
      errorMsg.value = String(data.error)
      step.value = 'outline'
    } else {
      result.value = data as unknown as typeof result.value
      keywordInput.value = title.value.trim()
      parseSections(data.content as string)
      step.value = 'result'
    }
  } catch (e: unknown) {
    errorMsg.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
    step.value = 'outline'
  }
  generatingLoading.value = false
}

// === Section Editing ===

function parseSections(html: string) {
  const items: SectionItem[] = []
  const regex = /(<h[2-6][^>]*>.*?<\/h[2-6]>)([\s\S]*?)(?=<h[2-6]|$)/gi
  let match
  while ((match = regex.exec(html)) !== null) {
    const heading = match[1]
    const content = match[2].trim()
    const levelMatch = heading.match(/<h(\d)/i)
    const level = levelMatch ? parseInt(levelMatch[1]) : 2
    items.push({ heading: heading.replace(/<[^>]+>/g, ''), level, content, regenerating: false })
  }
  sections.value = items
}

function editSection(index: number) {
  editSectionIndex.value = index
  editSectionText.value = sections.value[index].content.replace(/<[^>]+>/g, '\n').trim()
  showSectionEdit.value = true
}

async function regenerateSection(index: number) {
  sections.value[index].regenerating = true
  try {
    const res = await fetch('/api/content/regenerate-section', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        heading: sections.value[index].heading,
        context: title.value.trim(),
        full_content: result.value?.content ?? '',
        keyword: keywordInput.value.trim(),
      }),
    })
    const data = await res.json()
    if (data.content) {
      sections.value[index].content = data.content
      rebuildContent()
    }
  } catch {
    /* ignore */
  }
  sections.value[index].regenerating = false
}

function rebuildContent() {
  if (!result.value) return
  let html = ''
  for (const sec of sections.value) {
    const hTag = sec.level <= 2 ? 'h2' : 'h3'
    html += `<${hTag}>${sec.heading}</${hTag}>\n${sec.content}\n`
  }
  result.value.content = html
  parseSections(html)
}

function saveSectionEdit() {
  if (editSectionIndex.value === null) return
  sections.value[editSectionIndex.value].content = editSectionText.value
  showSectionEdit.value = false
  editSectionIndex.value = null
  rebuildContent()
}

// === SERP ===

async function analyzeSerp() {
  if (!title.value.trim()) return
  serpLoading.value = true
  serpError.value = ''
  try {
    const res = await fetch('/api/content/serp-analysis', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        keyword: title.value.trim(),
        subtype: subtype.value || undefined,
        outline: outline.value.map((i) => ({ heading: i.heading, level: i.level })),
      }),
    })
    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>
    if (!res.ok) {
      const errs = (data.errors ?? {}) as Record<string, string[]>
      const planMsg = Array.isArray(errs.plan_limit) ? errs.plan_limit[0] : undefined
      const errText = (data.error as string) ?? undefined
      const msgText = (data.message as string) ?? undefined
      const msg =
        planMsg ??
        errText ??
        msgText ??
        (res.status === 429
          ? 'تعداد درخواست‌ها زیاد است — کمی بعد دوباره تلاش کنید.'
          : 'خطای سرور (' + res.status + ')')
      errorMsg.value = msg
      step.value = 'input'
      return
    }
    if (data.error) {
      serpError.value = String(data.error)
    } else {
      serpAnalysis.value = data as unknown as typeof serpAnalysis.value
      showSerpPanel.value = true
    }
  } catch (e: unknown) {
    serpError.value = 'خطا: ' + (e instanceof Error ? e.message : String(e))
  }
  serpLoading.value = false
}

function addSerpHeading(heading: string) {
  outline.value.push({
    heading: heading.replace(/^H[23]:\s*/, ''),
    level: 2,
    note: 'از تحلیل رقبا',
  })
}

// === Navigation ===

function goToInput() {
  step.value = 'input'
  result.value = null
  outline.value = []
  serpAnalysis.value = null
  showSerpPanel.value = false
  errorMsg.value = ''
  showDuplicates.value = false
  duplicates.value = []
  sections.value = []
}

function goToOutline() {
  step.value = 'outline'
  result.value = null
  errorMsg.value = ''
  sections.value = []
}

function regenerate() {
  result.value = null
  step.value = 'outline'
  sections.value = []
}

function autoMetaTitle() {
  if (result.value && title.value) {
    const siteName = p.sites.find((s) => String(s.id) === selectedSiteId.value)?.name ?? ''
    result.value.meta_title = title.value.substring(0, 50) + ' | ' + siteName
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
  showPublishDialog.value = false
  try {
    // Use current draft if available, otherwise find by title
    let draftId = currentDraftId.value
    if (!draftId) {
      const draftsRes = await fetch(
        '/api/content/drafts?search=' + encodeURIComponent(title.value),
        { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
      )
      const draftsData = await draftsRes.json()
      draftId = draftsData.drafts?.[0]?.id
    }
    if (!draftId) {
      publishResult.value = { success: false, error: 'Draft یافت نشد. ابتدا مقاله را تولید کنید.' }
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
    const pd = (await res.json()) as Record<string, unknown>
    if (pd.via === 'connector' && pd.success === true && pd.command_id) {
      // انتشار کانکتور async است — تا رسیدن نتیجهٔ واقعی پلاگین poll می‌کنیم
      publishResult.value = {
        success: true,
        pending: true,
        message: 'در حال انتشار روی وردپرس…',
      } as unknown as typeof publishResult.value
      pollPublishStatus(Number(pd.command_id))
      return
    }
    publishResult.value = pd as unknown as typeof publishResult.value
  } catch (e) {
    publishResult.value = { success: false, error: e instanceof Error ? e.message : String(e) }
  }
  publishing.value = false
}

/** R1-3 — poll وضعیت فرمان انتشار تا نتیجهٔ واقعی پلاگین (حداکثر ~۹۰ ثانیه). */
async function pollPublishStatus(commandId: number, attempt = 1): Promise<void> {
  if (attempt > 30) {
    publishResult.value = {
      success: false,
      error: 'پاسخ پلاگین طولانی شد — وضعیت را از «تغییرات اجرایی» ببینید.',
    } as unknown as typeof publishResult.value
    return
  }
  try {
    const res = await fetch(`/api/content/publish-status?command_id=${commandId}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const d = (await res.json()) as {
      status?: string
      post_url?: string | null
      post_id?: number | null
      error?: string | null
    }
    if (d.status === 'executed') {
      publishResult.value = {
        success: true,
        post_url: d.post_url ?? undefined,
        message: d.post_url ? 'منتشر شد!' : `پست ساخته شد (#${d.post_id ?? '?'})`,
      } as typeof publishResult.value
      return
    }
    if (d.status === 'failed') {
      publishResult.value = {
        success: false,
        error: d.error ?? 'اجرای فرمان در وردپرس ناموفق بود.',
      } as unknown as typeof publishResult.value
      return
    }
    if (d.status === 'pending_approval') {
      publishResult.value = {
        success: false,
        error:
          'انتشار نیازمند تأیید انسانی است (گیت کاور/سیاست) — از «بررسی و تأییدها» ادامه دهید.',
      } as unknown as typeof publishResult.value
      return
    }
    await new Promise((r) => setTimeout(r, 3000))
    void pollPublishStatus(commandId, attempt + 1)
  } catch {
    await new Promise((r) => setTimeout(r, 4000))
    void pollPublishStatus(commandId, attempt + 1)
  }
}

const currentDraftId = ref<number | null>(null)
const draftSaved = ref(false)

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
        subtype: autoDetectedSubtype.value || 'tutorial',
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

watch(title, (v) => {
  if (v && v.trim().length > 5) autoDetect(v)
})
</script>
<template>
  <Head title="تولید مقاله هوشمند" />
  <AppLayout>
    <VPageHeader
      title="تولید مقاله هوشمند"
      description="با وارد کردن عنوان، outline پیشنهادی را بررسی و سپس مقاله را تولید کنید."
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
      <!-- ═══ استودیو v2: انتخاب حالت ═══ -->
      <VCard v-if="step === 'input'" class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-ink-strong text-sm font-bold">حالت تولید را انتخاب کنید</p>
            <p class="text-ink-muted mt-1 text-xs leading-6"
              ><VIcon :name="'zap'" size="sm" class="inline-block align-middle" /><b>سرعتی:</b> فقط
              عنوان — سیستم همه‌چیز را خودکار می‌سازد. &nbsp;·&nbsp; <b>حرفه‌ای:</b> بریف محتوایی
              قابل ویرایش + دسته‌بندی وردپرس + کنترل کامل.
            </p>
          </div>
          <div class="bg-surface-muted flex rounded-xl p-1">
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-xs font-bold transition"
              :class="studioMode === 'quick' ? 'bg-brand-600 text-white' : 'text-ink-muted'"
              @click="studioMode = 'quick'"
            >
              سرعتی
            </button>
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-xs font-bold transition"
              :class="studioMode === 'pro' ? 'bg-brand-600 text-white' : 'text-ink-muted'"
              @click="studioMode = 'pro'"
            >
              حرفه‌ای
            </button>
          </div>
        </div>
      </VCard>

      <VCard title="عنوان مقاله را وارد کنید">
        <div class="space-y-4">
          <VSelect
            v-model="selectedSiteId"
            label="سایت"
            :options="p.sites.map((s) => ({ label: s.name, value: String(s.id) }))"
            placeholder="انتخاب سایت"
          />
          <div>
            <label class="text-ink-strong text-sm font-semibold">عنوان مقاله</label>
            <input
              v-model="title"
              type="text"
              dir="auto"
              class="border-line focus:ring-brand-500 mt-2 w-full rounded-xl border px-4 py-3 text-lg focus:ring-2"
              placeholder="مثال: راهنمای جامع سئو برای سایت فروشگاهی"
              @keyup.enter="generateOutline"
            />
            <div v-if="autoDetectedSubtype" class="mt-2 flex items-center gap-2 text-xs">
              <span class="text-ink-muted">تشخیص خودکار:</span
              ><VBadge tone="info" size="sm">{{ autoDetectedSubtype }}</VBadge
              ><VBadge tone="success" size="sm">{{ autoDetectedTone }}</VBadge>
            </div>
            <p class="text-ink-muted mt-1 text-xs">
              بعد از وارد کردن عنوان، سیستم ابتدا outline (ساختار) پیشنهادی را نمایش می‌دهد.
            </p>
          </div>

          <!-- Prompt Template Selector -->
          <div
            v-if="templates.length === 0 && !templatesLoading"
            class="text-ink-muted bg-surface-muted rounded-lg p-3 text-xs leading-6"
          >
            کتابخانهٔ قالب‌ها خالی است — با دکمهٔ «ذخیره به‌عنوان قالب» پرامپت خودتان را اضافه کنید
            یا روی سرور اجرا کنید: <code dir="ltr">php artisan db:seed</code>
          </div>
          <div v-if="templates.length > 0">
            <label class="text-ink-strong text-sm font-semibold">قالب پرامپت (اختیاری)</label>
            <p class="text-ink-muted mb-2 text-xs">
              یک قالب حرفه‌ای انتخاب کنید یا خودتان پرامپت بنویسید.
            </p>
            <div class="mt-2 grid grid-cols-2 gap-2">
              <button
                v-for="tpl in templates"
                :key="tpl.id"
                type="button"
                class="rounded-xl border px-3 py-2 text-right text-sm transition-all"
                :class="[
                  selectedTemplateId === tpl.id
                    ? 'border-brand-500 bg-brand-50 ring-brand-300 ring-2'
                    : 'border-surface-muted bg-surface hover:border-brand-300',
                ]"
                @click="selectedTemplateId = selectedTemplateId === tpl.id ? null : tpl.id"
              >
                <div class="flex items-center justify-between">
                  <span class="font-medium">{{ tpl.title }}</span>
                  <span class="flex items-center gap-1">
                    <VBadge v-if="tpl.is_featured" tone="success" size="sm"
                      ><VIcon :name="'star'" size="sm" class="inline-block align-middle"
                    /></VBadge>
                    <span v-if="tpl.is_user_created" class="flex gap-1">
                      <button
                        type="button"
                        class="text-ink-muted hover:text-brand-600"
                        title="ویرایش قالب"
                        @click.stop="editTemplate(tpl.id)"
                        ><VIcon :name="'pencil'" size="sm" class="inline-block align-middle"
                      /></button>
                      <button
                        type="button"
                        class="text-ink-muted hover:text-red-600"
                        title="حذف قالب"
                        :disabled="deletingTemplateId === tpl.id"
                        @click.stop="deleteTemplate(tpl.id)"
                        ><VIcon :name="'trash'" size="sm" class="inline-block align-middle"
                      /></button>
                    </span>
                  </span>
                </div>
                <div class="text-ink-muted mt-1 flex gap-2 text-xs">
                  <span>{{ tpl.tone }}</span>
                  <span v-if="tpl.avg_quality_score > 0"
                    ><VIcon :name="'star'" size="sm" class="inline-block align-middle" />
                    {{ tpl.avg_quality_score.toFixed(1) }}</span
                  >
                  <span v-if="tpl.usage_count > 0">{{ tpl.usage_count }}x</span>
                </div>
              </button>
            </div>
          </div>

          <!-- Custom Prompt -->
          <div>
            <button
              type="button"
              class="text-brand-600 text-sm hover:underline"
              @click="showCustomPrompt = !showCustomPrompt"
            >
              {{ showCustomPrompt ? '️ بستن پرامپت دستی' : ' نوشتن پرامپت اختیاری' }}
            </button>
            <div v-if="showCustomPrompt" class="mt-3 space-y-3">
              <textarea
                v-model="customPrompt"
                dir="auto"
                rows="4"
                class="border-line focus:ring-brand-500 w-full rounded-xl border px-4 py-3 text-sm focus:ring-2"
                placeholder="پرامپت اختیاری خود را بنویسید..."
              ></textarea>
              <div class="flex gap-2">
                <VButton
                  size="sm"
                  variant="secondary"
                  :disabled="!customPrompt.trim()"
                  @click="showSaveDialog = true"
                  ><VIcon :name="'save'" size="sm" class="inline-block align-middle" /> ذخیره به
                  عنوان قالب</VButton
                >
              </div>
              <div
                v-if="showSaveDialog"
                class="bg-surface-muted flex items-center gap-2 rounded-xl p-3"
              >
                <input
                  v-model="newTemplateName"
                  type="text"
                  class="border-line flex-1 rounded-lg border px-3 py-1.5 text-sm"
                  placeholder="نام قالب..."
                />
                <VButton
                  size="sm"
                  variant="primary"
                  :loading="savingTemplate"
                  @click="saveAsTemplate"
                  >ذخیره</VButton
                >
                <VButton size="sm" variant="secondary" @click="showSaveDialog = false">لغو</VButton>
              </div>
            </div>
          </div>
          <VSelect
            v-model="subtype"
            label="زیرنوع"
            :options="Object.entries(p.subtypes).map(([v, l]) => ({ label: l, value: v }))"
          />

          <!-- Duplicate Warning -->

          <!-- GSC Context -->
          <div
            v-if="selectedSiteId && title.trim().length > 5"
            class="bg-surface border-surface-muted rounded-xl border p-4"
          >
            <div class="mb-3 flex items-center justify-between">
              <span class="text-sm font-semibold"
                ><VIcon :name="'chart-bar'" size="sm" class="inline-block align-middle" /> Context
                سایت</span
              >
              <button
                type="button"
                class="text-brand-600 text-xs hover:underline"
                :disabled="gscLoading"
                @click="fetchGscContext"
              >
                {{ gscLoading ? '...' : 'بروزرسانی' }}
              </button>
            </div>
            <div
              v-if="gscContext?.has_data"
              class="grid grid-cols-2 gap-3 text-center text-xs md:grid-cols-4"
            >
              <div>
                <div class="text-brand-700 font-bold">{{ gscContext.summary?.total_queries }}</div>
                <div class="text-ink-muted">کوئری</div>
              </div>
              <div>
                <div class="font-bold text-green-600">{{ gscContext.summary?.total_clicks }}</div>
                <div class="text-ink-muted">کلیک</div>
              </div>
              <div>
                <div class="font-bold text-blue-600">
                  {{ gscContext.summary?.total_impressions }}
                </div>
                <div class="text-ink-muted">نمایش</div>
              </div>
              <div>
                <div class="font-bold text-orange-600">{{ gscContext.summary?.avg_ctr }}%</div>
                <div class="text-ink-muted">CTR</div>
              </div>
            </div>
            <div v-else class="text-ink-muted text-xs">
              داده GSC یافت نشد — سایت را در GSC ثبت کنید.
            </div>
          </div>

          <VAlert v-if="showDuplicates && duplicates.length > 0" tone="warning">
            <div class="space-y-2">
              <p class="font-semibold"
                ><VIcon :name="'alert'" size="sm" class="inline-block align-middle" />
                {{ duplicates.length }} مقاله مشابه یافت شد:</p
              >
              <div
                v-for="d in duplicates"
                :key="d.id"
                class="flex items-center justify-between text-xs"
              >
                <span>{{ d.title }} ({{ d.status }})</span>
                <VBadge :tone="d.similarity >= 80 ? 'danger' : 'warning'" size="sm"
                  >{{ d.similarity }}% مشابه</VBadge
                >
              </div>
              <div class="mt-2 flex gap-2">
                <VButton variant="secondary" size="sm" @click="dismissDuplicates"
                  >ادامه با تولید</VButton
                >
              </div>
            </div>
          </VAlert>          <!-- ═══ پیشنهاد هوشمند تگ و دسته ═══ -->
          <div
            v-if="(smartTags.length > 0 || smartCategories.length > 0 || smartNewCategory.suggested) && !smartLoading"
            class="bg-brand-50 border-brand-200 rounded-xl border p-4"
          >
            <div class="mb-3 flex items-center justify-between">
              <span class="text-brand-700 text-sm font-bold">
                <VIcon :name="'sparkles'" size="sm" class="inline-block align-middle" />
                پیشنهاد هوشمند
              </span>
              <label class="flex items-center gap-2 text-xs">
                <input v-model="autoApplySmart" type="checkbox" class="rounded" />
                خودکار اعمال بشه
              </label>
            </div>

            <!-- دسته‌های پیشنهادی -->
            <div v-if="smartCategories.length > 0" class="mb-3">
              <p class="text-ink-muted mb-2 text-xs">دسته‌های پیشنهادی:</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="cat in smartCategories"
                  :key="cat.id"
                  type="button"
                  class="rounded-lg border px-3 py-1.5 text-xs transition-all"
                  :class="[
                    selectedCategoryIds.includes(cat.id)
                      ? 'border-brand-500 bg-brand-100 text-brand-700'
                      : 'border-surface-muted bg-white hover:border-brand-300',
                  ]"
                  @click="acceptSmartCategory(cat)"
                >
                  {{ cat.name }}
                  <span class="text-ink-muted">({{ cat.score }}%)</span>
                  <span v-if="cat.confidence === 'high'" class="text-green-600">✓</span>
                </button>
              </div>
            </div>

            <!-- دسته جدید پیشنهادی -->
            <div v-if="smartNewCategory.suggested && smartNewCategory.name" class="mb-3">
              <p class="text-ink-muted mb-1 text-xs">دسته جدید پیشنهادی:</p>
              <VBadge tone="info" size="sm">+ {{ smartNewCategory.name }}</VBadge>
            </div>

            <!-- تگ‌های پیشنهادی -->
            <div v-if="smartTags.length > 0">
              <p class="text-ink-muted mb-2 text-xs">تگ‌های پیشنهادی:</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="tag in smartTags.slice(0, 8)"
                  :key="tag.name"
                  type="button"
                  class="rounded-lg border px-3 py-1.5 text-xs transition-all"
                  :class="[
                    selectedTagNames.includes(tag.name)
                      ? 'border-brand-500 bg-brand-100 text-brand-700'
                      : 'border-surface-muted bg-white hover:border-brand-300',
                  ]"
                  @click="acceptSmartTag(tag)"
                >
                  {{ tag.name }}
                  <span class="text-ink-muted">({{ tag.score }})</span>
                  <span v-if="tag.existing" class="text-green-600">✓</span>
                </button>
              </div>
            </div>

            <p v-if="smartNote" class="text-ink-muted mt-2 text-xs">{{ smartNote }}</p>
          </div>

          <div v-if="smartLoading" class="text-brand-600 flex items-center gap-2 text-xs">
            <span class="animate-spin">⏳</span> در حال تحلیل هوشمند...
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
              {{ generatingLoading ? 'در حال تولید...' : '  تولید سریع' }}
            </VButton>
            <VButton
              :loading="outlineLoading"
              :disabled="!selectedSiteId || !title.trim()"
              variant="secondary"
              size="lg"
              class="flex-1"
              @click="fetchOutline(studioMode)"
            >
              {{ outlineLoading ? 'در حال تحلیل...' : '  با Outline' }}
            </VButton>
          </div>
          <VButton
            style="display: none"
            :loading="outlineLoading"
            :disabled="!selectedSiteId || !title.trim()"
            variant="primary"
            size="lg"
            class="w-full"
            @click="fetchOutline(studioMode)"
          >
            <span v-if="!outlineLoading">تولید Outline</span>
            <span v-else>در حال تحلیل...</span>
          </VButton>
          <VAlert v-if="outlineError" tone="danger">{{ outlineError }}</VAlert>
        </div>
      </VCard>
    </div>

    <!-- STEP 2: OUTLINE EDITOR -->
    <div v-if="step === 'outline'" class="mx-auto mt-6 max-w-3xl">
      <VCard title="ساختار پیشنهادی مقاله">
        <div class="text-ink-muted mb-4 flex items-center gap-3 text-sm">
          <span>H2: {{ outlineH2Count }}</span>
          <span>H3: {{ outlineH3Count }}</span>
          <span>کل: {{ outline.length }}</span>
          <VBadge tone="info" size="sm">مدل: {{ outlineModel }}</VBadge>
        </div>
        <div class="space-y-2">
          <div
            v-for="(item, index) in outline"
            :key="index"
            class="group border-surface-muted bg-surface hover:border-brand-300 flex items-center gap-2 rounded-xl border p-3 transition-all"
            :class="{ 'opacity-50': dragging === index }"
            :style="{ paddingLeft: item.level === 3 ? '2.5rem' : '1rem' }"
            draggable="true"
            @dragstart="startDrag(index)"
            @dragover="(e: DragEvent) => onDragOver(e, index)"
            @dragend="endDrag"
          >
            <span
              class="text-ink-muted cursor-grab opacity-0 transition-opacity group-hover:opacity-100"
              title="جابجایی"
              >⠿</span
            >
            <VBadge :tone="item.level === 2 ? 'brand' : 'info'" size="sm">H{{ item.level }}</VBadge>
            <input
              v-model="item.heading"
              dir="auto"
              class="flex-1 bg-transparent text-sm font-medium outline-none"
              :placeholder="item.level === 2 ? 'عنوان بخش اصلی...' : 'عنوان زیربخش...'"
            />
            <input
              v-model="item.note"
              dir="auto"
              class="text-ink-muted placeholder:text-ink-muted/50 w-48 bg-transparent text-xs outline-none"
              placeholder="توضیح (اختیاری)"
            />
            <div
              class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100"
            >
              <button
                type="button"
                class="text-ink-muted hover:bg-surface-muted rounded p-1"
                title="بالا"
                @click="moveOutlineItem(index, -1)"
              >
                ↑
              </button>
              <button
                type="button"
                class="text-ink-muted hover:bg-surface-muted rounded p-1"
                title="پایین"
                @click="moveOutlineItem(index, 1)"
              >
                ↓
              </button>
              <button
                type="button"
                class="text-ink-muted rounded p-1 hover:bg-red-100 hover:text-red-600"
                title="حذف"
                @click="removeOutlineItem(index)"
              >
                ✕
              </button>
            </div>
          </div>
        </div>
        <div class="mt-4 flex gap-2">
          <VButton variant="secondary" size="sm" @click="addOutlineItem(2)">+ افزودن H2</VButton>
          <VButton variant="secondary" size="sm" @click="addOutlineItem(3)">+ افزودن H3</VButton>
        </div>
        <div class="border-surface-muted mt-6 flex items-center justify-between border-t pt-4">
          <div class="flex items-center gap-2">
            <VButton variant="secondary" @click="goToInput">بازگشت</VButton>
            <VButton
              variant="secondary"
              size="sm"
              :loading="serpLoading"
              :disabled="!title.trim()"
              @click="analyzeSerp"
            >
              تحلیل رقبا (SERP)
            </VButton>
          </div>
          <VButton
            variant="primary"
            size="lg"
            :disabled="outline.length === 0 || outline.some((i) => !i.heading.trim())"
            :loading="generatingLoading"
            @click="generateWithOutline"
          >
            <span v-if="!generatingLoading">تولید مقاله بر اساس Outline</span>
            <span v-else>در حال تولید...</span>
          </VButton>
        </div>
      </VCard>

      <!-- SERP Intelligence Panel -->
      <VCard v-if="showSerpPanel && serpAnalysis" class="mt-4">
        <template #title>
          <div class="flex items-center justify-between">
            <span
              ><VIcon :name="'search'" size="sm" class="inline-block align-middle" /> تحلیل رقبا
              (SERP Intelligence)</span
            >
            <VBadge tone="info" size="sm">مدل: {{ serpAnalysis.model }}</VBadge>
          </div>
        </template>

        <div class="space-y-3">
          <h4 class="text-ink-strong text-sm font-semibold">صفحات برتر رقبا:</h4>
          <div
            v-for="(comp, i) in serpAnalysis.competitors"
            :key="i"
            class="border-surface-muted bg-surface rounded-xl border p-3"
          >
            <div class="flex items-center justify-between">
              <div>
                <p class="text-ink-strong text-sm font-medium">{{ comp.title }}</p>
                <p class="text-ink-muted text-xs">{{ comp.url }} · {{ comp.word_count }} کلمه</p>
              </div>
            </div>
            <div v-if="autoDetectedSubtype" class="mt-2 flex items-center gap-2 text-xs">
              <span class="text-ink-muted">تشخیص خودکار:</span
              ><VBadge tone="info" size="sm">{{ autoDetectedSubtype }}</VBadge
              ><VBadge tone="success" size="sm">{{ autoDetectedTone }}</VBadge>
            </div>
            <p class="text-ink-muted mt-1 text-xs">{{ comp.snippet }}</p>
            <div class="mt-2 flex flex-wrap gap-1">
              <VBadge v-for="h in comp.headings.slice(0, 6)" :key="h" tone="brand" size="sm">{{
                h
              }}</VBadge>
            </div>
          </div>
        </div>

        <div class="mt-4">
          <h4 class="text-ink-strong text-sm font-semibold">عنوان‌های مشترک رقبا:</h4>
          <div class="mt-2 flex flex-wrap gap-1">
            <button
              v-for="h in serpAnalysis.common_headings"
              :key="h"
              type="button"
              class="border-brand-300 bg-brand-50 text-brand-700 hover:bg-brand-100 rounded-full border px-3 py-1 text-xs transition-colors"
              @click="addSerpHeading(h)"
            >
              + {{ h }}
            </button>
          </div>
        </div>

        <div v-if="serpAnalysis.content_gaps.length > 0" class="mt-4">
          <h4 class="text-ink-strong text-sm font-semibold"
            ><VIcon :name="'alert'" size="sm" class="inline-block align-middle" /> شکاف‌های
            محتوایی:</h4
          >
          <ul class="mt-2 space-y-1">
            <li v-for="gap in serpAnalysis.content_gaps" :key="gap" class="text-xs text-yellow-600">
              • {{ gap }}
            </li>
          </ul>
        </div>

        <div v-if="serpAnalysis.recommendations.length > 0" class="mt-4">
          <h4 class="text-ink-strong text-sm font-semibold"
            ><VIcon :name="'lightbulb'" size="sm" class="inline-block align-middle" />
            پیشنهادات:</h4
          >
          <ul class="mt-2 space-y-1">
            <li
              v-for="rec in serpAnalysis.recommendations"
              :key="rec"
              class="text-xs text-green-600"
            >
              ✓ {{ rec }}
            </li>
          </ul>
        </div>

        <div class="text-ink-muted mt-4 text-sm">
          میانگین کلمات رقبا:
          <span class="text-ink-strong font-bold">{{ serpAnalysis.avg_word_count }}</span> کلمه
        </div>
      </VCard>

      <VAlert v-if="serpError" tone="danger" class="mt-4">{{ serpError }}</VAlert>

      <VCard class="mt-4" title="نکات">
        <ul class="text-ink-muted space-y-1 text-xs">
          <li>عنوان‌ها را می‌توانید ویرایش، حذف یا جابجا کنید.</li>
          <li>H2 برای بخش‌های اصلی و H3 برای زیربخش‌هاست.</li>
          <li>کشیدن و رها کردن (drag and drop) برای جابجایی سریع.</li>
          <li>بعد از تایید، مقاله دقیقاً بر اساس این ساختار تولید می‌شود.</li>
        </ul>
      </VCard>
    </div>

    <!-- STEP 3: GENERATING -->
    <div v-if="step === 'generating'" class="mx-auto mt-6 max-w-2xl py-16 text-center">
      <div class="mb-4 animate-pulse text-6xl"></div>
      <h2 class="text-ink-strong text-xl font-bold">در حال تولید مقاله...</h2>
      <p class="text-ink-muted mt-2">{{ generatingStatus }}</p>
      <div class="text-ink-muted mt-8 space-y-2 text-sm">
        <p>تحلیل outline پیشنهادی</p>
        <p>اعمال گاردرایل‌ها و استانداردها</p>
        <p>تحلیل داده GSC</p>
        <p class="animate-pulse">در انتظار تولید مقاله با AI...</p>
      </div>
    </div>

    <!-- STEP 4: RESULT -->
    <div v-if="step === 'result' && result" class="mt-6">
      <VCard class="mb-6">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="text-2xl"></span>
            <div>
              <h3 class="text-ink-strong font-bold">مقاله تولید شد!</h3>
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
            <VButton variant="secondary" @click="goToOutline">بازگشت به Outline</VButton>
            <VButton variant="secondary" @click="regenerate"
              ><VIcon :name="'refresh'" size="sm" class="inline-block align-middle" /> تولید
              مجدد</VButton
            >
          </div>
        </div>
      </VCard>
      <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">
          <div class="border-line flex gap-1 border-b">
            <button
              v-for="tab in [
                { id: 'content', label: ' محتوا' },
                { id: 'meta', label: '️ Meta' },
                { id: 'seo', label: ' امتیاز' },
                { id: 'schema', label: ' اسکیما' },
                { id: 'sections', label: 'ویرایش بخش‌ها' },
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

          <!-- R1-2: شفافیت منبع تولید -->
          <VAlert v-if="result && result.source === 'rule_based'" tone="warning" class="mb-4"
            ><VIcon :name="'settings'" size="sm" class="inline-block align-middle" /> این پیش‌نویس
            با<b>موتور قانونی (آفلاین)</b> ساخته شده، نه مدل زبانی — احتمالاً سرویس AI در دسترس نبود
            یا محدود شد. کلید/سهمیه را در تنظیمات←یکپارچه‌سازی بررسی کنید و «تولید مجدد» را بزنید.
          </VAlert>

          <!-- Content Tab -->
          <VCard v-if="activeResultTab === 'content'">
            <!-- eslint-disable-next-line vue/no-v-html -- محتوا توسط موتور خودِ پلتفرم تولید شده (نه ورودی کاربر) و صرفاً پیش‌نمایش است -->
            <div class="prose prose-sm max-w-none" dir="auto" v-html="result.content" />

            <CoverPicker :draft-id="currentDraftId" :title="title" class="mt-4" />
          </VCard>

          <!-- Meta Tab -->
          <VCard v-if="activeResultTab === 'meta'">
            <div class="space-y-4">
              <div>
                <div class="flex items-center justify-between">
                  <label class="text-ink-strong text-sm font-semibold">Meta Title</label
                  ><button type="button" class="text-brand-700 text-xs" @click="autoMetaTitle">
                    تولید خودکار
                  </button>
                </div>
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

              <!-- Readability Card -->
              <div
                v-if="readability"
                class="border-surface-muted bg-surface mt-4 rounded-xl border p-4"
              >
                <h4 class="text-ink-strong mb-2 text-sm font-semibold">خوانایی</h4>
                <div class="mb-3 flex items-center gap-3">
                  <VBadge
                    :tone="
                      readability.score >= 60
                        ? 'success'
                        : readability.score >= 40
                          ? 'warning'
                          : 'danger'
                    "
                    size="lg"
                  >
                    {{ readability.label }} ({{ readability.score }}/100)
                  </VBadge>
                </div>
                <div class="text-ink-muted grid grid-cols-2 gap-2 text-xs">
                  <div>
                    میانگین طول جمله:
                    <span class="text-ink-strong font-medium"
                      >{{ readability.sentence_avg_length }} کلمه</span
                    >
                  </div>
                  <div>
                    میانگین طول کلمه:
                    <span class="text-ink-strong font-medium"
                      >{{ readability.word_avg_length }} کاراکتر</span
                    >
                  </div>
                  <div>
                    جملات طولانی:
                    <span class="text-ink-strong font-medium"
                      >{{ readability.long_sentences_pct }}%</span
                    >
                  </div>
                  <div>
                    کلمات پیچیده:
                    <span class="text-ink-strong font-medium"
                      >{{ readability.complex_words_pct }}%</span
                    >
                  </div>
                </div>
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
                      title="ویرایش متن"
                      @click="editSection(i)"
                      ><VIcon :name="'pencil'" size="sm" class="inline-block align-middle"
                    /></button>
                    <button
                      type="button"
                      class="text-ink-muted rounded p-1.5 text-xs hover:bg-blue-100 hover:text-blue-600"
                      :disabled="sec.regenerating"
                      title="تولید مجدد"
                      @click="regenerateSection(i)"
                    >
                      <span v-if="sec.regenerating" class="animate-pulse">⏳</span>
                      <span v-else
                        ><VIcon :name="'refresh'" size="sm" class="inline-block align-middle"
                      /></span>
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
          <VCard
            v-if="showSectionEdit"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            style="position: fixed"
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
          </VCard>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
          <VCard title=" خلاصه">
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
              <div v-if="readability" class="flex justify-between">
                <span class="text-ink-muted">خوانایی:</span
                ><span
                  class="font-medium"
                  :class="readability.score >= 60 ? 'text-green-600' : 'text-yellow-600'"
                  >{{ readability.label }}</span
                >
              </div>
            </div>
          </VCard>

          <!-- Keyword Density -->
          <VCard title=" تراکم کلیدواژه">
            <div class="space-y-3">
              <input
                v-model="keywordInput"
                dir="auto"
                class="border-line w-full rounded-xl border px-3 py-2 text-sm"
                placeholder="کلیدواژه را وارد کنید..."
              />
              <div v-if="keywordInput.trim() && result?.content" class="space-y-2">
                <div class="flex items-center justify-between">
                  <span class="text-ink-muted text-xs">تعداد تکرار:</span>
                  <span class="text-ink-strong font-bold">{{ keywordDensity.count }}</span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-ink-muted text-xs">تراکم:</span>
                  <VBadge
                    :tone="
                      keywordDensity.density >= 1 && keywordDensity.density <= 3
                        ? 'success'
                        : keywordDensity.density > 3
                          ? 'danger'
                          : 'warning'
                    "
                    size="sm"
                  >
                    {{ keywordDensity.density }}%
                  </VBadge>
                </div>
                <div class="bg-surface-muted h-2 overflow-hidden rounded-full">
                  <div
                    class="h-full rounded-full transition-all duration-500"
                    :class="
                      keywordDensityStatus.color === 'text-green-600'
                        ? 'bg-green-500'
                        : keywordDensity.density > 3
                          ? 'bg-red-500'
                          : 'bg-yellow-500'
                    "
                    :style="{ width: Math.min(100, keywordDensity.density * 20) + '%' }"
                  />
                </div>
                <p class="text-xs" :class="keywordDensityStatus.color">
                  {{ keywordDensityStatus.label }} — بهترین: ۱ تا ۳٪
                </p>
              </div>
            </div>
          </VCard>

          <VCard v-if="result.links?.length" title="لینک‌های داخلی">
            <div class="space-y-2">
              <div v-for="link in result.links.slice(0, 5)" :key="link.url" class="text-xs">
                <span class="text-brand-700">{{ link.anchor }}</span> →
                <span class="text-ink-muted">{{ link.url }}</span>
              </div>
            </div>
          </VCard>
          <VCard v-if="result.profile" title=" پروفایل">
            <div class="space-y-1 text-xs">
              <div>نوع: {{ result.profile.content_type }}</div>
              <div>زیرنوع: {{ result.profile.subtype }}</div>
              <div>قصد: {{ result.profile.intent }}</div>
            </div>
          </VCard>

          <!-- Expert Analysis Card -->
          <VCard v-if="result.expert_analysis" title=" تحلیل متخصص SEO">
            <div class="space-y-3">
              <p class="text-ink-strong text-sm font-medium">
                {{ result.expert_analysis.summary }}
              </p>
              <div v-if="result.expert_analysis.strengths?.length" class="space-y-1">
                <p class="text-xs font-semibold text-green-600">نقاط قوت:</p>
                <p
                  v-for="s in result.expert_analysis.strengths"
                  :key="s"
                  class="text-xs text-green-500"
                >
                  • {{ s }}
                </p>
              </div>
              <div v-if="result.expert_analysis.weaknesses?.length" class="space-y-1">
                <p class="text-xs font-semibold text-red-600"
                  ><VIcon :name="'alert'" size="sm" class="inline-block align-middle" /> نقاط
                  ضعف:</p
                >
                <p
                  v-for="w in result.expert_analysis.weaknesses"
                  :key="w"
                  class="text-xs text-red-500"
                >
                  • {{ w }}
                </p>
              </div>
              <div v-if="result.expert_analysis.recommendations?.length" class="space-y-1">
                <p class="text-xs font-semibold text-blue-600"
                  ><VIcon :name="'lightbulb'" size="sm" class="inline-block align-middle" />
                  توصیه‌ها:</p
                >
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
                <VButton size="sm" variant="secondary" @click="result.expert_analysis = null"
                  >فقط ذخیره</VButton
                >
              </div>
            </div>
          </VCard>

          <!-- Action Buttons Card -->
          <VCard title=" اقدامات">
            <div class="space-y-3">
              <div class="flex flex-wrap gap-2">
                <VButton variant="primary" size="sm" @click="saveCurrentDraft"
                  ><VIcon :name="'save'" size="sm" class="inline-block align-middle" />
                  ذخیره</VButton
                >
                <VButton variant="secondary" size="sm" @click="copyHtml"
                  ><VIcon :name="'table'" size="sm" class="inline-block align-middle" /> کپی
                  HTML</VButton
                >
                <VButton variant="secondary" size="sm" @click="copyPlainText">کپی متن</VButton>
                <VButton variant="secondary" size="sm" @click="showPublishDialog = true"
                  ><VIcon :name="'rocket'" size="sm" class="inline-block align-middle" /> انتشار در
                  وردپرس</VButton
                >
              </div>
              <p v-if="draftSaved" class="text-xs text-green-600">Draft ذخیره شد</p>
              <p v-if="copyStatus === 'HTML'" class="text-xs text-blue-600"
                ><VIcon :name="'table'" size="sm" class="inline-block align-middle" /> HTML کپی
                شد!</p
              >
              <p v-if="copyStatus === 'TEXT'" class="text-xs text-blue-600">متن کپی شد!</p>
              <p v-if="publishResult?.success" class="text-xs text-green-600">
                منتشر شد!
                <a :href="publishResult.post_url" target="_blank" class="underline">مشاهده</a>
              </p>
              <p v-if="publishResult?.error" class="text-xs text-red-600">
                {{ publishResult.error }}
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
      <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <h4 class="text-ink-strong font-bold"
            ><VIcon :name="'rocket'" size="sm" class="inline-block align-middle" /> انتشار در
            وردپرس</h4
          >
          <button class="text-ink-muted hover:text-ink-strong" @click="showPublishDialog = false">
            ✕
          </button>
        </div>
        <p class="text-ink-muted mb-4 text-sm">
          مقاله با اتصال خودکار به سایت وردپرس منتشر می‌شود.
        </p>
        <div class="flex justify-end gap-2">
          <VButton variant="secondary" @click="showPublishDialog = false">لغو</VButton>
          <VButton variant="secondary" :loading="publishing" @click="publishToWordPress('draft')"
            >ذخیره پیش‌نویس</VButton
          >
          <VButton variant="primary" :loading="publishing" @click="publishToWordPress('publish')"
            >انتشار</VButton
          >
        </div>
      </div>
    </div>

    <!-- ═══ گام بریف (حالت حرفه‌ای) ═══ -->
    <VCard v-if="step === 'brief' && brief" title=" بریف محتوایی — بازبینی و ویرایش">
      <div class="grid gap-4 md:grid-cols-2">
        <div class="space-y-3">
          <div>
            <label class="text-ink-muted mb-1 block text-xs font-medium">عنوان</label>
            <input
              v-model="brief.title"
              class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
            />
          </div>
          <div>
            <label class="text-ink-muted mb-1 block text-xs font-medium">کلیدواژهٔ هدف</label>
            <input
              v-model="brief.target_query"
              dir="rtl"
              class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-ink-muted mb-1 block text-xs font-medium"
                >طول هدف (کلمه — ۰ = استاندارد)</label
              >
              <input
                v-model.number="userWordCount"
                type="number"
                dir="ltr"
                class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
              />
            </div>
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
              <label class="text-ink-muted mt-2 block text-xs font-medium"
                >لحن سفارشی (اختیاری — بازنویسی)</label
              >
              <input
                v-model="userTone"
                class="border-line focus:border-brand-600 mt-1 w-full rounded-lg border px-3 py-2 text-sm outline-none"
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
              placeholder="نکات خاص، منابع، الزامات مشتری…"
              class="border-line focus:border-brand-600 w-full rounded-lg border px-3 py-2 text-sm outline-none"
            />
          </div>
        </div>
        <div class="space-y-3">
          <div class="bg-surface-muted rounded-xl p-3 text-xs leading-6">
            <p class="text-ink-strong mb-1 font-bold">سيستم پیشنهاد می‌دهد</p>
            <p
              >· زیرنوع: <b>{{ brief.subtype }}</b> · قصد جستجو: <b>{{ brief.intent }}</b></p
            >
            <p
              >· طول هدف:
              <b dir="ltr">{{ brief.word_range[0] }}–{{ brief.word_range[1] }}</b> کلمه</p
            >
            <p
              >· عناصر الزامی: <b>{{ brief.required_elements.join(' + ') }}</b></p
            >
            <p v-if="brief.gsc_queries.length" class="mt-1">
              · کوئری‌های GSC:
              <span
                v-for="q in brief.gsc_queries.slice(0, 5)"
                :key="q.query"
                class="text-ink-muted"
              >
                «{{ q.query }}» ({{ q.impressions }})
              </span>
            </p>
          </div>
          <div v-if="brief.internal_link_candidates.length" class="text-xs leading-6">
            <p class="text-ink-strong font-bold">کاندیدهای لینک داخلی:</p>
            <p
              v-for="c in brief.internal_link_candidates"
              :key="c.url"
              class="text-ink-muted truncate"
            >
              · <a :href="c.url" target="_blank" class="text-brand-700 underline">{{ c.title }}</a>
            </p>
          </div>
        </div>
      </div>

      <!-- دسته/برچسب وردپرس -->
      <div class="border-line mt-5 border-t pt-4">
        <div class="mb-2 flex items-center justify-between">
          <p class="text-ink-strong text-xs font-bold"
            ><VIcon :name="'table'" size="sm" class="inline-block align-middle" /> دسته و برچسب
            وردپرس (اختیاری)</p
          >
          <VButton size="sm" variant="ghost" @click="loadTaxonomies">دریافت از سایت</VButton>
        </div>
        <p v-if="taxonomiesError" class="text-warning-700 text-[11px]">{{ taxonomiesError }}</p>
        <template v-else-if="taxonomiesLoaded">
          <div class="flex flex-wrap gap-2">
            <button
              v-for="c in wpCategories"
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
              v-for="t in wpTags"
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
          برای چیدن مقاله در جای درست سایت، دسته‌ها را از وردپرس بگیرید (اتصال پلاگین لازم است).
        </p>
      </div>

      <div class="border-line mt-5 flex flex-wrap gap-2 border-t pt-4">
        <VButton variant="secondary" @click="step = 'input'">→ بازگشت</VButton>
        <VButton :loading="outlineLoading" @click="fetchOutline('pro')"
          >ادامه — ساخت پیش‌نویس ساختار ▶</VButton
        >
      </div>
    </VCard>
  </AppLayout>
</template>
