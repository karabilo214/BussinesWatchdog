<script setup lang="ts">
import { reactive } from 'vue';
import { LOCALES, LOCALE_NAMES } from '@bw/i18n';
import type { IncidentSeverity, NotificationPreferences, Store } from '@/api/types';
import { timezones } from '@/components/storeOptions';

const props = defineProps<{ idPrefix: string; preferences: NotificationPreferences; stores: Store[]; isOwner: boolean }>();
const SEVERITIES: IncidentSeverity[] = ['info', 'warning', 'critical'];

const form = reactive({
  min_severity: props.preferences.min_severity,
  locale: props.preferences.locale,
  timezone: props.preferences.timezone,
  quiet: props.preferences.quiet_hours !== null,
  quiet_start: props.preferences.quiet_hours?.start ?? '22:00',
  quiet_end: props.preferences.quiet_hours?.end ?? '07:00',
  critical_bypasses_quiet_hours: props.preferences.critical_bypasses_quiet_hours,
  notify_recovery: props.preferences.notify_recovery,
  all_stores: props.preferences.store_ids === null,
  store_ids: [...(props.preferences.store_ids ?? [])],
});
const zoneOptions = timezones(props.preferences.timezone);

/** Only fields the user may change; the critical bypass is sent only by an owner (the API rejects it otherwise). */
function value(): Partial<NotificationPreferences> {
  const result: Partial<NotificationPreferences> = {
    min_severity: form.min_severity,
    locale: form.locale,
    timezone: form.timezone,
    quiet_hours: form.quiet ? { start: form.quiet_start, end: form.quiet_end } : null,
    notify_recovery: form.notify_recovery,
    store_ids: form.all_stores ? null : form.store_ids,
  };

  if (props.isOwner) {
    result.critical_bypasses_quiet_hours = form.quiet && form.critical_bypasses_quiet_hours;
  }

  return result;
}

defineExpose({ value, valid: () => form.all_stores || form.store_ids.length > 0 });
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="grid gap-4 sm:grid-cols-3">
      <label class="flex flex-col gap-1.5 text-sm font-medium">
        {{ $t('notifications.prefs.min_severity') }}
        <select :id="`${idPrefix}-severity`" v-model="form.min_severity" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal">
          <option v-for="severity in SEVERITIES" :key="severity" :value="severity">{{ $t(`notifications.prefs.severity.${severity}`) }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1.5 text-sm font-medium">
        {{ $t('notifications.prefs.locale') }}
        <select v-model="form.locale" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal">
          <option v-for="locale in LOCALES" :key="locale" :value="locale">{{ LOCALE_NAMES[locale] }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1.5 text-sm font-medium">
        {{ $t('notifications.prefs.timezone') }}
        <select v-model="form.timezone" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-normal">
          <option v-for="zone in zoneOptions" :key="zone" :value="zone">{{ zone }}</option>
        </select>
      </label>
    </div>

    <fieldset class="flex flex-col gap-2 rounded-lg border border-border p-3">
      <legend class="px-1 text-sm font-medium">{{ $t('notifications.prefs.quiet_title') }}</legend>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.quiet" type="checkbox" class="size-4 accent-primary" />
        {{ $t('notifications.prefs.quiet_enable') }}
      </label>
      <div v-if="form.quiet" class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-sm">
          {{ $t('notifications.prefs.quiet_from') }}
          <input v-model="form.quiet_start" type="time" class="rounded-md border border-border-strong bg-surface px-3 py-1.5" />
        </label>
        <label class="flex flex-col gap-1 text-sm">
          {{ $t('notifications.prefs.quiet_to') }}
          <input v-model="form.quiet_end" type="time" class="rounded-md border border-border-strong bg-surface px-3 py-1.5" />
        </label>
      </div>
      <label v-if="form.quiet" class="flex items-start gap-2 text-sm" :class="{ 'text-text-muted': !isOwner }">
        <input v-model="form.critical_bypasses_quiet_hours" type="checkbox" class="mt-0.5 size-4 accent-primary" :disabled="!isOwner" />
        <span>
          {{ $t('notifications.prefs.critical_bypass') }}
          <span v-if="!isOwner" class="block text-[13px]">{{ $t('notifications.prefs.owner_only') }}</span>
        </span>
      </label>
      <p class="text-[13px] text-text-muted">{{ $t('notifications.prefs.quiet_note') }}</p>
    </fieldset>

    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.notify_recovery" type="checkbox" class="size-4 accent-primary" />
      {{ $t('notifications.prefs.notify_recovery') }}
    </label>

    <fieldset class="flex flex-col gap-2 rounded-lg border border-border p-3">
      <legend class="px-1 text-sm font-medium">{{ $t('notifications.prefs.stores') }}</legend>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.all_stores" type="checkbox" class="size-4 accent-primary" />
        {{ $t('notifications.prefs.all_stores') }}
      </label>
      <div v-if="!form.all_stores" class="flex flex-col gap-1 pl-6">
        <label v-for="store in stores" :key="store.id" class="flex items-center gap-2 text-sm">
          <input v-model="form.store_ids" type="checkbox" :value="store.id" class="size-4 accent-primary" />
          {{ store.name }}
        </label>
        <p v-if="form.store_ids.length === 0" class="text-[13px] text-crit">{{ $t('notifications.prefs.pick_store') }}</p>
      </div>
    </fieldset>
  </div>
</template>
