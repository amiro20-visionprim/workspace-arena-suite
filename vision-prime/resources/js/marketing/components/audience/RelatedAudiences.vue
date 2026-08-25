<script setup lang="ts">
import { ArrowLeft } from '@lucide/vue'

import SectionHeading from '@/marketing/components/SectionHeading.vue'
import type { AudienceLink } from '@/types/audience'

withDefaults(
  defineProps<{
    links?: AudienceLink[]
  }>(),
  {
    links: () => [],
  },
)
</script>

<template>
  <section class="border-line bg-surface border-t">
    <div class="mx-auto max-w-7xl px-5 py-14 sm:px-8 lg:px-10 lg:py-16">
      <div v-reveal>
        <SectionHeading eyebrow="RELATED" title="این مسیر برای چه کسانی دیگر مناسب است؟" centered />
      </div>
      <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a
          v-for="(link, index) in links"
          :key="link.href"
          v-reveal="{ delay: index * 90 }"
          :href="link.href"
          class="group border-line bg-canvas hover:border-brand-200 hover:shadow-card rounded-2xl border p-5 transition-all duration-300 hover:-translate-y-1"
        >
          <h3 class="text-ink-strong flex items-center justify-between text-base font-bold">
            {{ link.title }}
            <ArrowLeft
              class="text-brand-700 size-4 transition-transform duration-300 group-hover:-translate-x-1"
              aria-hidden="true"
            />
          </h3>
          <p class="text-ink-muted mt-2 text-sm leading-6">{{ link.description }}</p>
        </a>
      </div>
    </div>
  </section>
</template>
