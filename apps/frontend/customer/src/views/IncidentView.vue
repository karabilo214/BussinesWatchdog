<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { getIncident } from '@/api/incidents';
import { getStore } from '@/api/stores';
import type { IncidentDetail, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import IncidentActions from '@/components/incidents/IncidentActions.vue';
import IncidentEvidence from '@/components/incidents/IncidentEvidence.vue';
import IncidentTimeline from '@/components/incidents/IncidentTimeline.vue';
import { SEVERITY_TONES, STATE_TONES } from '@/components/incidents/tones';
import { useIncidentText } from '@/components/incidents/useIncidentText';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const props = defineProps<{ id: string }>();
const { canManageStores, canHandleIncidents } = useRole();
const { dateTime } = useFormat();
const { title, advice, discrepancy, resolution } = useIncidentText();

const incident = ref<IncidentDetail | null>(null);
const store = ref<Store | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

const amount = computed(() => (incident.value ? discrepancy(incident.value) : null));

async function load(): Promise<void> {
  error.value = null;

  try {
    incident.value = await getIncident(props.id);

    if (store.value?.id !== incident.value.store_id) {
      store.value = await getStore(incident.value.store_id).catch(() => null);
    }
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.id,
  () => {
    loading.value = true;
    incident.value = null;
    void load();
  },
  { immediate: true },
);
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <RouterLink :to="{ name: 'incidents' }" class="text-sm text-primary hover:underline">← {{ $t('nav.incidents') }}</RouterLink>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <ErrorNotice v-else-if="error && !incident" :error="error">
        <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load">
          {{ $t('common.retry') }}
        </button>
      </ErrorNotice>

      <template v-else-if="incident">
        <header class="flex flex-col gap-3">
          <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :tone="SEVERITY_TONES[incident.severity]" :label="$t(`incident.severity.${incident.severity}`)" solid />
            <StatusBadge :tone="STATE_TONES[incident.state]" :label="$t(`incident.state.${incident.state}`)" />
            <span class="text-sm text-text-muted">{{ $t(`incident.family.${incident.family}`) }}</span>
          </div>
          <h1 class="text-2xl font-semibold">{{ title(incident.title_code) }}</h1>
          <p v-if="store" class="text-sm text-text-muted">
            <RouterLink :to="{ name: 'store', params: { id: store.id } }" class="hover:text-primary hover:underline">{{ store.name }}</RouterLink>
          </p>
        </header>

        <section class="grid gap-4 rounded-xl border border-border bg-surface p-5 sm:grid-cols-2 lg:grid-cols-4" aria-label="facts" data-panel="incident-facts">
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('incident.fields.last_good') }}</span>
            <span class="text-sm font-medium">{{ incident.last_good_at ? dateTime(incident.last_good_at) : $t('incident.fields.unknown') }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('incident.fields.first_bad') }}</span>
            <span class="text-sm font-medium">{{ incident.first_bad_at ? dateTime(incident.first_bad_at) : $t('incident.fields.unknown') }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('incident.fields.last_seen') }}</span>
            <span class="text-sm font-medium">{{ dateTime(incident.last_seen_at) }}</span>
          </div>
          <div v-if="incident.family === 'money'" class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('incident.fields.discrepancy') }}</span>
            <span v-if="amount" class="font-mono text-sm font-medium" data-amount>{{ amount }}</span>
            <span v-else class="text-sm font-medium">{{ $t('incident.fields.no_amount') }}</span>
          </div>
          <p v-if="incident.last_good_at && incident.first_bad_at" class="text-[13px] text-text-muted sm:col-span-2 lg:col-span-4">
            {{ $t('incident.fields.window_note') }}
          </p>
          <p v-if="incident.state === 'resolved'" class="text-sm sm:col-span-2 lg:col-span-4" data-resolution>
            {{ $t('incident.fields.resolved', { time: dateTime(incident.resolved_at) }) }}
            <template v-if="resolution(incident.resolution_reason)"> — {{ resolution(incident.resolution_reason) }}</template>
          </p>
        </section>

        <section class="flex flex-col gap-1 rounded-xl border border-note-border bg-note-soft p-5" aria-labelledby="advice-title">
          <h2 id="advice-title" class="text-base font-semibold">{{ $t('incident.advice_title') }}</h2>
          <p class="text-sm">{{ advice(incident.title_code) }}</p>
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
          <div class="flex min-w-0 flex-col gap-6">
            <IncidentEvidence :incident="incident" />
            <IncidentTimeline :incident-id="incident.id" :activity="incident.activity" :can-comment="canHandleIncidents" @commented="load" />
          </div>
          <div class="flex flex-col gap-6">
            <IncidentActions :incident="incident" :can-handle="canHandleIncidents" :can-manage="canManageStores" @changed="load" />
          </div>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
