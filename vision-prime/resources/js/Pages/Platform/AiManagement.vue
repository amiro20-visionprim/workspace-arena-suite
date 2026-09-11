<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import PlatformLayout from '@/platform/layouts/PlatformLayout.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'
import VIcon from '@/shared/ui/VIcon.vue'
import VInput from '@/shared/ui/VInput.vue'
import VModal from '@/shared/ui/VModal.vue'
import VPageHeader from '@/shared/ui/VPageHeader.vue'
import VSelect from '@/shared/ui/VSelect.vue'
import VStatCard from '@/shared/ui/VStatCard.vue'

interface AiKey {
  id: number
  provider: string
  status: string
  created_at: string
}

interface UsageLog {
  id: number
  provider: string
  model: string | null
  input_tokens: number
  output_tokens: number
  occurred_at: string
}

const props = defineProps<{
  keys: AiKey[]
  usage: { today: number; week: number; month: number }
  recentLogs: UsageLog[]
  allProviders: Record<string, { name: string; category: string }>
}>()

/* ── State ── */
const showAddModal = ref(false)
const testingId = ref<number | null>(null)
const testResult = ref<{ ok: boolean; message: string } | null>(null)
const deletingId = ref<number | null>(null)
const detectingId = ref<number | null>(null)
const detectedModels = ref<Array<{id: string; name: string; status: string}>>([])
const form = useForm({ provider: 'groq', api_key: '', model: '' })

/* ── All supported providers ── */
const providerOptions = [
  { label: 'Groq (سریع‌ترین)', value: 'groq' },
  { label: 'DeepSeek (ارزان و سریع)', value: 'deepseek' },
  { label: 'OpenRouter (14+ مدل رایگان)', value: 'openrouter' },
  { label: 'OpenAI (GPT-4)', value: 'openai' },
  { label: 'Anthropic (Claude)', value: 'anthropic' },
  { label: 'Google Gemini', value: 'google' },
  { label: 'Together AI', value: 'together' },
  { label: 'Fireworks AI', value: 'fireworks' },
  { label: 'Mistral AI', value: 'mistral' },
  { label: 'Cohere', value: 'cohere' },
  { label: 'DeepInfra', value: 'deepinfra' },
  { label: 'Novita AI', value: 'novita' },
  { label: 'GapGPT (گپ جی پی تی)', value: 'gapgpt' },
  { label: 'Samani (سمانی)', value: 'samani' },
  { label: 'ParsTech (پارستک)', value: 'parstech' },
  { label: 'Ayez (آیز)', value: 'ayez' },
  { label: 'Fal.ai (فال)', value: 'fal' },
]

const providerLabels: Record<string, string> = Object.fromEntries(
  providerOptions.map((p) => [p.value, p.label]),
)

const faNum = (v: number): string => new Intl.NumberFormat('fa-IR').format(v)

/* ── Computed ── */
const activeKeys = computed(() => props.keys.filter((k) => k.status === 'active'))
const inactiveKeys = computed(() => props.keys.filter((k) => k.status !== 'active'))

/* ── Actions ── */
function addKey(): void {
  if (!form.provider || !form.api_key) return
  form.post('/platform/ai-management', {
    preserveScroll: true,
    onSuccess: () => {
      showAddModal.value = false
      form.reset()
    },
  })
}

function toggleKey(id: number): void {
  router.post(`/platform/ai-management/${id}/toggle`, {}, { preserveScroll: true, only: ['keys'] })
}

function deleteKey(id: number): void {
  deletingId.value = id
  router.delete(`/platform/ai-management/${id}`, {
    preserveScroll: true,
    only: ["keys"],
    onFinish: () => { deletingId.value = null },
  })
}

