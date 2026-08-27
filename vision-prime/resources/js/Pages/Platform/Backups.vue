<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import AppLayout from '@/app/layouts/AppLayout.vue'
import VAlert from '@/shared/ui/VAlert.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'
import VCard from '@/shared/ui/VCard.vue'
import VInput from '@/shared/ui/VInput.vue'
import VPageHeader from '@/shared/ui/VPageHeader.vue'

const page = usePage<{ flash?: { status?: string; error?: string } }>()

const props = defineProps<{
  settings: { enabled: boolean; notify_email: string; keep_days: number }
  files: { name: string; size_kb: number; created_at: string }[]
  lastRun: null | { at: string; ok: boolean; file: string }
}>()

const form = reactive({
  enabled: props.settings.enabled,
  notify_email: props.settings.notify_email,
  keep_days: props.settings.keep_days,
})
const saving = ref(false)
const running = ref(false)

function save(): void {
  saving.value = true
  router.post('/platform/backups/settings', form, {
    preserveScroll: true,
    onFinish: () => {
      saving.value = false
    },
  })
}

function runNow(): void {
  running.value = true
  router.post(
    '/platform/backups/run',
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        running.value = false
      },
    },
  )
}
</script>

<template>
  <Head title="مدیریت بکاپ" />
  <AppLayout>
    <VPageHeader
      title="مدیریت بکاپ"
      subtitle="بکاپ روزانهٔ خودکار دیتابیس + اطلاع‌رسانی ایمیلی + دانلود نسخه‌ها"
    />

    <div class="space-y-6">
      <VAlert v-if="page.props.flash?.status" tone="success">{{ page.props.flash.status }}</VAlert>
      <VAlert v-if="page.props.flash?.error" tone="danger">{{ page.props.flash.error }}</VAlert>

      <!-- وضعیت آخرین اجرا -->
      <VCard>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="text-lg">🗄️</span>
            <div>
              <p class="text-ink-strong text-sm font-semibold">آخرین بکاپ</p>
              <p class="text-ink-muted text-xs">
                {{
                  lastRun
                    ? `${lastRun.at} — ${lastRun.file || 'بدون نام فایل'}`
                    : 'هنوز از پنل اجرا نشده (بکاپ شبانهٔ خودکار ممکن است فعال باشد)'
                }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <VBadge v-if="lastRun" :tone="lastRun.ok ? 'success' : 'danger'" size="sm">
              {{ lastRun.ok ? 'موفق' : 'ناموفق' }}
            </VBadge>
            <VButton size="sm" :loading="running" @click="runNow">▶️ اجرای بکاپ همین حالا</VButton>
          </div>
        </div>
      </VCard>

      <!-- تنظیمات -->
      <VCard title="⚙️ تنظیمات">
        <form class="grid gap-4 md:grid-cols-3" @submit.prevent="save">
          <VInput
            v-model="form.notify_email"
            label="ایمیل اطلاع‌رسانی بکاپ"
            type="email"
            dir="ltr"
            placeholder="ops@your-agency.ir"
            hint="بعد از هر بکاپ (موفق یا ناموفق) ایمیل می‌گیرد"
          />
          <VInput
            v-model.number="form.keep_days"
            label="مدت نگه‌داری (روز)"
            type="number"
            hint="نسخه‌های قدیمی‌تر خودکار حذف می‌شوند"
          />
          <div>
            <label class="text-ink-muted mb-1 block text-xs font-medium">
              بکاپ خودکار روزانه (ساعت ۳ بامداد)
            </label>
            <label class="mt-2 flex cursor-pointer items-center gap-2 text-sm">
              <input v-model="form.enabled" type="checkbox" class="accent-brand-600 size-4" />
              <span>{{ form.enabled ? 'فعال' : 'غیرفعال' }}</span>
            </label>
          </div>
          <div class="md:col-span-3">
            <VButton type="submit" :loading="saving">💾 ذخیرهٔ تنظیمات</VButton>
          </div>
        </form>
      </VCard>

      <!-- فهرست نسخه‌ها -->
      <VCard title="📦 نسخه‌های بکاپ">
        <div v-if="files.length === 0" class="text-ink-muted py-8 text-center text-sm">
          هنوز نسخه‌ای ثبت نشده — «اجرا همین حالا» را بزنید.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-right text-sm">
            <thead>
              <tr class="text-ink-muted border-line border-b text-xs">
                <th class="p-2">فایل</th>
                <th class="p-2">حجم</th>
                <th class="p-2">زمان</th>
                <th class="p-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in files" :key="f.name" class="border-line border-b last:border-0">
                <td class="p-2 font-mono text-xs" dir="ltr">{{ f.name }}</td>
                <td class="p-2">{{ f.size_kb }} KB</td>
                <td class="p-2" dir="ltr">{{ f.created_at }}</td>
                <td class="p-2">
                  <a
                    :href="`/platform/backups/download/${encodeURIComponent(f.name)}`"
                    class="text-brand-700 text-xs underline"
                    >دانلود</a
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </VCard>
    </div>
  </AppLayout>
</template>
