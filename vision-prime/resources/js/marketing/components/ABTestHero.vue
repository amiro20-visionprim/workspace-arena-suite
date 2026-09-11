<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

interface Variant {
  id: string
  headline: string
  subheadline: string
  cta: string
}

const variants: Variant[] = [
  {
    id: 'control',
    headline: 'از داده سرچ کنسول تا اقدام قابل تأیید؛',
    subheadline: 'مرکز فرماندهی رشد SEO',
    cta: 'درخواست دموی اختصاصی',
  },
  {
    id: 'value-focus',
    headline: 'SEO خود را با داده، نه حدس، مدیریت کنید؛',
    subheadline: 'پلتفرم هوشمند عملیات SEO',
    cta: 'رایگان امتحان کنید',
  },
  {
    id: 'urgency',
    headline: 'رقبایتان هر روز جلوتر می‌روند؛',
    subheadline: 'زمان از دست رفته را با داده جبران کنید',
    cta: 'مشاوره رایگان',
  },
]

const activeVariant = ref(0)

onMounted(() => {
  // Randomly select variant (1 in 3)
  activeVariant.value = Math.floor(Math.random() * variants.length)
  
  // Track impression
  const variant = variants[activeVariant.value]
  if (variant) {
    // Store in localStorage for tracking
    const impressions = JSON.parse(localStorage.getItem('ab_impressions') || '{}')
    impressions[variant.id] = (impressions[variant.id] || 0) + 1
    localStorage.setItem('ab_impressions', JSON.stringify(impressions))
  }
})

const currentVariant = computed(() => variants[activeVariant.value])
</script>

<template>
  <div>
    <h1
      class="font-display text-ink-strong mt-5 max-w-3xl text-4xl leading-[1.55] font-bold sm:text-5xl sm:leading-[1.5]"
    >
      {{ currentVariant.headline }}
      <span class="text-gradient-brand">{{ currentVariant.subheadline }}</span>
    </h1>
  </div>
</template>
