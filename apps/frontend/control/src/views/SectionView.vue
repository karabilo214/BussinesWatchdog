<script setup lang="ts">
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import type { OwnerSection } from '@/router';

defineProps<{ section: OwnerSection }>();
</script>

<template>
  <OwnerLayout>
    <section class="flex max-w-3xl flex-col gap-2 rounded-xl border border-dashed border-border-strong bg-surface p-8">
      <h1 class="text-2xl font-semibold">{{ $t(`section.${section}.title`) }}</h1>
      <p class="text-sm text-text-muted">{{ $t(`section.${section}.body`) }}</p>
    </section>
  </OwnerLayout>
</template>
