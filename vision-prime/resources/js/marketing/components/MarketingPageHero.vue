<script setup lang="ts">
withDefaults(
  defineProps<{
    eyebrow?: string
    title: string
    description: string
  }>(),
  {
    eyebrow: 'VISION PRIME SUITE',
  },
)
</script>

<template>
  <section class="border-line bg-canvas relative overflow-hidden border-b">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
      <div class="bg-radial-fade bg-hero-grid absolute inset-0" />
      <div
        class="bg-brand-200/50 absolute -top-32 right-[-10%] size-[420px] rounded-full blur-3xl"
      />
      <div
        class="absolute top-20 left-[-12%] size-[380px] rounded-full bg-violet-200/40 blur-3xl"
      />
    </div>
    <div v-reveal class="relative mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-20 lg:px-10 lg:py-24">
      <p class="text-gradient-brand text-sm font-bold tracking-wide">{{ eyebrow }}</p>
      <h1 class="font-display text-display text-ink-strong mt-4 max-w-3xl font-bold sm:text-5xl">
        {{ title }}
      </h1>
      <p class="text-ink-muted mt-5 max-w-2xl text-base leading-8 sm:text-lg">{{ description }}</p>
      <div v-if="$slots.actions" class="mt-8 flex flex-wrap gap-3"><slot name="actions" /></div>
    </div>
  </section>
</template>
