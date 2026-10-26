<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AttentionCode } from '@/api/types';
import { useNavBadgeState } from '@/composables/useNavBadges';
import { useRole } from '@/composables/useRole';
import { SOLID_TONE_CLASSES } from '@bw/ui';

const { role } = useRole();
const { badges } = useNavBadgeState();
const { t } = useI18n();

/** Which settings tab resolves which counter (Step 264). */
const TAB_OF: Partial<Record<AttentionCode, string>> = { subscription_past_due: 'billing', support_requests_pending: 'support', owner_mfa_off: 'profile' };

const tabBadges = computed(() => {
  const result: Record<string, { n: number; level: 'crit' | 'warn' | 'note'; text: string }> = {};

  for (const part of badges.value.settings?.parts ?? []) {
    const tab = part.code ? TAB_OF[part.code] : undefined;
    if (tab) result[tab] = { n: part.n, level: part.level ?? 'note', text: t(part.key, { n: part.n }) };
  }

  return result;
});
</script>

<template>
  <nav :aria-label="$t('settings.title')" class="flex flex-wrap gap-1 rounded-lg border border-border bg-surface p-1 self-start">
    <RouterLink
      v-for="tab in ['profile', 'team', 'billing', 'organization', 'support']"
      v-show="tab !== 'support' || role === 'owner'"
      :key="tab"
      :to="{ name: tab }"
      class="rounded-md px-3 py-1.5 text-sm font-medium text-text-muted hover:bg-surface-muted hover:text-text"
      exact-active-class="bg-primary-soft !text-primary"
    >
      {{ $t(`settings.tab.${tab}`) }}<span
        v-if="tabBadges[tab]"
        class="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full border px-1.5 text-xs font-semibold tabular-nums"
        :class="SOLID_TONE_CLASSES[tabBadges[tab].level]"
        :title="tabBadges[tab].text"
        :aria-label="tabBadges[tab].text"
        :data-tab-badge="tab"
      >{{ tabBadges[tab].n }}</span>
    </RouterLink>
  </nav>
</template>
