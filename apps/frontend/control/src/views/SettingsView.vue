<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { StatusBadge } from '@bw/ui';
import { cancelMaintenance, fetchPlatformSettings, scheduleMaintenance, type PlatformSettingsView, type SecretState, type SiteLocale } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { SITE_LOCALES } from '@/components/site';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const { dateTime } = useFormat();
const { can } = useStaff();
const allowed = computed(() => can('settings.manage'));
const settings = ref<PlatformSettingsView | null>(null);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref(false);
const notice = ref<string | null>(null);
const form = reactive({ open: false, starts: '', ends: '', messages: { ru: '', en: '', de: '' } as Record<SiteLocale, string>, reason: '', code: '' });
const cancel = reactive({ open: false, reason: '', code: '' });

const formReady = computed(() => form.starts !== '' && form.ends !== '' && SITE_LOCALES.every((code) => form.messages[code].trim().length >= 3) && form.reason.trim().length >= 3 && form.code.trim().length >= 6);

function secret(state: SecretState): string {
  return state.configured ? (state.fingerprint ?? '') : '';
}

async function load(): Promise<void> {
  if (!allowed.value) return;

  try {
    settings.value = await fetchPlatformSettings();
  } catch (caught) {
    error.value = caught;
  }
}

async function run(task: () => Promise<PlatformSettingsView>, message: string): Promise<void> {
  busy.value = true;
  actionError.value = null;
  notice.value = null;

  try {
    settings.value = await task();
    notice.value = message;
    form.open = false;
    cancel.open = false;
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

async function schedule(): Promise<void> {
  if (!formReady.value || busy.value) return;
  await run(() => scheduleMaintenance({ starts_at: new Date(form.starts).toISOString(), ends_at: new Date(form.ends).toISOString(), messages: { ...form.messages }, reason: form.reason.trim(), confirmation_code: form.code.trim() }), 'settings.maintenance.scheduled');
  form.code = '';
}

async function cancelCurrent(): Promise<void> {
  if (cancel.reason.trim().length < 3 || cancel.code.trim().length < 6 || busy.value) return;
  await run(() => cancelMaintenance({ reason: cancel.reason.trim(), confirmation_code: cancel.code.trim() }), 'settings.maintenance.cancelled');
  cancel.code = '';
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.settings.title') }}</h1>
        <p class="text-sm text-text-muted">{{ allowed ? $t('settings.hint') : $t('settings.forbidden') }}</p>
      </div>
      <ErrorNotice v-if="error" :error="error" />
      <p v-if="notice" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-notice>{{ $t(notice) }}</p>
      <ErrorNotice v-if="actionError" :error="actionError" />

      <template v-if="settings">
        <section class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 text-sm" data-section="maintenance">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
              <h2 class="text-lg font-semibold">{{ $t('settings.maintenance.title') }}</h2>
              <p class="text-text-muted">{{ $t('settings.maintenance.hint', { hours: settings.maintenance.limits.max_hours, days: settings.maintenance.limits.max_days_ahead }) }}</p>
            </div>
            <button v-if="!form.open" type="button" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover" data-action="plan" @click="form.open = true; notice = null">{{ $t('settings.maintenance.plan') }}</button>
          </div>

          <div v-if="settings.maintenance.current" class="flex flex-col gap-1 rounded-md border border-warn-border bg-warn-soft p-3" data-current>
            <p class="font-medium text-warn">{{ $t('settings.maintenance.current', { from: dateTime(settings.maintenance.current.starts_at), to: dateTime(settings.maintenance.current.ends_at), version: settings.maintenance.current.version }) }}</p>
            <p v-for="code in SITE_LOCALES" :key="code"><span class="font-mono uppercase text-text-muted">{{ code }}</span> {{ settings.maintenance.current.messages[code] }}</p>
            <button v-if="!cancel.open" type="button" class="self-start rounded-md border border-crit-border px-3 py-1.5 font-medium text-crit hover:bg-crit-soft" data-action="cancel" @click="cancel.open = true">{{ $t('settings.maintenance.cancel') }}</button>
            <form v-if="cancel.open" class="flex flex-wrap items-end gap-2" data-form="cancel" @submit.prevent="cancelCurrent">
              <label class="flex min-w-64 flex-1 flex-col gap-1.5"><span class="font-medium">{{ $t('tenant.actions.reason') }}</span><input v-model="cancel.reason" maxlength="500" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="cancel-reason" /></label>
              <label class="flex w-40 flex-col gap-1.5"><span class="font-medium">{{ $t('plans.code_confirm') }}</span><input v-model="cancel.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono" data-field="cancel-code" /></label>
              <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse disabled:opacity-60" :disabled="busy" data-action="confirm-cancel">{{ $t('settings.maintenance.cancel') }}</button>
            </form>
          </div>
          <p v-else class="text-text-muted" data-none>{{ $t('settings.maintenance.none') }}</p>

          <form v-if="form.open" class="flex flex-col gap-3 rounded-md border border-border p-4" data-form="maintenance" @submit.prevent="schedule">
            <div class="flex flex-wrap gap-3">
              <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('settings.maintenance.starts') }}</span><input v-model="form.starts" type="datetime-local" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="starts" /></label>
              <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('settings.maintenance.ends') }}</span><input v-model="form.ends" type="datetime-local" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="ends" /></label>
            </div>
            <label v-for="code in SITE_LOCALES" :key="code" class="flex flex-col gap-1.5">
              <span class="font-medium">{{ $t('settings.maintenance.message', { locale: code.toUpperCase() }) }}</span>
              <input v-model="form.messages[code]" maxlength="300" class="rounded-md border border-border-strong bg-surface px-3 py-2" :data-field="`message-${code}`" />
            </label>
            <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('tenant.actions.reason') }}</span><input v-model="form.reason" maxlength="500" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" /></label>
            <label class="flex max-w-xs flex-col gap-1.5"><span class="font-medium">{{ $t('plans.code_confirm') }}</span><input v-model="form.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="code" /></label>
            <div class="flex flex-wrap gap-2">
              <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !formReady" data-action="publish">{{ $t('settings.maintenance.publish') }}</button>
              <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="form.open = false">{{ $t('common.cancel') }}</button>
            </div>
          </form>

          <details v-if="settings.maintenance.history.length > 0" class="text-text-muted" data-history>
            <summary class="cursor-pointer font-medium text-text">{{ $t('settings.maintenance.history') }}</summary>
            <ul class="mt-2 flex flex-col gap-1">
              <li v-for="row in settings.maintenance.history" :key="row.id">v{{ row.version }} · {{ dateTime(row.starts_at) }} — {{ dateTime(row.ends_at) }} · {{ row.created_by ?? '—' }} · {{ row.reason }}<template v-if="row.cancelled_at"> · {{ $t('settings.maintenance.cancelled_at', { date: dateTime(row.cancelled_at), name: row.cancelled_by ?? '—' }) }}</template></li>
            </ul>
          </details>
        </section>

        <section class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 text-sm" data-section="configuration">
          <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold">{{ $t('settings.config.title') }}</h2>
            <p class="text-text-muted">{{ $t('settings.config.hint') }}</p>
          </div>
          <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-text-muted">{{ $t('settings.config.environment') }}</dt><dd class="font-mono">{{ settings.configuration.environment }} · {{ settings.configuration.release }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('settings.config.app_url') }}</dt><dd class="font-mono">{{ settings.configuration.app_url }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('settings.config.enforcement') }}</dt><dd>{{ $t(`settings.config.enforcement_mode.${settings.configuration.billing.enforcement}`, settings.configuration.billing.enforcement) }}</dd></div>
            <div><dt class="text-text-muted">Paddle</dt><dd class="font-mono">{{ settings.configuration.billing.paddle_environment }} · {{ settings.configuration.billing.paddle_account }}</dd></div>
            <div v-for="key in (['api_key', 'client_token', 'webhook_secret'] as const)" :key="key" :data-secret="key">
              <dt class="text-text-muted">{{ $t(`settings.config.paddle.${key}`) }}</dt>
              <dd class="flex items-center gap-2"><StatusBadge :tone="settings.configuration.billing[key].configured ? 'ok' : 'crit'" :label="$t(settings.configuration.billing[key].configured ? 'settings.config.set' : 'settings.config.missing')" /><span class="font-mono text-text-muted">{{ secret(settings.configuration.billing[key]) }}</span></dd>
            </div>
            <div><dt class="text-text-muted">{{ $t('settings.config.mail') }}</dt><dd class="font-mono">{{ settings.configuration.mail.mailer }} · {{ settings.configuration.mail.from }}</dd></div>
            <div data-secret="telegram">
              <dt class="text-text-muted">Telegram</dt>
              <dd class="flex items-center gap-2"><StatusBadge :tone="settings.configuration.telegram.token.configured ? 'ok' : 'unknown'" :label="$t(settings.configuration.telegram.token.configured ? 'settings.config.set' : 'settings.config.missing')" /><span class="font-mono text-text-muted">{{ settings.configuration.telegram.bot ? settings.configuration.telegram.bot.replace(/^@?/, '@') : '' }} {{ secret(settings.configuration.telegram.token) }}</span></dd>
            </div>
            <div><dt class="text-text-muted">{{ $t('settings.config.sessions') }}</dt><dd>{{ $t('settings.config.sessions_value', settings.configuration.sessions) }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('settings.config.retention') }}</dt><dd>{{ $t('settings.config.retention_value', settings.configuration.retention) }}</dd></div>
            <div data-config="service-alerts"><dt class="text-text-muted">{{ $t('settings.config.service_alerts') }}</dt><dd>{{ settings.configuration.service_alerts ? $t('settings.config.service_alerts_value', { n: settings.configuration.service_alerts }) : $t('settings.config.service_alerts_off') }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('settings.config.site') }}</dt><dd>{{ $t(settings.configuration.site.contact_notify === 0 ? 'settings.config.site_value_owners' : 'settings.config.site_value', settings.configuration.site) }}</dd></div>
          </dl>
          <p class="text-text-muted">{{ $t('settings.later') }}</p>
        </section>
      </template>
    </div>
  </OwnerLayout>
</template>
