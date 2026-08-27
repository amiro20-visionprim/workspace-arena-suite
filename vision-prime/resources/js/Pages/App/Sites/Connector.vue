<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppLayout from '@/app/layouts/AppLayout.vue'
import VAlert from '@/shared/ui/VAlert.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'
import VPageHeader from '@/shared/ui/VPageHeader.vue'

/**
 * اتصال وردپرس — بازسازی UX (v1.3):
 * فقط سه قدم؛ هیچ REST URL و رمز Application Passwordی از کاربر پرسیده نمی‌شود.
 *   ۱) دانلود پلاگین (یک کلیک)
 *   ۲) ساخت توکن اتصال + کپی (۱۵ دقیقه اعتبار)
 *   ۳) paste توکن در تنظیمات پلاگین + بررسی اتصال
 * ارتباط از این پس فقط با امضای HMAC انجام می‌شود؛ مسیر REST قدیمی حذف شد.
 */
const props = defineProps<{
  site: { id: number; name: string; canonicalUrl: string }
  platformUrl: string
  connection: null | {
    status: string
    platformUrl: string | null
    pluginVersion: string | null
    lastSeenAt: string | null
    health: Record<string, unknown>
  }
}>()

const tokenBusy = ref(false)
const token = ref('')
const tokenExpiresAt = ref('')
const copied = ref(false)
const checkBusy = ref(false)
const checkResult = ref<null | { success: boolean; message: string }>(null)

const connected = computed(() => props.connection?.status === 'connected')
const connectionTone = computed(() =>
  props.connection?.status === 'connected' ? 'success' : props.connection ? 'warning' : 'neutral',
)
const connectionLabel = computed(() => {
  if (!props.connection) return 'متصل نیست'
  if (props.connection.status === 'connected') return 'متصل و سالم'
  return props.connection.status === 'degraded' ? 'قطع/ناسالم' : props.connection.status
})

async function generateToken(): Promise<void> {
  tokenBusy.value = true
  try {
    const res = await fetch(`/app/sites/${props.site.id}/connector/pairing-token`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
      },
    })
    const data = (await res.json()) as { token?: string; expires_at?: string }
    token.value = data.token ?? ''
    tokenExpiresAt.value = data.expires_at ?? ''
    copied.value = false
  } catch {
    token.value = ''
  } finally {
    tokenBusy.value = false
  }
}

function copy(text: string, what: 'token' | 'url'): void {
  navigator.clipboard.writeText(text)
  if (what === 'token') copied.value = true
}

async function checkConnection(): Promise<void> {
  checkBusy.value = true
  checkResult.value = null
  try {
    const res = await fetch(`/app/sites/${props.site.id}/connector/check`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
      },
    })
    const data = (await res.json()) as {
      success: boolean
      error?: string
      health?: { plugin_version?: string; wordpress_version?: string }
    }
    checkResult.value = {
      success: data.success,
      message: data.success
        ? `✅ پلاگین پاسخ داد — نسخهٔ پلاگین: ${data.health?.plugin_version ?? '?'} · وردپرس: ${data.health?.wordpress_version ?? '?'}`
        : `❌ ${data.error ?? 'پاسخی دریافت نشد'}`,
    }
    router.reload({ only: ['connection'] })
  } catch (e) {
    checkResult.value = {
      success: false,
      message: `❌ ${e instanceof Error ? e.message : 'خطای شبکه'}`,
    }
  } finally {
    checkBusy.value = false
  }
}

function csrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]) : ''
}
</script>

