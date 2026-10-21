<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { StatusBadge } from '@bw/ui';
import { applyTenantAction, fetchPlans, fetchTenant, previewTenantAction, type ActionPreview, type Plan, type TenantAction, type TenantActionInput, type TenantCard } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import TenantReminder from '@/components/tenant/TenantReminder.vue';
import { ACCESS_TONES } from '@/components/tones';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ card: TenantCard }>();
const emit = defineEmits<{ updated: [card: TenantCard] }>();

const { dateTime, localized } = useFormat();
const plans = ref<Plan[]>([]);
const form = reactive({ action: null as TenantAction | null, overrideId: '', planVersionId: '', extraStores: 0, until: '', reason: '', code: '' });
const preview = ref<ActionPreview | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const done = ref<TenantAction | null>(null);
const reminding = ref(false);
const reminded = ref<number | null>(null);

const subscription = computed(() => props.card.billing?.subscription ?? null);
const activeOverrides = computed(() => props.card.billing?.overrides.filter((override) => override.active) ?? []);
const available = computed<TenantAction[]>(() => {
  const actions: TenantAction[] = [props.card.status === 'suspended' ? 'resume' : 'suspend'];

  if (activeOverrides.value.length === 0) actions.push('grant_override');
  if (subscription.value?.provider === 'internal' && subscription.value.status === 'trial') actions.push('extend_trial');
  if (subscription.value && subscription.value.provider !== 'internal' && subscription.value.status === 'active') actions.push('cancel_subscription');
  if ((props.card.billing?.contracts.length ?? 0) > 0) actions.push('refresh_billing');

  return actions;
});
const versions = computed(() =>
  plans.value.flatMap((plan) => plan.versions.filter((version) => version.state === 'published').map((version) => ({ id: version.id, label: `${localized(version.name, plan.code)} · v${version.version}` }))),
);

function input(): TenantActionInput {
  const iso = form.until === '' ? undefined : new Date(form.until).toISOString();

  switch (form.action) {
    case 'grant_override':
      return { plan_version_id: form.planVersionId, extra_stores: form.extraStores, ends_at: iso };
    case 'revoke_override':
      return { override_id: form.overrideId };
    case 'extend_trial':
      return { trial_ends_at: iso };
    default:
      return {};
  }
}

function remind(): void {
  form.action = null;
  done.value = null;
  reminded.value = null;
  reminding.value = true;
}

async function reminderSent(recipients: number): Promise<void> {
  reminding.value = false;
  reminded.value = recipients;
  const card = await fetchTenant(props.card.id).catch(() => null);
  if (card) emit('updated', card);
}

function choose(action: TenantAction, overrideId = ''): void {
  reminding.value = false;
  reminded.value = null;
  Object.assign(form, { action, overrideId, planVersionId: versions.value[0]?.id ?? '', extraStores: 0, until: '', reason: '', code: '' });
  preview.value = null;
  error.value = null;
  done.value = null;
}

const needsInput = computed(() => form.action === 'grant_override' || form.action === 'extend_trial');
const inputReady = computed(() => !needsInput.value || (form.until !== '' && (form.action !== 'grant_override' || form.planVersionId !== '')));

