<script setup lang="ts">
import { LocaleSwitch } from '@bw/i18n';
</script>

<template>
  <div class="flex min-h-full flex-col px-4 py-8 sm:px-6">
    <div class="flex justify-end">
      <LocaleSwitch />
    </div>
    <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-8 py-10">
      <div class="flex flex-col gap-1">
        <span class="text-lg font-semibold text-brand-800">{{ $t('app.name') }}</span>
        <span class="text-sm text-text-muted">{{ $t('app.tagline') }}</span>
      </div>
      <slot />
    </main>
  </div>
</template>
