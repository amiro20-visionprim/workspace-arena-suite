<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ArrowUpRight, Play, Pause, RotateCcw } from '@lucide/vue'
import { ref, onMounted, onUnmounted } from 'vue'

import MarketingPageHero from '@/marketing/components/MarketingPageHero.vue'
import MarketingLayout from '@/marketing/layouts/MarketingLayout.vue'
import VBadge from '@/shared/ui/VBadge.vue'
import VButton from '@/shared/ui/VButton.vue'

const demos = [
  {
    id: 'growth-opportunities',
    title: 'هوش فرصت‌های رشد',
    description: 'فرصت‌های رشد را با داده واقعی سرچ کنسول شناسایی و اولویت‌بندی کنید.',
    features: ['تحلیل CTR Gap', 'ارزش‌گذاری تجاری', 'اولویت‌بندی خودکار'],
  },
  {
    id: 'content-generation',
    title: 'تولید محتوای هوشمند',
    description: 'محتوای SEO-شده با ساختار حرفه‌ای و Schema خودکار تولید کنید.',
    features: ['تولید با AI', 'Schema خودکار', 'بهینه‌سازی عنوان'],
  },
  {
    id: 'approval-workflow',
    title: 'گردش‌کار تأیید',
    description: 'هر تغییر قبل از اجرا، تأیید مشتری را می‌گیرد و قابل بازگشت است.',
    features: ['تأیید قبل از اجرا', 'بازگشت آنی', ' Audit Trail'],
  },
]

const activeDemo = ref(0)
let autoPlayInterval: ReturnType<typeof setInterval> | null = null

function startAutoPlay() {
  autoPlayInterval = setInterval(() => {
    activeDemo.value = (activeDemo.value + 1) % demos.length
  }, 4000)
}

function stopAutoPlay() {
  if (autoPlayInterval) {
    clearInterval(autoPlayInterval)
    autoPlayInterval = null
  }
}

onMounted(() => startAutoPlay())
onUnmounted(() => stopAutoPlay())
</script>