async function showPreview(): Promise<void> {
  if (form.action === null || !inputReady.value) return;
  busy.value = true;
  error.value = null;

  try {
    preview.value = await previewTenantAction(props.card.id, form.action, input());
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

const confirmReady = computed(() => form.reason.trim().length >= 3 && form.code.trim().length >= 6);

async function confirm(): Promise<void> {
  if (form.action === null || !confirmReady.value) return;
  busy.value = true;
  error.value = null;

  try {
    const card = await applyTenantAction(props.card.id, form.action, input(), { reason: form.reason.trim(), confirmation_code: form.code.trim() });
    done.value = form.action;
    form.action = null;
    preview.value = null;
    emit('updated', card);
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  plans.value = (await fetchPlans().catch(() => null))?.data ?? [];
});
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="actions-title" data-panel="actions">
    <div class="flex flex-col gap-1">
      <h2 id="actions-title" class="text-lg font-semibold">{{ $t('tenant.actions.title') }}</h2>
      <p class="text-sm text-text-muted">{{ $t('tenant.actions.hint') }}</p>
    </div>

    <p v-if="reminded !== null" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-done="reminder">{{ $t('tenant.reminder.done', { n: reminded }) }}</p>
    <p v-if="done" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" :data-done="done">{{ $t(`tenant.actions.done.${done}`) }}</p>

    <div class="flex flex-wrap gap-2">
      <button
        v-for="action in available"
        :key="action"
        type="button"
        class="rounded-md border px-3 py-2 text-sm font-medium disabled:opacity-60"
        :class="['suspend', 'cancel_subscription'].includes(action) ? 'border-crit-border text-crit hover:bg-crit-soft' : 'border-border-strong text-primary hover:bg-primary-soft'"
        :disabled="busy"
        :data-action="action"
        @click="choose(action)"
      >
        {{ $t(`tenant.actions.name.${action}`) }}
      </button>
      <button
        v-for="override in activeOverrides"
        :key="override.id"
        type="button"
        class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
        :disabled="busy"
        data-action="revoke_override"
        @click="choose('revoke_override', override.id)"
      >
        {{ $t('tenant.actions.name.revoke_override') }} ({{ dateTime(override.ends_at) }})
      </button>
      <button type="button" class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" data-action="remind" @click="remind">{{ $t('tenant.reminder.button') }}</button>
      <RouterLink v-if="subscription?.provider === 'paddle' && subscription.status === 'active'" :to="{ name: 'owner-payments', query: { tenant_id: card.id } }" class="rounded-md border border-crit-border px-3 py-2 text-sm font-medium text-crit hover:bg-crit-soft" data-action="cancel-and-refund">{{ $t('refunds.cancel_and_refund') }}</RouterLink>
    </div>

    <TenantReminder v-if="reminding" :tenant-id="card.id" @close="reminding = false" @sent="reminderSent" />

    <form v-if="form.action" class="flex flex-col gap-4 rounded-lg border border-border p-4 text-sm" :data-form="form.action" @submit.prevent="preview ? confirm() : showPreview()">
      <p class="font-medium">{{ $t(`tenant.actions.name.${form.action}`) }}</p>
      <p class="text-text-muted">{{ $t(`tenant.actions.explain.${form.action}`) }}</p>

      <template v-if="form.action === 'grant_override'">
        <label class="flex max-w-md flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.actions.plan') }}</span>
          <select v-model="form.planVersionId" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="plan-version" @change="preview = null">
            <option v-for="version in versions" :key="version.id" :value="version.id">{{ version.label }}</option>
          </select>
        </label>
        <label class="flex max-w-xs flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.actions.extra_stores') }}</span>
          <input v-model.number="form.extraStores" type="number" min="0" max="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="extra-stores" @input="preview = null" />
        </label>
      </template>
      <label v-if="needsInput" class="flex max-w-xs flex-col gap-1.5">
        <span class="font-medium">{{ form.action === 'extend_trial' ? $t('tenant.actions.trial_until') : $t('tenant.actions.until') }}</span>
        <input v-model="form.until" type="datetime-local" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="until" @input="preview = null" />
      </label>

      <div v-if="preview" class="flex flex-col gap-2 rounded-md border border-note-border bg-note-soft p-3" data-preview="action">
        <p class="flex flex-wrap items-center gap-2">
          {{ $t('tenant.actions.access') }}
          <StatusBadge :tone="ACCESS_TONES[preview.access_before.state]" :label="$t(`access.${preview.access_before.state}`)" />
          →
          <StatusBadge :tone="ACCESS_TONES[preview.access_after.state]" :label="$t(`access.${preview.access_after.state}`)" />
        </p>
        <p>{{ $t('tenant.actions.stores_affected', { n: preview.stores }) }}</p>
        <p v-if="preview.effective_at">{{ $t(`tenant.actions.effective.${form.action}`, { date: dateTime(preview.effective_at) }) }}</p>
      </div>

      <template v-if="preview">
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
          <textarea v-model="form.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
        </label>
        <label class="flex max-w-xs flex-col gap-1.5">
          <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
          <input v-model="form.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="step-up" />
        </label>
      </template>

      <ErrorNotice v-if="error" :error="error" />

      <div class="flex flex-wrap gap-2">
        <button
          v-if="!preview"
          type="submit"
          class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
          :disabled="busy || !inputReady"
          data-action="preview"
        >
          {{ $t('tenant.actions.preview') }}
        </button>
        <button
          v-else
          type="submit"
          class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="busy || !confirmReady"
          data-action="confirm"
        >
          {{ $t('tenant.actions.confirm') }}
        </button>
        <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" data-action="close" @click="form.action = null">{{ $t('common.cancel') }}</button>
      </div>
    </form>
  </section>
</template>