async function testKey(id: number): Promise<void> {
  testingId.value = id
  testResult.value = null
  try {
    const res = await fetch(`/platform/ai-management/${id}/test`, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const data = await res.json()
    testResult.value = data.ok
      ? { ok: true, message: `پاسخ: ${data.response} (${data.latency_ms}ms)` }
      : { ok: false, message: `خطا: ${data.error}` }
  } catch {
    testResult.value = { ok: false, message: 'خطا در ارتباط' }
  }
  testingId.value = null
}


async function detectModels(id: number, provider: string, apiKey: string): Promise<void> {
  detectingId.value = id
  detectedModels.value = []
  try {
    const res = await fetch('/api/content/detect-models', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ provider, api_key: apiKey }),
    })
    const data = await res.json()
    if (data.success) {
      detectedModels.value = data.models
    } else {
      testResult.value = { ok: false, message: 'خطا در تشخیص مدل‌ها: ' + (data.error || 'نامشخص') }
    }
  } catch {
    testResult.value = { ok: false, message: 'خطا در ارتباط' }
  }
  detectingId.value = null
}
function providerKeyLabel(p: string): string {
  return providerLabels[p] ?? p
}
</script>

<template>
  <Head title="مدیریت هوش مصنوعی" />
  <PlatformLayout>
    <VPageHeader
      title="مدیریت هوش مصنوعی"
      description="مدیریت کلیدهای API، پروایدرها و نظارت بر مصرف توکن — فقط برای سوپرادمین."
    >
      <template #actions>
        <VButton @click="showAddModal = true">
          <VIcon name="plus" size="sm" class="inline-block align-middle" />
          افزودن کلید
        </VButton>
      </template>
    </VPageHeader>

    <!-- ── Stat Cards ── -->
    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <VStatCard
        label="کلیدهای فعال"
        :value="activeKeys.length"
        icon="key"
        icon-tone="success"
        hint="پروایدرهای فعال"
      />
      <VStatCard
        label="درخواست امروز"
        :value="usage.today"
        icon="chart-line"
        icon-tone="brand"
        hint="تعداد درخواست‌ها"
      />
      <VStatCard
        label="درخواست هفته"
        :value="usage.week"
        icon="trend-up"
        icon-tone="info"
        hint="۷ روز اخیر"
      />
      <VStatCard
        label="درخواست ماه"
        :value="usage.month"
        icon="calendar"
        icon-tone="neutral"
        hint="۳۰ روز اخیر"
      />
    </div>

    <!-- ── Test Result Alert ── -->
    <VCard v-if="testResult" class="mt-6">
      <div
        class="rounded-xl p-4 text-sm"
        :class="
          testResult.ok
            ? 'bg-success-50 text-success-700'
            : 'bg-danger-50 text-danger-700'
        "
      >
        <VIcon
          :name="testResult.ok ? 'check' : 'x'"
          size="sm"
          class="inline-block align-middle ms-1"
        />
        {{ testResult.message }}
      </div>
    </VCard>

    <!-- ── Active Keys ── -->
    <VCard title="کلیدهای فعال" class="mt-6">
      <template v-if="activeKeys.length">
        <div class="divide-line divide-y">
          <div
            v-for="k in activeKeys"
            :key="k.id"
            class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0 flex-1">
              <p class="text-ink-strong text-sm font-semibold">
                {{ providerKeyLabel(k.provider) }}
              </p>
              <p class="text-ink-muted mt-0.5 text-xs" dir="ltr">
                {{ k.provider }}
              </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <VBadge tone="success">فعال</VBadge>
              <VButton
                size="sm"
                variant="ghost"
                :disabled="testingId === k.id"
                @click="testKey(k.id)"
              >
                {{ testingId === k.id ? '...' : 'تست' }}
              </VButton>
              <VButton size="sm" variant="ghost" :disabled="detectingId === k.id" @click="detectModels(k.id)">
                {{ detectingId === k.id ? '...' : 'تضخیص مدل' }}
              </VButton>
              <VButton size="sm" variant="ghost" @click="toggleKey(k.id)">
                غیرفعال
              </VButton>
              <VButton
                size="sm"
                variant="danger"
                :disabled="deletingId === k.id"
                @click="deleteKey(k.id)"
              >
                حذف
              </VButton>
            </div>
          </div>
        </div>
      </template>
      <div v-else class="text-ink-muted py-8 text-center text-sm">
        کلید فعالی وجود ندارد.
      </div>
    </VCard>

    <!-- ── Inactive Keys ── -->
    <VCard v-if="inactiveKeys.length" title="غیرفعال" class="mt-6 opacity-70">
      <div class="divide-line divide-y">
        <div
          v-for="k in inactiveKeys"
          :key="k.id"
          class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0 flex-1">
            <p class="text-ink-strong text-sm font-semibold line-through">
              {{ providerKeyLabel(k.provider) }}
            </p>
            <p class="text-ink-muted mt-0.5 text-xs" dir="ltr">{{ k.provider }}</p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <VBadge tone="neutral">غیرفعال</VBadge>
            <VButton size="sm" variant="ghost" @click="toggleKey(k.id)">
              فعال
            </VButton>
            <VButton size="sm" variant="danger" @click="deleteKey(k.id)">
              حذف
            </VButton>
          </div>
        </div>
      </div>
    </VCard>

    <!-- ── Recent Logs ── -->
    <VCard title="آخرین درخواست‌ها" class="mt-6">
      <template v-if="recentLogs.length">
        <div class="divide-line divide-y">
          <div
            v-for="l in recentLogs"
            :key="l.id"
            class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm"
          >
            <div class="flex items-center gap-3">
              <VBadge tone="info" size="sm">{{ l.provider ?? '?' }}</VBadge>
              <span class="text-ink-muted text-xs" dir="ltr">{{ l.model ?? '---' }}</span>
            </div>
            <div class="flex items-center gap-4 text-xs">
              <span class="text-ink-muted">
                {{ faNum((l.input_tokens ?? 0) + (l.output_tokens ?? 0)) }} توکن
              </span>
              <span class="text-ink-muted" dir="ltr">{{ l.occurred_at }}</span>
            </div>
          </div>
        </div>
      </template>
      <div v-else class="text-ink-muted py-8 text-center text-sm">
        درخواستی ثبت نشده.
      </div>
    </VCard>

    <!-- ── Supported Providers ── -->
    <VCard title="پروایدرهای پشتیبانی‌شده" description="تمام سرویس‌های هوش مصنوعی که توسط سیستم پشتیبانی میشوند." class="mt-6">
      <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="opt in providerOptions"
          :key="opt.value"
          class="border-line flex items-center justify-between rounded-xl border px-3 py-2.5"
        >
          <span class="text-ink-strong text-sm">{{ opt.label }}</span>
          <VBadge
            :tone="
              activeKeys.some((k) => k.provider === opt.value)
                ? 'success'
                : 'neutral'
            "
            size="sm"
          >
            {{ activeKeys.some((k) => k.provider === opt.value) ? 'فعال' : 'غیرفعال' }}
          </VBadge>
        </div>
      </div>
    </VCard>

    <!-- ── Add Modal ── -->
    <VModal v-model="showAddModal" title="افزودن کلید جدید">
      <div class="space-y-4">
        <VSelect
          v-model="form.provider"
          label="پروایدر"
          :options="providerOptions"
        />
        <VInput
          v-model="form.api_key"
          label="کلید API"
          type="password"
          dir="ltr"
          placeholder="sk-..."
        />
        <VInput
          v-model="form.model"
          label="مدل (اختیاری)"
          type="text"
          dir="ltr"
          placeholder="پیش‌فضل خودکار"
        />
      </div>
      <template #footer>
        <div class="flex gap-3">
          <VButton @click="addKey">ذخیره</VButton>
          <VButton variant="ghost" @click="showAddModal = false">انصراف</VButton>
        </div>
      </template>
    </VModal>
  </PlatformLayout>
</template>