<template>
  <Head title="دموی زنده — VisionPrime Suite" />
  <MarketingLayout>
    <MarketingPageHero
      title="دموی زنده محصول"
      description="سوئیت را در عمل ببینید — تعاملی و بدون نیاز به ثبت‌نام."
    />

    <section class="mx-auto max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
      <div class="grid gap-8 lg:grid-cols-[300px_1fr]">
        <!-- Demo Selector -->
        <div class="space-y-3">
          <div
            v-for="(demo, index) in demos"
            :key="demo.id"
            class="cursor-pointer rounded-xl border p-4 transition-all duration-300"
            :class="
              activeDemo === index
                ? 'border-brand-500 bg-brand-50 shadow-md'
                : 'border-line bg-surface hover:border-brand-200'
            "
            @click="activeDemo = index"
          >
            <div class="flex items-center gap-3">
              <span
                class="flex size-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold"
                :class="
                  activeDemo === index
                    ? 'bg-brand-500 text-white'
                    : 'bg-surface-alt text-ink-muted'
                "
              >
                {{ index + 1 }}
              </span>
              <div>
                <h3 class="text-ink-strong text-sm font-bold">{{ demo.title }}</h3>
                <p class="text-ink-muted text-xs">{{ demo.description }}</p>
              </div>
            </div>
          </div>

          <!-- Controls -->
          <div class="flex gap-2 pt-2">
            <button
              class="flex size-9 items-center justify-center rounded-lg border border-line bg-surface transition-colors hover:bg-surface-alt"
              @click="stopAutoPlay"
            >
              <Pause class="size-4 text-ink-muted" />
            </button>
            <button
              class="flex size-9 items-center justify-center rounded-lg border border-line bg-surface transition-colors hover:bg-surface-alt"
              @click="startAutoPlay"
            >
              <Play class="size-4 text-ink-muted" />
            </button>
            <button
              class="flex size-9 items-center justify-center rounded-lg border border-line bg-surface transition-colors hover:bg-surface-alt"
              @click="activeDemo = 0"
            >
              <RotateCcw class="size-4 text-ink-muted" />
            </button>
          </div>
        </div>

        <!-- Demo Display -->
        <div class="border-line bg-canvas relative overflow-hidden rounded-2xl border">
          <!-- Animated Background -->
          <div class="pointer-events-none absolute inset-0">
            <div class="absolute -top-20 right-1/4 size-60 rounded-full bg-brand-200/30 blur-3xl" />
            <div class="absolute -bottom-20 left-1/4 size-60 rounded-full bg-violet-200/30 blur-3xl" />
          </div>

          <!-- Dashboard Mock -->
          <div class="relative p-6">
            <!-- Top Bar -->
            <div class="mb-6 flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="flex gap-1.5">
                  <div class="size-3 rounded-full bg-red-400" />
                  <div class="size-3 rounded-full bg-yellow-400" />
                  <div class="size-3 rounded-full bg-green-400" />
                </div>
                <span class="font-latin text-ink-muted text-xs">visionprime-suite.ir</span>
              </div>
              <VBadge tone="success">آنلاین</VBadge>
            </div>

            <!-- Dashboard Grid -->
            <div class="grid grid-cols-2 gap-4">
              <!-- Chart Card -->
              <div class="border-line bg-surface rounded-xl border p-4">
                <p class="text-ink-muted text-xs font-bold">ترافیک ارگانیک</p>
                <div class="mt-3 flex items-end gap-1">
                  <div
                    v-for="h in [40, 65, 45, 80, 55, 90, 70]"
                    :key="h"
                    class="bg-brand-400 w-full rounded-t transition-all duration-700"
                    :style="{ height: h + 'px' }"
                  />
                </div>
              </div>

              <!-- Stats Card -->
              <div class="border-line bg-surface rounded-xl border p-4">
                <p class="text-ink-muted text-xs font-bold">فرصت‌های رشد</p>
                <p class="text-gradient-brand mt-2 text-3xl font-bold">۴۸+</p>
                <p class="text-success-600 mt-1 text-xs">+۱۲٪ نسبت به ماه قبل</p>
              </div>

              <!-- Activity Card -->
              <div class="border-line bg-surface col-span-2 rounded-xl border p-4">
                <p class="text-ink-muted text-xs font-bold">فعالیت‌های اخیر</p>
                <div class="mt-3 space-y-2">
                  <div class="flex items-center gap-2">
                    <div class="bg-success-500 size-2 rounded-full" />
                    <span class="text-ink text-xs">بهینه‌سازی عنوان صفحه محصول — تأیید شد</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="bg-brand-500 size-2 rounded-full" />
                    <span class="text-ink text-xs">تولید محتوای جدید — در انتظار تأیید</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="bg-yellow-500 size-2 rounded-full" />
                    <span class="text-ink text-xs">شناسایی ۳ فرصت رشد جدید</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Demo Label -->
            <div class="mt-6 text-center">
              <p class="text-ink-muted text-sm font-bold">{{ demos[activeDemo].title }}</p>
              <div class="mt-3 flex flex-wrap justify-center gap-2">
                <span
                  v-for="feature in demos[activeDemo].features"
                  :key="feature"
                  class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700"
                >
                  {{ feature }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- CTA -->
      <div class="mt-16 text-center">
        <h2 class="text-ink-strong text-2xl font-bold sm:text-3xl">
          آماده‌اید سوئیت را روی سایت خودتان ببینید؟
        </h2>
        <p class="text-ink-muted mx-auto mt-4 max-w-xl leading-7">
          در دموی اختصاصی، سوئیت را روی سایت واقعی شما پیاده‌سازی می‌کنیم.
        </p>
        <div class="mt-8 flex justify-center gap-3">
          <VButton href="/demo" size="lg" variant="gradient">
            درخواست دموی اختصاصی
            <ArrowUpRight class="size-4" />
          </VButton>
          <VButton href="/case-study" size="lg" variant="secondary">مشاهده مطالعات موردی</VButton>
        </div>
      </div>
    </section>
  </MarketingLayout>
</template>
