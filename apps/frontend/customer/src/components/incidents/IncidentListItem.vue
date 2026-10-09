<script setup lang="ts">
import { computed } from 'vue';
import type { Incident } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import { SEVERITY_TONES, STATE_TONES } from './tones';
import { useIncidentText } from './useIncidentText';

const props = defineProps<{ incident: Incident; storeName: string | null }>();
const { dateTime } = useFormat();
const { title, discrepancy } = useIncidentText();

const amount = computed(() => discrepancy(props.incident));
</script>

<template>
  <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6" :data-incident-id="incident.id">
    <div class="flex min-w-0 flex-col gap-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <StatusBadge :tone="SEVERITY_TONES[incident.severity]" :label="$t(`incident.severity.${incident.severity}`)" />
        <StatusBadge :tone="STATE_TONES[incident.state]" :label="$t(`incident.state.${incident.state}`)" />
      </div>
      <RouterLink :to="{ name: 'incident', params: { id: incident.id } }" class="font-medium hover:text-primary hover:underline">
        {{ title(incident.title_code) }}
      </RouterLink>
      <p class="text-[13px] text-text-muted">
        <template v-if="storeName">{{ storeName }} · </template>{{ $t(`incident.family.${incident.family}`) }}
      </p>
    </div>
    <div class="flex shrink-0 flex-col gap-1 text-[13px] text-text-muted sm:items-end">
      <span v-if="amount" class="font-mono text-sm font-medium text-text" data-amount>{{ $t('incident.discrepancy_short', { amount }) }}</span>
      <span>{{ $t('incident.last_seen', { time: dateTime(incident.last_seen_at) }) }}</span>
      <span v-if="incident.resolved_at">{{ $t('incident.resolved_at', { time: dateTime(incident.resolved_at) }) }}</span>
    </div>
  </li>
</template>