<template>
  <Head :title="`اتصال وردپرس — ${site.name}`" />
  <AppLayout>
    <VPageHeader :title="`اتصال وردپرس — ${site.name}`" :subtitle="site.canonicalUrl" />

    <div class="space-y-6">
      <!-- وضعیت فعلی -->
      <VCard>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="text-lg">🔌</span>
            <div>
              <p class="text-ink-strong text-sm font-semibold">وضعیت اتصال</p>
              <p class="text-ink-muted text-xs">
                {{
                  connection?.platformUrl
                    ? `آدرس متصل‌شده: ${connection.platformUrl}`
                    : 'هنوز پلاگینی جفت نشده است'
                }}
                <template v-if="connection?.pluginVersion">
                  · نسخهٔ پلاگین {{ connection.pluginVersion }}
                </template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <VBadge :tone="connectionTone" size="sm">{{ connectionLabel }}</VBadge>
            <VButton
              v-if="connection"
              size="sm"
              variant="secondary"
              :loading="checkBusy"
              @click="checkConnection"
            >
              بررسی اتصال
            </VButton>
          </div>
        </div>
        <VAlert v-if="checkResult" :tone="checkResult.success ? 'success' : 'danger'" class="mt-4">
          {{ checkResult.message }}
        </VAlert>
      </VCard>

      <!-- ویزارد ۳ مرحله‌ای -->
      <div v-if="!connected" class="grid gap-5 lg:grid-cols-3">
        <!-- مرحله ۱ -->
        <VCard>
          <div class="mb-3 flex items-center gap-2">
            <span
              class="bg-brand-600 inline-flex size-7 items-center justify-center rounded-full text-sm font-bold text-white"
              >۱</span
            >
            <p class="text-ink-strong text-sm font-bold">دانلود پلاگین</p>
          </div>
          <p class="text-ink-muted mb-4 text-xs leading-6">
            فایل zip آمادهٔ نصب را بگیرید و در وردپرس از
            <span class="text-ink-strong" dir="ltr">افزونه‌ها ← افزودن ← بارگذاری</span>
            نصب و فعال کنید.
          </p>
          <a
            :href="`/app/sites/${site.id}/connector/plugin`"
            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700"
          >
            ⬇️ دانلود Vision Prime Connector
          </a>
        </VCard>

        <!-- مرحله ۲ -->
        <VCard>
          <div class="mb-3 flex items-center gap-2">
            <span
              class="bg-brand-600 inline-flex size-7 items-center justify-center rounded-full text-sm font-bold text-white"
              >۲</span
            >
            <p class="text-ink-strong text-sm font-bold">توکن اتصال</p>
          </div>
          <p class="text-ink-muted mb-4 text-xs leading-6">
            با یک کلیک، توکن یک‌بارمصرف بسازید. اعتبار ۱۵ دقیقه است و پس از مصرف باطل می‌شود.
          </p>
          <VButton v-if="!token" class="w-full" :loading="tokenBusy" @click="generateToken">
            🔑 ساخت توکن اتصال
          </VButton>
          <div v-else class="space-y-3">
            <div
              class="rounded-lg border border-dashed p-3 font-mono text-[11px] break-all select-all"
              dir="ltr"
            >
              {{ token }}
            </div>
            <div class="flex gap-2">
              <VButton size="sm" variant="secondary" class="flex-1" @click="copy(token, 'token')">
                {{ copied ? '✓ کپی شد' : 'کپی توکن' }}
              </VButton>
              <VButton size="sm" variant="ghost" :loading="tokenBusy" @click="generateToken">
                توکن جدید
              </VButton>
            </div>
            <p class="text-ink-muted text-[11px]">انقضا: {{ tokenExpiresAt }}</p>
          </div>
        </VCard>

        <!-- مرحله ۳ -->
        <VCard>
          <div class="mb-3 flex items-center gap-2">
            <span
              class="bg-brand-600 inline-flex size-7 items-center justify-center rounded-full text-sm font-bold text-white"
              >۳</span
            >
            <p class="text-ink-strong text-sm font-bold">جفت‌سازی در وردپرس</p>
          </div>
          <p class="text-ink-muted mb-3 text-xs leading-6">
            در پیشخوان وردپرس، منوی <span class="text-ink-strong">Vision Prime</span> را باز کنید و
            فقط این دو مقدار را پیست کنید:
          </p>
          <div class="space-y-2 text-xs">
            <div class="bg-surface-muted flex items-center justify-between gap-2 rounded-lg p-2">
              <span class="text-ink-muted">آدرس پلتفرم</span>
              <button
                class="text-brand-700 font-mono text-[11px] underline"
                dir="ltr"
                @click="copy(platformUrl, 'url')"
              >
                {{ platformUrl }}
              </button>
            </div>
            <div class="bg-surface-muted flex items-center justify-between gap-2 rounded-lg p-2">
              <span class="text-ink-muted">توکن اتصال</span>
              <span class="text-ink-muted text-[11px]">از مرحلهٔ ۲</span>
            </div>
          </div>
          <VAlert v-if="!token" tone="info" class="mt-3"> اول توکن مرحلهٔ ۲ را بسازید. </VAlert>
          <VButton
            v-else
            class="mt-3 w-full"
            variant="secondary"
            :loading="checkBusy"
            @click="checkConnection"
          >
            تأیید و بررسی اتصال
          </VButton>
        </VCard>
      </div>

      <!-- متصل: راهنمای بعدی -->
      <VCard v-else>
        <p class="text-ink-strong mb-2 text-sm font-semibold"
          >✅ این سایت از طریق کانکتور متصل است</p
        >
        <ul class="text-ink-muted list-inside list-disc space-y-1 text-xs leading-6">
          <li>انتشار مقاله و محصول (پیش‌نویس یا منتشرشده) مستقیم از همین پلتفرم انجام می‌شود.</li>
          <li
            >همگام‌سازی محتوای وردپرس به‌صورت امن و امضاشده انجام می‌شود؛ نیازی به رمز وردپرس
            نداریم.</li
          >
          <li>با دکمهٔ «بررسی اتصال» سلامت لحظه‌ای پلاگین را ببینید.</li>
        </ul>
      </VCard>
    </div>
  </AppLayout>
</template>
