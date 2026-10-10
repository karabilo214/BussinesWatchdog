<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LocaleSwitch } from '@bw/i18n';
import { resendEmailVerification } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useSession } from '@/composables/useSession';

const router = useRouter();
const route = useRoute();
const { session, signOut, load } = useSession();
const menuOpen = ref(false);
const verifyState = ref<'idle' | 'sent'>('idle');
const verifyError = ref<unknown>(null);
const verifiedNotice = ref<'1' | '0' | null>(null);

if (route.query.email_verified === '1' || route.query.email_verified === '0') {
  verifiedNotice.value = route.query.email_verified;
  const query = { ...route.query };
  delete query.email_verified;
  void router.replace({ query });

  if (verifiedNotice.value === '1') {
    void load(true);
  }
}

async function resendVerification(): Promise<void> {
  verifyError.value = null;

  try {
    await resendEmailVerification();
    verifyState.value = 'sent';
  } catch (caught) {
    verifyError.value = caught;
  }
}

/** Main sections; `also` lists child pages that keep the section highlighted. */
const NAV = [
  { name: 'overview', label: 'nav.overview', also: ['store', 'store-create'] },
  { name: 'incidents', label: 'nav.incidents', also: ['incident'] },
  { name: 'reconciliation', label: 'nav.reconciliation', also: ['order'] },
  { name: 'checks', label: 'nav.checks', also: ['check-run'] },
  { name: 'notifications', label: 'nav.notifications', also: [] },
] as const;

function active(item: (typeof NAV)[number]): boolean {
  return route.name === item.name || (item.also as readonly string[]).includes(String(route.name));
}

async function leave(): Promise<void> {
  await signOut().catch(() => undefined);
  await router.push({ name: 'login' });
}

watch(() => route.fullPath, () => {
  menuOpen.value = false;
});
</script>

<template>
  <div class="flex min-h-full flex-col">
    <header class="sticky top-0 z-10 border-b border-border bg-surface">
      <div class="mx-auto flex max-w-6xl items-center gap-x-8 gap-y-3 px-4 py-3 sm:px-6">
        <RouterLink :to="{ name: 'overview' }" class="text-base font-semibold whitespace-nowrap text-brand-800">{{ $t('app.name') }}</RouterLink>

        <nav :aria-label="$t('nav.main')" class="hidden flex-1 flex-wrap gap-1 md:flex">
          <RouterLink
            v-for="item in NAV"
            :key="item.name"
            :to="{ name: item.name }"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="active(item) ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
            :aria-current="active(item) ? 'page' : undefined"
          >
            {{ $t(item.label) }}
          </RouterLink>
        </nav>

        <div class="ml-auto hidden items-center gap-3 md:flex">
          <RouterLink :to="{ name: 'profile' }" class="max-w-[12rem] truncate text-sm text-text-muted hover:text-primary hover:underline" :title="$t('nav.settings')">{{ session?.user.name }}</RouterLink>
          <LocaleSwitch />
          <button
            type="button"
            class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft"
            @click="leave"
          >
            {{ $t('common.sign_out') }}
          </button>
        </div>

        <button
          type="button"
          class="ml-auto flex items-center gap-2 rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary md:hidden"
          :aria-expanded="menuOpen"
          aria-controls="mobile-menu"
          @click="menuOpen = !menuOpen"
        >
          <svg viewBox="0 0 20 20" class="size-4" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path v-if="menuOpen" d="M5 5l10 10M15 5L5 15" />
            <path v-else d="M3 5h14M3 10h14M3 15h14" />
          </svg>
          {{ $t('nav.menu') }}
        </button>
      </div>

      <div v-if="menuOpen" id="mobile-menu" class="border-t border-border px-4 pt-2 pb-4 md:hidden">
        <nav :aria-label="$t('nav.main')" class="flex flex-col gap-1">
          <RouterLink
            v-for="item in NAV"
            :key="item.name"
            :to="{ name: item.name }"
            class="rounded-md px-3 py-2.5 text-base font-medium"
            :class="active(item) ? 'bg-primary-soft text-primary' : 'text-text hover:bg-surface-muted'"
            :aria-current="active(item) ? 'page' : undefined"
          >
            {{ $t(item.label) }}
          </RouterLink>
        </nav>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
          <RouterLink :to="{ name: 'profile' }" class="text-sm font-medium text-primary hover:underline">{{ $t('nav.settings') }} · {{ session?.user.name }}</RouterLink>
          <div class="flex items-center gap-3">
            <LocaleSwitch id="locale-switch-mobile" />
            <button
              type="button"
              class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft"
              @click="leave"
            >
              {{ $t('common.sign_out') }}
            </button>
          </div>
        </div>
      </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
      <p v-if="verifiedNotice" role="status" class="mb-6 rounded-md border px-3 py-2 text-sm" :class="verifiedNotice === '1' ? 'border-ok-border bg-ok-soft text-ok' : 'border-warn-border bg-warn-soft'" data-verified-notice>
        {{ verifiedNotice === '1' ? $t('account.verify.done') : $t('account.verify.failed') }}
      </p>
      <div v-else-if="session && !session.user.email_verified" class="mb-6 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm" data-verify-banner>
        <span class="flex-1">{{ $t('account.verify.banner', { email: session.user.email }) }}</span>
        <button v-if="verifyState === 'idle'" type="button" class="font-medium text-primary hover:underline" @click="resendVerification">{{ $t('account.verify.resend') }}</button>
        <span v-else class="text-ok" role="status">{{ $t('account.verify.sent') }}</span>
        <ErrorNotice v-if="verifyError" :error="verifyError" class="w-full" />
      </div>
      <slot />
    </main>
  </div>
</template>
