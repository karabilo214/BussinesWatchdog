<script setup lang="ts">
import { computed } from 'vue';
import type { Store } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import CoverageList from './CoverageList.vue';
import PauseDeviationNotice from './PauseDeviationNotice.vue';
import { storeHost } from './storeHost';

const props = defineProps<{ store: Store; canDelete?: boolean }>();
const emit = defineEmits<{ delete: [store: Store] }>();
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
      <RouterLink v-if="store.active_incident_count > 0" :to="{ name: 'incidents', query: { store: store.id } }" class="rounded-full focus-visible:outline-2">
        <StatusBadge tone="crit" solid :label="$t('overview.active_incidents', { n: store.active_incident_count })" />
      </RouterLink>
      <StatusBadge v-else-if="store.capacity_paused_at" tone="unknown" :label="$t('overview.paused_badge')" />
      <StatusBadge v-else tone="ok" :label="$t('overview.no_incidents')" />
    </header>

    <p v-if="store.capacity_paused_at" class="rounded-md border border-unknown-border bg-unknown-soft px-3 py-2 text-sm text-unknown" data-store-paused="capacity">
      {{ $t('overview.capacity_paused') }}
      <RouterLink :to="{ name: 'billing' }" class="font-medium underline">{{ $t('overview.capacity_choose') }}</RouterLink>
    </p>

    <PauseDeviationNotice v-if="!store.capacity_paused_at && store.last_capacity_pause" :pause="store.last_capacity_pause" />

    <CoverageList :coverage="store.coverage" />

    <p class="border-t border-border pt-3 text-[13px] text-text-muted">
      {{ $t('overview.last_passed_check', { time: dateTime(store.last_successful_check_at) }) }}
    </p>
    <button v-if="canDelete" type="button" class="self-start rounded-md border border-crit-border px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" data-action="delete-store" @click="emit('delete', store)">
      {{ $t('overview.delete_store.open') }}
    </button>
  </article>
</template>
