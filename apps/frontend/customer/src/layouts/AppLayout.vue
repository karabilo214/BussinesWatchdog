<script setup lang="ts">
import { useRouter } from 'vue-router';
import { LocaleSwitch } from '@bw/i18n';
import { useSession } from '@/composables/useSession';

const router = useRouter();
const { session, signOut } = useSession();

async function leave(): Promise<void> {
  await signOut().catch(() => undefined);
  await router.push({ name: 'login' });
}
</script>

<template>
  <div class="flex min-h-full flex-col">
    <header class="sticky top-0 z-10 border-b border-border bg-surface">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-8 gap-y-3 px-4 py-3 sm:px-6">
        <RouterLink :to="{ name: 'overview' }" class="text-base font-semibold text-brand-800">{{ $t('app.name') }}</RouterLink>
        <nav :aria-label="$t('nav.main')" class="flex flex-1 flex-wrap gap-1">
          <RouterLink
            :to="{ name: 'overview' }"
            class="rounded-md px-3 py-1.5 text-sm font-medium text-text-muted hover:bg-surface-muted hover:text-text"
            active-class="bg-primary-soft !text-primary"
          >
            {{ $t('nav.overview') }}
          </RouterLink>
          <RouterLink
            :to="{ name: 'incidents' }"
            class="rounded-md px-3 py-1.5 text-sm font-medium text-text-muted hover:bg-surface-muted hover:text-text"
            :class="{ '!text-primary bg-primary-soft': $route.name === 'incident' }"
            active-class="bg-primary-soft !text-primary"
          >
            {{ $t('nav.incidents') }}
          </RouterLink>
        </nav>
        <div class="flex items-center gap-3">
          <span class="hidden text-sm text-text-muted sm:inline">{{ session?.user.name }}</span>
          <LocaleSwitch />
          <button
            type="button"
            class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft"
            @click="leave"
          >
            {{ $t('common.sign_out') }}
          </button>
        </div>
      </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
      <slot />
    </main>
  </div>
</template>
