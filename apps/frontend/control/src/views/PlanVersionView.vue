<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { parseMinor, StatusBadge, type Tone } from '@bw/ui';
import {
  addPrice,
  createVersion,
  fetchPlans,
  publishVersion,
  removePrice,
  retireVersion,
  updateVersion,
  type Interval,
  type Plan,
  type PlanCatalog,
  type PlanPrice,
  type PlanState,
  type PlanVersion,
} from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const LOCALES = ['ru', 'en', 'de'] as const;
const STATE_TONES: Record<PlanState, Tone> = { draft: 'note', published: 'ok', retired: 'unknown' };

const props = defineProps<{ id: string }>();
const router = useRouter();
const { can } = useStaff();
const { localized, price: priceLabel, period } = useFormat();

const catalog = ref<PlanCatalog | null>(null);
const plan = ref<Plan | null>(null);
const version = ref<PlanVersion | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const actionError = ref<unknown>(null);
const saved = ref(false);
const busy = ref(false);

const draft = reactive({
  name: { ru: '', en: '', de: '' } as Record<(typeof LOCALES)[number], string>,
  description: { ru: '', en: '', de: '' } as Record<(typeof LOCALES)[number], string>,
  trialDays: 0,
  graceDays: 30,
  contactSales: false,
  fasterMinutes: '' as string | number,
  features: [] as string[],
  limits: {} as Record<string, string>,
  unlimited: {} as Record<string, boolean>,
});
const newPrice = reactive({ kind: 'base' as PlanPrice['kind'], currency: 'EUR', amount: '', interval: 'month' as Interval, count: 1 });
const confirm = reactive({ action: null as 'publish' | 'retire' | null, reason: '', code: '' });

const editable = computed(() => version.value?.state === 'draft' && can('plans.manage'));
const exponent = computed(() => new Intl.NumberFormat('en', { style: 'currency', currency: newPrice.currency }).resolvedOptions().maximumFractionDigits ?? 2);
const amountMinor = computed(() => parseMinor(newPrice.amount, exponent.value));
const hasDraft = computed(() => plan.value?.versions.some((item) => item.state === 'draft') ?? false);

function fill(current: PlanVersion): void {
  version.value = current;

  for (const locale of LOCALES) {
    draft.name[locale] = current.name[locale] ?? '';
    draft.description[locale] = current.description[locale] ?? '';
  }

  draft.trialDays = current.trial_days;
  draft.graceDays = current.order_volume_grace_days;
  draft.contactSales = current.contact_sales;
  draft.fasterMinutes = current.faster_check_interval_minutes ?? '';
  draft.features = [...current.entitlements.features];

  for (const limit of catalog.value?.catalog.limits ?? []) {
    const value = current.entitlements.limits[limit];
    draft.unlimited[limit] = value === null || value === undefined;
    draft.limits[limit] = value === null || value === undefined ? '' : String(value);
  }
}

async function load(): Promise<void> {
  loadError.value = null;

  try {
    catalog.value = await fetchPlans();
    plan.value = catalog.value.data.find((item) => item.versions.some((candidate) => candidate.id === props.id)) ?? null;
    const current = plan.value?.versions.find((item) => item.id === props.id) ?? null;

    if (current === null) {
      loadError.value = new Error('not_found');
    } else {
      fill(current);
    }
  } catch (caught) {
    loadError.value = caught;
  } finally {
    loading.value = false;
  }
}

