<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LocaleSwitch } from '@bw/i18n';
import { SOLID_TONE_CLASSES, StatusBadge } from '@bw/ui';
import { useI18n } from 'vue-i18n';
import { useOwnerBadges } from '@/composables/useOwnerBadges';
import { useStaff } from '@/composables/useStaff';
import { allowedSections } from '@/router';

const route = useRoute();
const router = useRouter();
const { me, signOut } = useStaff();
const menuOpen = ref(false);
const sections = computed(() => allowedSections(me.value?.capabilities ?? []));
const { t } = useI18n();
const { badges } = useOwnerBadges(sections);

function badgeHint(section: string): string {
  const badge = badges.value[section];
  return badge ? t(badge.hint.key, { n: badge.hint.n }) : '';
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
  <div class="flex min-h-screen flex-col">
    <header class="border-b border-border bg-brand-950 text-text-inverse">
      <div class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3 sm:px-6">
        <RouterLink :to="{ name: 'owner-overview' }" class="flex items-center gap-2 font-semibold"><img src="/favicon.png" alt="" width="28" height="28" />{{ $t('app.name') }}</RouterLink>
        <StatusBadge v-if="me" :tone="me.billing_mode === 'live' ? 'crit' : 'warn'" solid :label="$t(`mode.${me.billing_mode}`)" data-mode />
        <button
          type="button"
          class="ml-auto rounded-md border border-brand-700 px-3 py-1.5 text-sm font-medium text-brand-200 md:hidden"
          :aria-expanded="menuOpen"
          aria-controls="owner-menu"
          @click="menuOpen = !menuOpen"
        >
          {{ $t('nav.menu') }}
        </button>
        <div class="hidden items-center gap-3 md:ml-auto md:flex">
          <span v-if="me" class="text-sm text-brand-200" data-staff>{{ me.name }} · {{ $t(`role.${me.role}`) }}</span>
          <LocaleSwitch />
          <button type="button" class="rounded-md border border-brand-700 px-3 py-1.5 text-sm font-medium text-brand-200 hover:text-white" @click="leave">
            {{ $t('auth.sign_out') }}
          </button>
        </div>
      </div>
    </header>

    <div class="flex flex-1 flex-col md:flex-row">
      <nav
        id="owner-menu"
        :aria-label="$t('nav.main')"
        class="border-b border-border bg-surface px-3 py-3 md:block md:w-56 md:shrink-0 md:border-r md:border-b-0"
        :class="menuOpen ? 'block' : 'hidden'"
      >
        <ul class="flex flex-col gap-1">
          <li v-for="section in sections" :key="section">
            <RouterLink
              :to="{ name: `owner-${section}` }"
              class="block rounded-md px-3 py-2 text-sm font-medium text-text-muted hover:bg-surface-muted hover:text-text"
              :class="(section === 'plans' && (String($route.name).startsWith('owner-plan') || String($route.name).startsWith('owner-discount'))) || (section === 'tenants' && String($route.name).startsWith('owner-tenant')) || (section === 'site' && ['owner-page', 'owner-blocks', 'owner-block'].includes(String($route.name))) || (section === 'contacts' && $route.name === 'owner-contact') ? 'bg-primary-soft !text-primary' : ''"
              exact-active-class="bg-primary-soft !text-primary"
            >
              <span class="flex items-center justify-between gap-2">
                <span>{{ $t(`section.${section}.title`) }}</span>
                <span
                  v-if="badges[section]"
                  class="inline-flex min-w-5 items-center justify-center rounded-full border px-1.5 text-xs font-semibold tabular-nums"
                  :class="SOLID_TONE_CLASSES[badges[section].level]"
                  :title="badgeHint(section)"
                  :aria-label="badgeHint(section)"
                  :data-nav-badge="section"
                  :data-level="badges[section].level"
                >{{ badges[section].text }}</span>
              </span>
            </RouterLink>
          </li>
        </ul>
        <div class="mt-4 flex flex-col gap-3 border-t border-border pt-4 md:hidden">
          <span v-if="me" class="text-sm text-text-muted">{{ me.name }} · {{ $t(`role.${me.role}`) }}</span>
          <LocaleSwitch />
          <button type="button" class="self-start rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-primary" @click="leave">{{ $t('auth.sign_out') }}</button>
        </div>
      </nav>
      <main class="w-full flex-1 px-4 py-8 sm:px-8">
        <slot />
      </main>
    </div>
  </div>
</template>
