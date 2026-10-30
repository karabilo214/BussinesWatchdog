<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError } from '@bw/api-client';
import { SALES_CALENDAR_REGIONS, setSalesCalendar } from '@/api/salesExclusions';
import type { CalendarHoliday, CalendarState, LocalHoliday, SalesCalendar, SalesCalendarRegion } from '@/api/salesExclusions';
import ErrorNotice from './ErrorNotice.vue';

const props = defineProps<{ storeId: string; configuration: SalesCalendar; holidays: CalendarHoliday[]; state: CalendarState; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const region = ref<SalesCalendarRegion | ''>('');
const flags = ref<LocalHoliday[]>([]);
const saving = ref(false);
const conflict = ref(false);
const invalid = ref(false);
const saved = ref(false);
const error = ref<unknown>(null);
let generation = 0;
const options = computed<LocalHoliday[]>(() => region.value === 'DE-BY' ? ['assumption', 'augsburg_peace'] : (region.value === 'DE-SN' || region.value === 'DE-TH' ? ['corpus_christi'] : []));
const dirty = computed(() => (region.value || null) !== props.configuration.region || [...flags.value].sort().join(',') !== [...props.configuration.local_holidays].sort().join(','));
watch(region, () => { flags.value = []; saved.value = false; }, { flush: 'sync' });
watch([() => props.storeId, () => props.configuration], () => {
  generation++;
  region.value = props.configuration.region ?? '';
  flags.value = [...props.configuration.local_holidays];
  saving.value = false;
  conflict.value = false;
  invalid.value = false;
  error.value = null;
}, { immediate: true });
onBeforeUnmount(() => { generation++; });

async function save(): Promise<void> {
  if (!props.canManage || saving.value || !dirty.value) return;
  const current = generation;
  saving.value = true;
  conflict.value = false;
  invalid.value = false;
  saved.value = false;
  error.value = null;
  try {
    await setSalesCalendar(props.storeId, props.configuration, region.value || null, [...flags.value]);
    if (current !== generation) return;
    saved.value = true;
    emit('changed');
  } catch (caught) {
    if (current !== generation) return;
    if (caught instanceof ApiError && caught.status === 409) conflict.value = true;
    else if (caught instanceof ApiError && caught.status === 422) invalid.value = true;
    else error.value = caught;
  } finally {
    if (current === generation) saving.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3 rounded-lg border border-border p-4" data-panel="sales-calendar">
    <h3 class="font-semibold">{{ $t('sales_calendar.title') }}</h3>
    <p class="max-w-3xl text-sm text-text-muted">{{ $t('sales_calendar.intro') }}</p>
    <p class="text-sm text-text-muted">{{ $t(`sales_calendar.states.${state}`) }}</p>
    <form v-if="canManage" class="flex flex-col items-start gap-3" @submit.prevent="save">
      <label for="sales-calendar-region" class="flex w-full max-w-sm flex-col gap-1.5 text-sm font-medium">
        {{ $t('sales_calendar.region') }}
        <select id="sales-calendar-region" v-model="region" :disabled="saving" class="w-full rounded-md border border-border-strong bg-surface px-3 py-2">
          <option value="">{{ $t('sales_calendar.unconfigured') }}</option>
          <option v-for="option in SALES_CALENDAR_REGIONS" :key="option" :value="option">{{ $t(`sales_calendar.regions.${option}`) }}</option>
        </select>
      </label>
      <fieldset v-if="options.length" class="flex flex-col gap-2 text-sm">
        <legend class="mb-2 font-medium">{{ $t('sales_calendar.local_title') }}</legend>
        <p class="mb-1 text-text-muted">{{ $t('sales_calendar.local_hint') }}</p>
        <label v-for="flag in options" :key="flag" class="flex items-start gap-2">
          <input v-model="flags" type="checkbox" :value="flag" :disabled="saving" class="mt-1" :data-local-holiday="flag" />
          <span>{{ $t(`sales_calendar.holidays.${flag}`) }}</span>
        </label>
      </fieldset>
      <button type="submit" :disabled="saving || !dirty || conflict" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" data-action="save-sales-calendar">{{ $t('common.save') }}</button>
    </form>
    <p v-else class="text-sm">{{ configuration.region ? $t(`sales_calendar.regions.${configuration.region}`) : $t('sales_calendar.unconfigured') }}<template v-for="flag in configuration.local_holidays" :key="flag"> · {{ $t(`sales_calendar.holidays.${flag}`) }}</template></p>
    <p class="text-sm text-text-muted">{{ $t('sales_calendar.years', { years: configuration.supported_years.join(', ') }) }}</p>
    <p v-if="saved" role="status" class="text-sm text-ok">{{ $t('common.saved') }}</p>
    <p v-if="invalid" role="alert" class="text-sm text-crit">{{ $t('sales_calendar.invalid') }}</p>
    <div v-if="conflict" role="alert" class="text-sm text-warn">
      <p>{{ $t('sales_calendar.conflict') }}</p>
      <button type="button" class="mt-2 text-primary hover:underline" data-action="reload-sales-calendar" @click="emit('changed')">{{ $t('sales_calendar.reload') }}</button>
    </div>
    <ErrorNotice v-if="error" :error="error" />
    <div v-if="holidays.length" class="flex flex-col gap-2 text-sm">
      <p class="font-medium">{{ $t('sales_calendar.dates') }}</p>
      <ul class="flex flex-col gap-1">
        <li v-for="holiday in holidays" :key="holiday.date">{{ holiday.date }} · {{ holiday.codes.map((code) => $t(`sales_calendar.holidays.${code}`)).join(', ') }}</li>
      </ul>
      <p class="text-text-muted">{{ $t('sales_calendar.automatic') }}</p>
    </div>
  </div>
</template>