async function act(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  actionError.value = null;
  saved.value = false;

  try {
    await action();
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

function limitsPayload(): Record<string, number | null> | null {
  const limits: Record<string, number | null> = {};

  for (const limit of catalog.value?.catalog.limits ?? []) {
    if (draft.unlimited[limit]) {
      limits[limit] = null;
      continue;
    }

    const raw = (draft.limits[limit] ?? '').trim();

    if (!/^\d{1,9}$/.test(raw)) {
      return null;
    }

    limits[limit] = Number.parseInt(raw, 10);
  }

  return limits;
}

const limitsValid = computed(() => limitsPayload() !== null);

async function save(): Promise<void> {
  const limits = limitsPayload();

  if (version.value === null || limits === null) {
    return;
  }

  await act(async () => {
    await updateVersion(version.value!.id, {
      name: { ...draft.name },
      description: { ...draft.description },
      trial_days: draft.trialDays,
      order_volume_grace_days: draft.graceDays,
      contact_sales: draft.contactSales,
      faster_check_interval_minutes: draft.fasterMinutes === '' ? null : Number(draft.fasterMinutes),
      entitlements: { features: draft.features, limits },
    });
    await load();
    saved.value = true;
  });
}

async function add(): Promise<void> {
  if (version.value === null || amountMinor.value === null) {
    return;
  }

  await act(async () => {
    await addPrice(version.value!.id, { kind: newPrice.kind, currency: newPrice.currency, amount_minor: amountMinor.value!, interval: newPrice.interval, interval_count: newPrice.count });
    newPrice.amount = '';
    await load();
  });
}

async function remove(item: PlanPrice): Promise<void> {
  await act(async () => {
    await removePrice(item.id);
    await load();
  });
}

async function nextVersion(): Promise<void> {
  if (plan.value === null) {
    return;
  }

  await act(async () => {
    const created = await createVersion(plan.value!.id);
    await router.push({ name: 'owner-plan-version', params: { id: created.id } });
    await load();
  });
}

async function confirmAction(): Promise<void> {
  if (version.value === null || confirm.action === null) {
    return;
  }

  const action = confirm.action;
  await act(async () => {
    if (action === 'publish') {
      await publishVersion(version.value!.id, confirm.reason, confirm.code.trim());
    } else {
      await retireVersion(version.value!.id, confirm.reason, confirm.code.trim());
    }

    confirm.action = null;
    confirm.reason = '';
    await load();
  });
  confirm.code = '';
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-4xl flex-col gap-6">
      <RouterLink :to="{ name: 'owner-plans' }" class="text-sm text-primary hover:underline">← {{ $t('section.plans.title') }}</RouterLink>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="loadError" :error="loadError" />

      <template v-else-if="plan && version && catalog">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="text-2xl font-semibold">{{ localized(version.name, plan.code) }}</h1>
          <span class="font-mono text-sm text-text-muted">{{ plan.code }} · {{ $t('plans.version', { n: version.version }) }}</span>
          <StatusBadge :tone="STATE_TONES[version.state]" :label="$t(`plans.state.${version.state}`)" />
        </div>

        <p v-if="version.state === 'published'" class="rounded-md border border-note-border bg-note-soft px-3 py-2 text-sm text-note">{{ $t('plans.published_note', { n: version.subscribers }) }}</p>
        <p v-if="version.state === 'retired'" class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm text-text-muted">{{ $t('plans.retired_note', { n: version.subscribers }) }}</p>

        <ErrorNotice v-if="actionError" :error="actionError" />
        <p v-if="saved" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok">{{ $t('plans.saved') }}</p>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" data-panel="content">
          <h2 class="text-lg font-semibold">{{ $t('plans.content') }}</h2>
          <div class="grid gap-3 sm:grid-cols-3">
            <label v-for="locale in LOCALES" :key="`name-${locale}`" class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.name') }} · {{ locale.toUpperCase() }}</span>
              <input v-model="draft.name[locale]" :disabled="!editable" maxlength="80" class="rounded-md border border-border-strong bg-surface px-3 py-2 disabled:bg-surface-muted" :data-field="`name-${locale}`" />
            </label>
            <label v-for="locale in LOCALES" :key="`description-${locale}`" class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.description') }} · {{ locale.toUpperCase() }}</span>
              <textarea v-model="draft.description[locale]" :disabled="!editable" maxlength="500" rows="3" class="rounded-md border border-border-strong bg-surface px-3 py-2 disabled:bg-surface-muted"></textarea>
            </label>
          </div>
          <label class="flex max-w-xs flex-col gap-1.5 text-sm">
            <span class="font-medium">{{ $t('plans.trial_days') }}</span>
            <input v-model.number="draft.trialDays" :disabled="!editable" type="number" min="0" :max="catalog.catalog.max_trial_days" class="rounded-md border border-border-strong bg-surface px-3 py-2 disabled:bg-surface-muted" data-field="trial" />
            <span class="text-[13px] text-text-muted">{{ $t('plans.trial_hint') }}</span>
          </label>
          <label class="flex max-w-xs flex-col gap-1.5 text-sm">
            <span class="font-medium">{{ $t('plans.grace_days') }}</span>
            <input v-model.number="draft.graceDays" :disabled="!editable" type="number" min="0" max="365" class="rounded-md border border-border-strong bg-surface px-3 py-2 disabled:bg-surface-muted" data-field="grace-days" />
            <span class="text-[13px] text-text-muted">{{ $t('plans.grace_hint') }}</span>
          </label>
          <label class="flex max-w-xs flex-col gap-1.5 text-sm">
            <span class="font-medium">{{ $t('plans.faster_minutes') }}</span>
            <input v-model="draft.fasterMinutes" :disabled="!editable" type="number" min="5" max="1440" class="rounded-md border border-border-strong bg-surface px-3 py-2 disabled:bg-surface-muted" data-field="faster-minutes" />
            <span class="text-[13px] text-text-muted">{{ $t('plans.faster_hint') }}</span>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="draft.contactSales" :disabled="!editable" type="checkbox" class="mt-0.5 size-4 accent-primary" data-field="contact-sales" />
            <span class="flex flex-col gap-0.5">
              <span class="font-medium">{{ $t('plans.contact_sales') }}</span>
              <span class="text-[13px] text-text-muted">{{ $t('plans.contact_sales_hint') }}</span>
            </span>
          </label>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" data-panel="features">
          <h2 class="text-lg font-semibold">{{ $t('plans.features') }}</h2>
          <div class="grid gap-2 sm:grid-cols-2">
            <label v-for="feature in catalog.catalog.features" :key="feature" class="flex items-center gap-2 text-sm">
              <input v-model="draft.features" type="checkbox" :value="feature" :disabled="!editable" class="size-4 accent-primary" :data-feature="feature" />
              {{ $t(`feature.${feature}`) }}
            </label>
          </div>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" data-panel="limits">
          <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold">{{ $t('plans.limits') }}</h2>
            <p class="text-sm text-text-muted">{{ $t('plans.limits_hint') }}</p>
          </div>
          <ul class="flex flex-col divide-y divide-border rounded-lg border border-border">
            <li v-for="limit in catalog.catalog.limits" :key="limit" class="flex flex-wrap items-center justify-between gap-3 p-3" :data-limit="limit">
              <div class="flex flex-col gap-0.5">
                <span class="text-sm font-medium">{{ $t(`limit.${limit}`) }}</span>
                <span v-if="catalog.catalog.per_store_limits.includes(limit)" class="text-[13px] text-text-muted">{{ $t('plans.per_store') }}</span>
              </div>
              <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-sm">
                  <input v-model="draft.unlimited[limit]" type="checkbox" :disabled="!editable" class="size-4 accent-primary" />
                  {{ $t('plans.unlimited') }}
                </label>
                <input
                  v-model="draft.limits[limit]"
                  :disabled="!editable || draft.unlimited[limit]"
                  inputmode="numeric"
                  class="w-28 rounded-md border border-border-strong bg-surface px-2 py-1.5 text-right text-sm disabled:bg-surface-muted"
                />
              </div>
            </li>
          </ul>
          <p v-if="editable && !limitsValid" class="text-sm text-crit">{{ $t('plans.limits_invalid') }}</p>
        </section>

        <button
          v-if="editable"
          type="button"
          class="self-start rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="busy || !limitsValid"
          data-action="save"
          @click="save"
        >
          {{ $t('plans.save') }}
        </button>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" data-panel="prices">
          <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold">{{ $t('plans.prices') }}</h2>
            <p class="text-sm text-text-muted">{{ $t('plans.prices_hint', { mode: $t(`mode.${catalog.mode}`) }) }}</p>
          </div>
          <p v-if="version.prices.length === 0" class="text-sm text-text-muted">{{ $t('plans.no_prices') }}</p>
          <ul v-else class="flex flex-col divide-y divide-border rounded-lg border border-border">
            <li v-for="item in version.prices" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 p-3" :data-price="item.kind">
              <div class="flex flex-col gap-0.5">
                <span class="font-medium tabular-nums">{{ priceLabel(item) }}</span>
                <span class="text-[13px] text-text-muted">{{ $t(`plans.kind.${item.kind}`) }} · {{ item.mode.toUpperCase() }}<template v-if="item.at_provider"> · {{ $t('plans.at_provider') }}</template></span>
              </div>
              <button v-if="editable && !item.at_provider" type="button" class="rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" :disabled="busy" @click="remove(item)">
                {{ $t('plans.remove') }}
              </button>
            </li>
          </ul>

          <form v-if="editable" class="flex flex-wrap items-end gap-3 border-t border-border pt-4" data-form="price" @submit.prevent="add">
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.price_kind') }}</span>
              <select v-model="newPrice.kind" class="rounded-md border border-border-strong bg-surface px-2 py-2" data-field="kind">
                <option value="base">{{ $t('plans.kind.base') }}</option>
                <option value="extra_store">{{ $t('plans.kind.extra_store') }}</option>
                <option value="faster_checks">{{ $t('plans.kind.faster_checks') }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.amount') }}</span>
              <input v-model="newPrice.amount" inputmode="decimal" placeholder="19,90" class="w-28 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" data-field="amount" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.currency') }}</span>
              <select v-model="newPrice.currency" class="rounded-md border border-border-strong bg-surface px-2 py-2" data-field="currency">
                <option v-for="currency in catalog.catalog.currencies" :key="currency" :value="currency">{{ currency }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.every') }}</span>
              <input v-model.number="newPrice.count" type="number" min="1" :max="catalog.catalog.max_interval_count" class="w-20 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" data-field="count" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.unit') }}</span>
              <select v-model="newPrice.interval" class="rounded-md border border-border-strong bg-surface px-2 py-2" data-field="interval">
                <option v-for="interval in catalog.catalog.intervals" :key="interval" :value="interval">{{ $t(`plans.unit_name.${interval}`) }}</option>
              </select>
            </label>
            <button type="submit" class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || amountMinor === null" data-action="add-price">
              {{ $t('plans.add_price') }}
            </button>
            <p class="w-full text-[13px] text-text-muted">{{ $t('plans.period_preview', { period: period(newPrice.interval, Math.max(1, newPrice.count || 1)) }) }}</p>
          </form>
        </section>

        <section v-if="can('plans.manage')" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5" data-panel="actions">
          <h2 class="text-lg font-semibold">{{ $t('plans.actions') }}</h2>
          <div class="flex flex-wrap gap-2">
            <button v-if="version.state === 'draft'" type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" data-action="publish" @click="confirm.action = 'publish'">
              {{ $t('plans.publish') }}
            </button>
            <button v-if="version.state !== 'draft' && !hasDraft" type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" :disabled="busy" data-action="new-version" @click="nextVersion">
              {{ $t('plans.new_version') }}
            </button>
            <button v-if="version.state === 'published'" type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-crit hover:bg-crit-soft" data-action="retire" @click="confirm.action = 'retire'">
              {{ $t('plans.retire') }}
            </button>
          </div>
          <p class="text-[13px] text-text-muted">{{ $t('plans.actions_hint') }}</p>

          <form v-if="confirm.action" class="flex flex-col gap-3 rounded-lg border border-border-strong p-4" data-form="confirm" @submit.prevent="confirmAction">
            <p class="text-sm font-medium">{{ $t(`plans.confirm_${confirm.action}`, { n: version.subscribers }) }}</p>
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.reason') }}</span>
              <input v-model="confirm.reason" required minlength="3" maxlength="500" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
            </label>
            <label class="flex max-w-xs flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
              <input v-model="confirm.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="step-up" />
            </label>
            <div class="flex gap-2">
              <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || confirm.reason.trim().length < 3 || confirm.code.trim().length < 6" data-action="confirm">
                {{ $t('plans.confirm') }}
              </button>
              <button type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium" @click="confirm.action = null">{{ $t('common.cancel') }}</button>
            </div>
          </form>
        </section>
      </template>
    </div>
  </OwnerLayout>
</template>
