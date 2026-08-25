<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import AppLayout from '@/app/layouts/AppLayout.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'
import VPageHeader from '@/shared/ui/VPageHeader.vue'
import VConfirmDialog from '@/shared/ui/VConfirmDialog.vue'

const props = defineProps<{
  site: { id: number; name: string; canonicalUrl: string }
  connection: null | {
    status: string
    platformUrl: string | null
    pluginVersion: string | null
    lastSeenAt: string | null
    health: Record<string, unknown>
  }
  wpCredentials: null | {
    wp_url: string
    wp_username: string
    has_password: boolean
    connected_at: string | null
  }
}>()
const page = usePage<{ flash?: { pairingToken?: string; pairingTokenExpiresAt?: string } }>()
function generateToken(): void {
  router.post(`/app/sites/${props.site.id}/connector/pairing-token`, {}, { preserveScroll: true })
}
const disconnectOpen = ref(false)
function copyToken(): void {
  navigator.clipboard.writeText(page.props.flash?.pairingToken ?? '')
}

const syncing = ref(false)
const syncResult = ref<null | { synced: number; errors: string[]; url_profiles_count: number }>(
  null,
)

async function syncWordPress() {
  syncing.value = true
  syncResult.value = null
  try {
    const res = await fetch('/api/content/sync-wordpress', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: JSON.stringify({ site_id: props.site.id }),
    })
    syncResult.value = await res.json()
  } catch (e) {
    syncResult.value = {
      synced: 0,
      errors: [e instanceof Error ? e.message : String(e)],
      url_profiles_count: 0,
    }
  }
  syncing.value = false
}
function disconnect(): void {
  router.post(`/app/sites/${props.site.id}/connector/disconnect`)
}

// WordPress credentials
const wpUrl = ref(props.wpCredentials?.wp_url || props.site.canonicalUrl || '')
const wpUser = ref(props.wpCredentials?.wp_username || '')
const wpPass = ref('')
const wpSaving = ref(false)
const wpMessage = ref<null | { type: 'success' | 'error'; text: string }>(null)

async function saveWpCredentials() {
  wpSaving.value = true
  wpMessage.value = null
  try {
    const res = await fetch(`/app/sites/${props.site.id}/connector/wp-credentials`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        wp_url: wpUrl.value,
        wp_username: wpUser.value,
        wp_app_password: wpPass.value,
      }),
    })
    const data = await res.json()
    if (data.success) {
      wpMessage.value = {
        type: 'success',
        text: `✅ ذخیره شد — کاربر: ${data.user_name || wpUser.value}`,
      }
      wpPass.value = ''
    } else {
      wpMessage.value = { type: 'error', text: `❌ ${data.error || 'خطا در ذخیره'}` }
    }
  } catch (e) {
    wpMessage.value = { type: 'error', text: `❌ ${e instanceof Error ? e.message : String(e)}` }
  }
  wpSaving.value = false
}

