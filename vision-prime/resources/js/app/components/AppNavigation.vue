<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import VIcon, { type IconName } from '@/shared/ui/VIcon.vue'

interface NavigationItem {
  label: string
  hint?: string
  href: string
  icon: IconName
  exact?: boolean
}

interface NavigationGroup {
  label: string
  icon: IconName
  items: NavigationItem[]
}

const baseGroups: NavigationGroup[] = [
  {
    label: 'نمای کلی',
    icon: 'chart-line',
    items: [
      { label: 'داشبورد', href: '/app/dashboard', icon: 'chart-line', exact: true },
      { label: 'مرکز آموزش', href: '/app/training', icon: 'graduation' },
      { label: 'واژه‌نامهٔ اصطلاحات', href: '/app/training/glossary', icon: 'info' },
    ],
  },
  {
    label: 'استودیوی محتوا',
    icon: 'sparkles',
    items: [
      {
        label: 'تولید مقاله',
        hint: 'سرعتی و حرفه‌ای',
        href: '/app/ai-drafts/article/create',
        icon: 'file',
      },
      {
        label: 'تولید محصول',
        hint: 'با دادهٔ ووکامرس',
        href: '/app/ai-drafts/product/create',
        icon: 'shopping-bag',
      },
      { label: 'پیش‌نویس‌ها', href: '/app/ai-drafts', icon: 'document' },
      { label: 'تقویم محتوایی', href: '/app/content-calendar', icon: 'calendar' },
    ],
  },
  {
    label: 'هوش رشد',
    icon: 'trend-up',
    items: [
      { label: 'سرچ کنسول', href: '/app/gsc', icon: 'search' },
      { label: 'فرصت‌های رشد', href: '/app/opportunities', icon: 'lightbulb' },
      { label: 'صفحات درآمدزا', href: '/app/money-pages', icon: 'gauge' },
      { label: 'ریسک‌های تبدیل', href: '/app/conversion-risks', icon: 'trend-down' },
      { label: 'URLها و محتوا', href: '/app/url-profiles', icon: 'news' },
    ],
  },
  {
    label: 'فضای کاری',
    icon: 'building',
    items: [
      { label: 'مشتریان', href: '/app/clients', icon: 'building' },
      { label: 'پروژه‌ها', href: '/app/projects', icon: 'users' },
      { label: 'سایت‌ها', href: '/app/sites', icon: 'activity' },
    ],
  },
  {
    label: 'گردش‌کار',
    icon: 'list',
    items: [
      { label: 'بررسی و تأییدها', href: '/app/reviews', icon: 'user-check' },
      { label: 'تغییرات اجرایی', href: '/app/commands', icon: 'zap' },
      { label: 'پیشنهادها', href: '/app/recommendations', icon: 'list' },
      { label: 'گزارش‌ها', href: '/app/reports', icon: 'chart-bar' },
    ],
  },
  {
    label: 'تنظیمات',
    icon: 'settings',
    items: [
      { label: 'سازمان و اعضا', href: '/app/settings/organization', icon: 'users' },
      { label: 'یکپارچه‌سازی‌ها', href: '/app/settings/integrations', icon: 'zap' },
      { label: 'گزارش ممیزی', href: '/app/settings/audit-log', icon: 'shield' },
    ],
  },
]

const groups = computed<NavigationGroup[]>(() => {
  const groupsList = [...baseGroups]
  if (canViewMarketing.value) {
    groupsList.splice(4, 0, {
      label: 'بازاریابی',
      icon: 'megaphone',
      items: [{ label: 'لیدها و دادهٔ تبلیغات', href: '/app/marketing', icon: 'megaphone' }],
    })
  }
  return groupsList
})

const page = usePage<{ permissions?: string[] }>()
const canViewMarketing = computed(
  () => page.props.permissions?.includes('marketing.view.organization') ?? false,
)

const currentPath = computed(() => page.url.split('?')[0])

function isActive(item: NavigationItem): boolean {
  return item.exact
    ? currentPath.value === item.href
    : currentPath.value === item.href || currentPath.value.startsWith(`${item.href}/`)
}
</script>

<template>
  <nav class="space-y-5" aria-label="ناوبری فضای کاری">
    <!-- ⚡ اکشن اصلی: تولید محتوا -->
    <Link
      href="/app/ai-drafts/article/create"
      class="group from-grad-from via-grad-via to-grad-to rounded-card relative flex items-center justify-between overflow-hidden border border-white/20 bg-gradient-to-l p-3 text-white shadow-[0_8px_24px_var(--color-glow-brand)] transition-all duration-300 hover:-translate-y-0.5 hover:brightness-110 active:translate-y-0"
    >
      <span class="flex items-center gap-2.5">
        <span
          class="flex size-8 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm"
        >
          <VIcon name="sparkles" tone="neutral" size="sm" class="text-white" />
        </span>
        <span class="font-display text-sm font-bold">تولید محتوا</span>
      </span>
      <span
        class="text-lg opacity-60 transition-transform duration-300 group-hover:-translate-x-0.5"
        aria-hidden="true"
        >←</span
      >
      <!-- درخشش شیشه‌ای -->
      <span
        class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/15 via-transparent to-transparent"
        aria-hidden="true"
      />
    </Link>

    <section v-for="group in groups" :key="group.label">
      <!-- سرگروه: مینیمال با خط مویی -->
      <div class="flex items-center gap-2 px-3">
        <p class="text-ink-muted text-[10px] font-bold tracking-widest">{{ group.label }}</p>
        <span class="bg-line h-px flex-1" aria-hidden="true" />
      </div>

      <ul class="mt-1.5 space-y-0.5">
        <li v-for="item in group.items" :key="item.href">
          <Link
            :href="item.href"
            :class="[
              'transition-ui group rounded-ui relative flex items-center gap-3 px-3 py-2 text-sm',
              isActive(item)
                ? 'bg-brand-50/70 text-brand-700 font-semibold shadow-[inset_0_1px_0_rgb(255_255_255/0.6)] backdrop-blur-sm'
                : 'text-ink hover:bg-surface-muted/60 hover:text-ink-strong font-medium',
            ]"
          >
            <!-- نشانگر فعال: نوار گرادیانی سمت راست (RTL) -->
            <span
              v-if="isActive(item)"
              class="from-grad-via to-grad-from absolute inset-y-1.5 end-0 w-[3px] rounded-full bg-gradient-to-b"
              aria-hidden="true"
            />
            <span
              :class="[
                'transition-ui flex size-7 shrink-0 items-center justify-center rounded-lg',
                isActive(item)
                  ? 'bg-brand-100/90 text-brand-700 shadow-sm backdrop-blur-sm'
                  : 'bg-surface-muted/70 text-ink-muted group-hover:text-ink-strong group-hover:bg-white/70 group-hover:shadow-sm',
              ]"
            >
              <VIcon :name="item.icon" :tone="isActive(item) ? 'brand' : 'neutral'" size="sm" />
            </span>
            <span class="flex flex-1 flex-col leading-tight">
              <span>{{ item.label }}</span>
              <span
                v-if="item.hint && isActive(item)"
                class="text-brand-600/80 mt-0.5 text-[10px] font-normal"
                >{{ item.hint }}</span
              >
            </span>
          </Link>
        </li>
      </ul>
    </section>
  </nav>
</template>
