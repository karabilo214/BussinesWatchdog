<script setup lang="ts">
import { ref, watch } from 'vue';
import { ApiError } from '@bw/api-client';
import { getSalesExclusions, setSalesExclusion } from '@/api/salesExclusions';
import type { ManualExclusionKind, SalesExclusion, SalesExclusions, SalesMode } from '@/api/salesExclusions';
import ErrorNotice from './ErrorNotice.vue';

const props = defineProps<{ storeId: string; canManage: boolean }>();
const mode = ref<SalesMode>('live');
const data = ref<SalesExclusions | null>(null);
const date = ref('');
const kind = ref<ManualExclusionKind>('promotion');
const loading = ref(false);
const saving = ref(false);
const error = ref<unknown>(null);
const invalid = ref(false);
const saved = ref(false);
let generation = 0;

async function load(): Promise<void> {
  const current = ++generation;
  data.value = null;
  saved.value = false;
  invalid.value = false;
  loading.value = true;
  error.value = null;
  try {
    const response = await getSalesExclusions(props.storeId, mode.value);
    if (current !== generation) return;
    data.value = response;
    date.value = response.max_date;
  } catch (caught) {
    if (current === generation) error.value = caught;
  } finally {
    if (current === generation) loading.value = false;
  }
}

async function change(excluded: boolean, entry?: SalesExclusion): Promise<void> {
  if (!props.canManage || saving.value || loading.value || !data.value) return;
  const selectedKind = entry?.kind ?? kind.value;
  if (selectedKind !== 'promotion' && selectedKind !== 'holiday') return;
  const current = generation;
  saving.value = true;
  error.value = null;
  invalid.value = false;
  saved.value = false;
  try {
    const response = await setSalesExclusion(props.storeId, { date: entry?.date ?? date.value, kind: selectedKind, mode: mode.value, excluded });
    if (current !== generation) return;
    data.value = response;
    saved.value = true;
  } catch (caught) {
    if (current !== generation) return;
    if (caught instanceof ApiError && caught.status === 422) invalid.value = true;
    else error.value = caught;
  } finally {
    if (current === generation) saving.value = false;
  }
}

watch([() => props.storeId, mode], () => { saving.value = false; void load(); }, { immediate: true });
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="sales-exclusions-title" data-panel="sales-exclusions">
    <div>
      <h2 id="sales-exclusions-title" class="text-lg font-semibold">{{ $t('sales_exclusions.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-text-muted">{{ $t('sales_exclusions.intro') }}</p>
      <p class="mt-1 text-sm text-text-muted">{{ $t('sales_exclusions.preparing') }}</p>
    </div>
    <label class="flex max-w-xs flex-col gap-1.5 text-sm font-medium" for="sales-exclusions-mode">
      {{ $t('sales_exclusions.mode') }}
      <select id="sales-exclusions-mode" v-model="mode" :disabled="saving" class="rounded-md border border-border-strong bg-surface px-3 py-2">
        <option value="live">{{ $t('sales_exclusions.live') }}</option>
        <option value="test">{{ $t('sales_exclusions.test') }}</option>
      </select>
    </label>
    <p v-if="loading" role="status" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
    <template v-if="data">
      <p class="text-sm text-text-muted">{{ $t('sales_exclusions.range', { from: data.min_date, to: data.max_date, timezone: data.timezone }) }}</p>
      <form v-if="canManage" class="flex flex-wrap items-end gap-3" @submit.prevent="change(true)">
        <label for="sales-exclusions-date" class="flex flex-col gap-1.5 text-sm font-medium">
          {{ $t('sales_exclusions.date') }}
          <input id="sales-exclusions-date" v-model="date" type="date" required :min="data.min_date" :max="data.max_date" :disabled="saving" :aria-invalid="invalid" class="rounded-md border border-border-strong bg-surface px-3 py-2" />
        </label>
        <label for="sales-exclusions-kind" class="flex flex-col gap-1.5 text-sm font-medium">
          {{ $t('sales_exclusions.kind') }}
          <select id="sales-exclusions-kind" v-model="kind" :disabled="saving" class="rounded-md border border-border-strong bg-surface px-3 py-2">
            <option value="promotion">{{ $t('sales_exclusions.kinds.promotion') }}</option>
            <option value="holiday">{{ $t('sales_exclusions.kinds.holiday') }}</option>
          </select>
        </label>
        <button type="submit" :disabled="saving" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" data-action="add-sales-exclusion">{{ $t('sales_exclusions.add') }}</button>
      </form>
      <p v-if="invalid" role="alert" class="text-sm text-crit">{{ $t('sales_exclusions.invalid') }}</p>
      <p v-if="saved" role="status" class="text-sm text-ok">{{ $t('common.saved') }}</p>
      <p v-if="data.entries.length === 0" class="text-sm text-text-muted">{{ $t('sales_exclusions.empty') }}</p>
      <ul v-else class="flex flex-col divide-y divide-border text-sm">
        <li v-for="entry in data.entries" :key="`${entry.date}:${entry.kind}`" class="flex flex-wrap items-center justify-between gap-3 py-2">
          <span>{{ entry.date }} · {{ $t(`sales_exclusions.kinds.${entry.kind}`) }} · {{ entry.timezone }}<span v-if="!entry.editable" class="text-text-muted"> · {{ $t('sales_exclusions.automatic') }}</span></span>
          <button v-if="canManage && entry.editable" type="button" :disabled="saving" :aria-label="$t('sales_exclusions.remove_label', { date: entry.date, kind: $t(`sales_exclusions.kinds.${entry.kind}`) })" class="rounded-md px-3 py-1.5 text-primary hover:underline disabled:opacity-60" data-action="remove-sales-exclusion" @click="change(false, entry)">{{ $t('sales_exclusions.remove') }}</button>
        </li>
      </ul>
    </template>
    <ErrorNotice v-if="error" :error="error">
      <button v-if="!data" type="button" class="mt-2 text-primary hover:underline" @click="load">{{ $t('common.retry') }}</button>
    </ErrorNotice>
  </section>
</template>