async function removeWpCredentials() {
  wpSaving.value = true
  wpMessage.value = null
  try {
    const res = await fetch(`/app/sites/${props.site.id}/connector/wp-credentials`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
    const data = await res.json()
    if (data.success) {
      wpMessage.value = { type: 'success', text: '✅ اطلاعات وردپرس حذف شد.' }
      wpUser.value = ''
      wpPass.value = ''
    }
  } catch (e) {
    wpMessage.value = { type: 'error', text: `❌ ${e instanceof Error ? e.message : String(e)}` }
  }
  wpSaving.value = false
}
</script>
<template>
  <Head :title="`اتصال وردپرس ${site.name}`" />
  <AppLayout>
    <VPageHeader
      title="اتصال وردپرس"
      :description="site.canonicalUrl"
      :breadcrumbs="[
        { label: 'سایت‌ها', href: '/app/sites' },
        { label: site.name, href: `/app/sites/${site.id}` },
        { label: 'اتصال وردپرس' },
      ]"
    />
    <VCard class="mt-8" title="وضعیت اتصال">
      <template #action
        ><VBadge :tone="connection?.status === 'connected' ? 'success' : 'warning'">{{
          connection?.status === 'connected' ? 'متصل' : 'اتصال برقرار نیست'
        }}</VBadge></template
      >
      <dl v-if="connection" class="divide-line divide-y">
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-ink-muted">آدرس پلتفرم</dt>
          <dd class="font-latin text-ink-strong" dir="ltr">{{ connection.platformUrl }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-ink-muted">نسخه پلاگین</dt>
          <dd>{{ connection.pluginVersion || '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-3">
          <dt class="text-ink-muted">آخرین فعالیت</dt>
          <dd>{{ connection.lastSeenAt || '—' }}</dd>
        </div>
      </dl>
      <p v-else class="text-ink-muted">
        برای اتصال، ابتدا افزونه وردپرس را نصب کنید، سپس توکن اتصال ایجاد کرده و در تنظیمات افزونه
        وارد کنید.
      </p>
      <div
        v-if="!connection || connection.status !== 'connected'"
        class="border-line mt-6 border-t pt-5"
      >
        <h3 class="text-ink-strong mb-3 text-sm font-bold">مرحله ۱ — دانلود و نصب افزونه</h3>
        <p class="text-ink-muted mb-3 text-sm leading-6">
          افزونه وردپرس را دانلود کرده و از مسیر
          <strong dir="ltr">افزونه‌ها → افزودن → بارگذاری افزونه</strong> نصب کنید.
        </p>
        <a
          href="/vision-prime-connector.zip"
          download
          class="transition-ui rounded-ui bg-brand-50 text-brand-700 hover:bg-brand-100 border-brand-200 inline-flex items-center gap-2 border px-4 py-2.5 text-sm font-bold"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="size-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
            />
          </svg>
          دانلود افزونه وردپرس
        </a>
        <p class="text-ink-muted mt-2 text-xs">
          نسخه ۱.۲.۰ · پشتیبانی از مقاله، صفحه و محصولات ووکامرس
        </p>
      </div>

      <div v-if="connection?.status === 'connected'" class="border-line mt-6 border-t pt-5">
        <h3 class="text-ink-strong mb-3 text-sm font-bold">همگام‌سازی محتوا از وردپرس</h3>
        <p class="text-ink-muted mb-3 text-sm">
          صفحات، مقالات و محصولات وردپرس را برای لینک‌سازی داخلی هوشمند دریافت کنید.
        </p>
        <VButton :loading="syncing" variant="secondary" @click="syncWordPress"
          >🔄 سینک محتوا از وردپرس</VButton
        >
        <div v-if="syncResult" class="rounded-card bg-surface mt-4 p-4">
          <p v-if="syncResult.errors?.length === 0" class="text-sm font-semibold text-green-600">
            {{ syncResult.synced }} محتوا سینک شد — {{ syncResult.url_profiles_count }} صفحه در
            پایگاه داده
          </p>
          <div v-else>
            <p class="text-sm text-red-600">خطا: {{ syncResult.errors?.join(', ') }}</p>
          </div>
        </div>
      </div>
      <div class="border-line mt-6 border-t pt-5">
        <h3 class="text-ink-strong mb-3 text-sm font-bold">تنظیمات انتشار در وردپرس</h3>
        <p class="text-ink-muted mb-3 text-sm">
          برای انتشار مستقیم مقالات و محصولات، اطلاعات WordPress REST API را وارد کنید.
        </p>
        <div v-if="wpCredentials" class="rounded-card bg-surface mb-4 p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-semibold text-green-600">✅ متصل به وردپرس</p>
              <p class="text-ink-muted mt-1 text-xs">
                کاربر: {{ wpCredentials.wp_username }} · URL: {{ wpCredentials.wp_url }}
              </p>
            </div>
            <VButton size="sm" variant="danger" :loading="wpSaving" @click="removeWpCredentials"
              >حذف</VButton
            >
          </div>
        </div>
        <div class="space-y-3">
          <div>
            <label class="text-ink-muted text-xs font-medium">آدرس سایت وردپرس</label>
            <input
              v-model="wpUrl"
              type="url"
              placeholder="https://example.com"
              class="border-line mt-1 w-full rounded-lg border px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label class="text-ink-muted text-xs font-medium">نام کاربری وردپرس</label>
            <input
              v-model="wpUser"
              type="text"
              placeholder="admin"
              class="border-line mt-1 w-full rounded-lg border px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label class="text-ink-muted text-xs font-medium">Application Password</label>
            <input
              v-model="wpPass"
              type="password"
              :placeholder="wpCredentials?.has_password ? '(قابل تغییر)' : 'xxxx xxxx xxxx xxxx'"
              class="border-line mt-1 w-full rounded-lg border px-3 py-2 text-sm"
            />
            <p class="text-ink-muted mt-1 text-xs">
              از wp-admin → کاربران → ویرایش → Application Passwords بسازید
            </p>
          </div>
          <VButton :loading="wpSaving" variant="primary" size="sm" @click="saveWpCredentials"
            >💾 ذخیره و تست اتصال</VButton
          >
          <p
            v-if="wpMessage"
            :class="wpMessage.type === 'success' ? 'text-green-600' : 'text-red-600'"
            class="text-xs"
          >
            {{ wpMessage.text }}
          </p>
        </div>
      </div>

      <div class="border-line mt-6 border-t pt-5">
        <h3 class="text-ink-strong mb-3 text-sm font-bold">مرحله ۲ — جفت‌سازی</h3>
        <div class="flex flex-wrap gap-3">
          <VButton @click="generateToken">ایجاد توکن اتصال</VButton
          ><VButton
            v-if="connection?.status === 'connected'"
            variant="danger"
            @click="disconnectOpen = true"
            >قطع اتصال</VButton
          >
        </div>
        <div v-if="page.props.flash?.pairingToken" class="rounded-card bg-warning-50 mt-4 p-4">
          <p class="text-warning-700 text-sm font-semibold">
            این توکن فقط اکنون نمایش داده می‌شود — کپی کنید و در وردپرس وارد کنید.
          </p>
          <code
            class="rounded-ui bg-surface font-latin text-ink-strong mt-3 block p-3 text-sm break-all"
            dir="ltr"
            >{{ page.props.flash.pairingToken }}</code
          >
          <div class="mt-3 flex items-center gap-3">
            <VButton size="sm" variant="secondary" @click="copyToken">کپی توکن</VButton
            ><span class="text-ink-muted text-sm"
              >انقضا: {{ page.props.flash.pairingTokenExpiresAt }}</span
            >
          </div>
        </div>
      </div>
    </VCard>
    <VConfirmDialog
      v-model="disconnectOpen"
      title="قطع اتصال وردپرس"
      description="کلید محرمانه این سایت حذف می‌شود و افزونه تا اتصال مجدد قادر به ارسال درخواست معتبر نخواهد بود."
      confirm-label="قطع اتصال"
      tone="danger"
      @confirm="disconnect"
    />
  </AppLayout>
</template>
