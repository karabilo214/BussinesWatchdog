<script setup lang="ts">
import { computed } from 'vue';
import type { Store } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import CoverageList from './CoverageList.vue';
import { storeHost } from './storeHost';

const props = defineProps<{ store: Store }>();
const { dateTime } = useFormat();

const host = computed(() => storeHost(props.store.base_url));
</script>

<template>
  <article class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5" :data-store-id="store.id">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex min-w-0 flex-col gap-0.5">
        <h2 class="truncate text-lg font-semibold">
          <RouterLink :to="{ name: 'store', params: { id: store.id } }" class="hover:text-primary hover:underline">{{ store.name }}</RouterLink>
        </h2>
        <p class="truncate text-sm text-text-muted">{{ host }} · {{ $t(`store_status.${store.status}`) }}</p>
      </div>
      <StatusBadge
        v-if="store.active_incident_count > 0"
        tone="crit"
        solid
        :label="$t('overview.active_incidents', { n: store.active_incident_count })"
      />
      <StatusBadge v-else tone="ok" :label="$t('overview.no_incidents')" />
    </header>

    <CoverageList :coverage="store.coverage" />

    <p class="border-t border-border pt-3 text-[13px] text-text-muted">
      {{ $t('overview.last_passed_check', { time: dateTime(store.last_successful_check_at) }) }}
    </p>
  </article>
</template>
