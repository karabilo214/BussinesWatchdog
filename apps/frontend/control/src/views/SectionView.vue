<script setup lang="ts">
defineProps<{ section: 'owner' | 'admin' }>();
</script>

<template>
  <section class="flex flex-col gap-2 rounded-xl border border-dashed border-border-strong bg-surface p-8">
    <h1 class="text-2xl font-semibold">{{ $t(`section.${section}_title`) }}</h1>
    <p class="max-w-2xl text-sm text-text-muted">{{ $t(`section.${section}_body`) }}</p>
  </section>
</template>
