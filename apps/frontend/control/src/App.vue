<script setup lang="ts">
import { LocaleSwitch } from '@bw/i18n';
</script>

<template>
  <div class="flex min-h-full flex-col">
    <header class="border-b border-border bg-brand-950 text-text-inverse">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-8 gap-y-3 px-4 py-3 sm:px-6">
        <span class="font-semibold">{{ $t('app.name') }}</span>
        <nav :aria-label="$t('nav.main')" class="flex flex-1 flex-wrap gap-1">
          <RouterLink :to="{ name: 'owner' }" class="rounded-md px-3 py-1.5 text-sm font-medium text-brand-200 hover:text-white" active-class="bg-brand-800 !text-white">
            {{ $t('nav.owner') }}
          </RouterLink>
          <RouterLink :to="{ name: 'admin' }" class="rounded-md px-3 py-1.5 text-sm font-medium text-brand-200 hover:text-white" active-class="bg-brand-800 !text-white">
            {{ $t('nav.admin') }}
          </RouterLink>
        </nav>
        <LocaleSwitch />
      </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
      <RouterView />
    </main>
  </div>
</template>
