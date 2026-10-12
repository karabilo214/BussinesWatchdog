<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { newIdempotencyKey } from '@bw/api-client';
import { formatMinor, parseMinor, StatusBadge, type Tone } from '@bw/ui';
import {
  checkDiscount,
  fetchCheckout,
  fetchEntitlements,
  fetchPlans,
  fetchSubscription,
  startCheckout,
  startTrial,
  type AccessState,
  type BillingCatalog,
  type BillingCheckout,
  type BillingDiscount,
  type BillingPlan,
  type BillingPrice,
  type BillingSubscription,
  type Entitlements,
  type SubscriptionStatus,
} from '@/api/billing';
import { listStores } from '@/api/stores';
import ErrorNotice from '@/components/ErrorNotice.vue';
import SettingsTabs from '@/components/account/SettingsTabs.vue';
import { openPaddleCheckout } from '@/composables/paddle';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';

const POLL_MS = 3000;
const POLL_LIMIT = 100;

const ACCESS_TONES: Record<AccessState, Tone> = {
  pilot: 'ok',
  active: 'ok',
  override: 'ok',
  trial: 'note',
  grace: 'warn',
  paused_billing: 'crit',
  paused_manual: 'crit',
  no_service: 'crit',
  onboarding: 'unknown',
};

const STATUS_TONES: Record<SubscriptionStatus, Tone> = {
  trial: 'note',
  active: 'ok',
  past_due: 'warn',
  suspended: 'crit',
  cancelled: 'unknown',
};

const PLAN_LIMITS = ['max_active_stores', 'min_check_interval_minutes', 'manual_runs_per_day', 'monthly_event_budget', 'retention_days'] as const;

const { locale, t } = useI18n();
const { dateTime, period } = useFormat();
const { role } = useRole();
const isOwner = computed(() => role.value === 'owner');

const entitlements = ref<Entitlements | null>(null);
const catalog = ref<BillingCatalog | null>(null);
const subscription = ref<BillingSubscription | null>(null);
const activeStores = ref<number | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref<string | null>(null);
const extraStores = reactive<Record<string, number>>({});
const checkout = ref<BillingCheckout | null>(null);
const checkoutPhase = ref<'idle' | 'open' | 'confirming' | 'confirmed' | 'failed'>('idle');
const trialStarted = ref(false);
const promo = reactive({ input: '', code: null as string | null, results: {} as Record<string, BillingDiscount | 'invalid'>, busy: false, error: null as unknown });
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let polls = 0;
let pendingKey: { selection: string; key: string } | null = null;

const paid = computed(() => subscription.value !== null && ['active', 'past_due'].includes(subscription.value.status) && subscription.value.provider !== 'internal');
const currentPlan = computed(() => catalog.value?.data.find((plan) => plan.plan_version_id === subscription.value?.plan_version_id) ?? null);
const canTrial = computed(() => isOwner.value && catalog.value?.trial_available === true && subscription.value === null);
const canBuy = computed(() => isOwner.value && catalog.value?.checkout_available === true && !paid.value);

function planName(plan: BillingPlan | null, code: string | null): string {
  if (plan === null) {
    return code ?? t('billing.no_plan');
  }

  return plan.name[locale.value] ?? plan.name.en ?? Object.values(plan.name)[0] ?? plan.plan_code;
}

function price(plan: BillingPlan, kind: BillingPrice['kind']): BillingPrice | null {
  return plan.prices.find((item) => item.kind === kind) ?? null;
}

function money(item: BillingPrice, minor = item.amount_minor): string {
  return formatMinor(minor, item.currency, item.currency_exponent, locale.value) ?? `${minor} ${item.currency}`;
}

function totalMinor(plan: BillingPlan): bigint | null {
  const base = price(plan, 'base');
  const extra = price(plan, 'extra_store');

  if (base === null) {
    return null;
  }

  const quantity = BigInt(extraStores[plan.plan_version_id] ?? 0);

  return BigInt(base.amount_minor) + (extra === null ? 0n : BigInt(extra.amount_minor) * quantity);
}

function total(plan: BillingPlan): string | null {
  const sum = totalMinor(plan);
  const base = price(plan, 'base');

  return sum === null || base === null ? null : money(base, sum.toString());
}

function discountFor(plan: BillingPlan): BillingDiscount | null {
  const promoted = promo.results[plan.plan_version_id];

  return typeof promoted === 'object' ? promoted : plan.discount;
}

function discountedTotal(plan: BillingPlan, discount: BillingDiscount): string | null {
  const sum = totalMinor(plan);
  const base = price(plan, 'base');

  if (sum === null || base === null) {
    return null;
  }

  if (discount.kind === 'flat') {
    const amount = BigInt(discount.amount_minor ?? '0');

    return money(base, (sum > amount ? sum - amount : 0n).toString());
  }

  const bps = BigInt(parseMinor(discount.percent ?? '0', 2) ?? '0');

  return money(base, (sum - (sum * bps + 5000n) / 10000n).toString());
}

function discountText(discount: BillingDiscount): string {
  const own = discount.label[locale.value] ?? discount.label.en;
  const value =
    discount.kind === 'percentage'
      ? `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(Number(discount.percent))} %`
      : (formatMinor(discount.amount_minor ?? '0', discount.currency ?? 'EUR', discount.currency_exponent ?? 2, locale.value) ?? '');
  const terms = t('billing.discount.terms', { value, duration: t(`billing.discount.duration.${discount.duration}`, { n: discount.duration_periods ?? 0 }) });
  const until = discount.expires_at === null ? '' : ` ${t('billing.discount.until', { date: dateTime(discount.expires_at) })}`;

  return `${own ? `${own}: ` : ''}${terms}${until}`;
}

async function applyPromo(): Promise<void> {
  const code = promo.input.trim();

  if (code === '' || catalog.value === null) {
    return;
  }

  promo.busy = true;
  promo.error = null;
  promo.results = {};
  let firstError: unknown = null;

  for (const plan of catalog.value.data) {
    const base = price(plan, 'base');

    if (base === null) {
      continue;
    }

    try {
      promo.results[plan.plan_version_id] = await checkDiscount(code, base.id);
    } catch (caught) {
      promo.results[plan.plan_version_id] = 'invalid';
      firstError ??= caught;
    }
  }

  promo.code = Object.values(promo.results).some((result) => typeof result === 'object') ? code : null;
  promo.error = promo.code === null ? firstError : null;
  promo.busy = false;
}

function maxExtra(plan: BillingPlan): number {
  return plan.entitlements.limits.max_extra_stores ?? 1000;
}

function count(value: number): string {
  return new Intl.NumberFormat(locale.value).format(value);
}

function limitValue(code: (typeof PLAN_LIMITS)[number], value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return t('billing.unlimited');
  }

  return t(`billing.limit_value.${code}`, { n: count(value) });
}

const usageRows = computed(() => {
  const current = entitlements.value;

  if (current === null) {
    return [];
  }

  const budget = current.limits.monthly_event_budget ?? null;
  const events = current.usage.monthly_event_budget.used;
  const ratio = budget === null || budget === 0 ? null : events / budget;

  return [
    { key: 'stores', used: activeStores.value, limit: current.limits.max_active_stores ?? null, note: null, tone: null as Tone | null },
    {
      key: 'manual_runs',
      used: current.usage.manual_runs_per_day.used,
      limit: current.limits.manual_runs_per_day ?? null,
      note: t('billing.resets_at', { date: dateTime(current.usage.manual_runs_per_day.period_end) }),
      tone: null as Tone | null,
    },
    {
      key: 'events',
      used: events,
      limit: budget,
      note: ratio === null ? t('billing.resets_at', { date: dateTime(current.usage.monthly_event_budget.period_end) }) : ratio >= 1 ? t('billing.events_over') : ratio >= 0.8 ? t('billing.events_near') : t('billing.resets_at', { date: dateTime(current.usage.monthly_event_budget.period_end) }),
      tone: (ratio === null ? null : ratio >= 1 ? 'crit' : ratio >= 0.8 ? 'warn' : null) as Tone | null,
    },
  ];
});

async function load(): Promise<void> {
  loadError.value = null;

  try {
    const [loadedEntitlements, loadedCatalog, loadedSubscription, stores] = await Promise.all([
      fetchEntitlements(),
      fetchPlans(),
      isOwner.value ? fetchSubscription() : Promise.resolve(null),
      listStores(),
    ]);
    entitlements.value = loadedEntitlements;
    catalog.value = loadedCatalog;
    subscription.value = loadedSubscription;
    activeStores.value = stores.filter((store) => store.status !== 'deleted').length;

    for (const plan of loadedCatalog.data) {
      extraStores[plan.plan_version_id] ??= 0;
    }
  } catch (caught) {
    loadError.value = caught;
  } finally {
    loading.value = false;
  }
}

async function trial(plan: BillingPlan): Promise<void> {
  busy.value = `trial:${plan.plan_version_id}`;
  actionError.value = null;

  try {
    await startTrial(plan.plan_version_id);
    trialStarted.value = true;
    await load();
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = null;
  }
}

function stopPolling(): void {
  if (pollTimer !== null) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }
}

async function poll(): Promise<void> {
  if (checkout.value === null) {
    return;
  }

  try {
    checkout.value = await fetchCheckout(checkout.value.id);
  } catch (caught) {
    actionError.value = caught;
  }

  const status = checkout.value.status;

  if (status === 'confirmed') {
    const current = await fetchSubscription().catch(() => null);

    if (current !== null && current.provider !== 'internal') {
      checkoutPhase.value = 'confirmed';
      pendingKey = null;
      await load();

      return;
    }
  }

  if (['failed', 'cancelled', 'expired'].includes(status)) {
    checkoutPhase.value = 'failed';
    pendingKey = null;

    return;
  }

  if (++polls < POLL_LIMIT) {
    pollTimer = setTimeout(() => void poll(), POLL_MS);
  }
}

function onPaddleEvent(name: string): void {
  if (name === 'checkout.completed') {
    checkoutPhase.value = 'confirming';
    polls = 0;
    stopPolling();
    void poll();
  } else if (name === 'checkout.closed' && checkoutPhase.value === 'open') {
    checkoutPhase.value = 'idle';
  }
}

async function buy(plan: BillingPlan): Promise<void> {
  const base = price(plan, 'base');

  if (base === null) {
    return;
  }

  const quantity = Math.max(0, Math.min(maxExtra(plan), Math.trunc(extraStores[plan.plan_version_id] ?? 0)));
  const code = typeof promo.results[plan.plan_version_id] === 'object' ? promo.code : null;
  const selection = `${base.id}:${quantity}:${code ?? ''}`;

  if (pendingKey === null || pendingKey.selection !== selection) {
    pendingKey = { selection, key: newIdempotencyKey() };
  }

  busy.value = `buy:${plan.plan_version_id}`;
  actionError.value = null;
  stopPolling();

  try {
    checkout.value = await startCheckout(base.id, quantity, pendingKey.key, code);

    if (checkout.value.provider_checkout === null) {
      checkoutPhase.value = 'confirming';
      polls = 0;
      await poll();

      return;
    }

    checkoutPhase.value = 'open';
    await openPaddleCheckout(checkout.value.provider_checkout, onPaddleEvent);
  } catch (caught) {
    actionError.value = caught;
    checkoutPhase.value = 'idle';
  } finally {
    busy.value = null;
  }
}

function features(plan: BillingPlan): string[] {
  return plan.entitlements.features.map((code) => t(`billing.feature.${code}`));
}

onMounted(load);
onBeforeUnmount(stopPolling);
</script>

<template>
  <AppLayout>
    <div class="flex max-w-5xl flex-col gap-6">
      <h1 class="text-2xl font-semibold">{{ $t('settings.title') }}</h1>
      <SettingsTabs />

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="loadError" :error="loadError" />

      <template v-else-if="entitlements && catalog">
        <p v-if="!entitlements.enforced" class="rounded-md border border-note-border bg-note-soft px-3 py-2 text-sm text-note" data-notice="pilot">
          {{ $t('billing.pilot') }}
        </p>
        <p v-if="catalog.mode === 'test'" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm text-warn" data-notice="test-mode">
          {{ $t('billing.test_mode') }}
        </p>

        <ErrorNotice v-if="actionError" :error="actionError" />
        <p v-if="trialStarted" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok">{{ $t('billing.trial_started') }}</p>
        <p v-if="checkoutPhase === 'confirming'" role="status" class="rounded-md border border-note-border bg-note-soft px-3 py-2 text-sm text-note" data-checkout="confirming">
          {{ $t('billing.checkout_confirming') }}
        </p>
        <p v-else-if="checkoutPhase === 'confirmed'" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-checkout="confirmed">
          {{ $t('billing.checkout_confirmed') }}
        </p>
        <p v-else-if="checkoutPhase === 'failed'" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-checkout="failed">
          {{ $t('billing.checkout_failed') }}
        </p>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="subscription-title" data-panel="subscription">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="subscription-title" class="text-lg font-semibold">{{ $t('billing.subscription') }}</h2>
            <StatusBadge :tone="ACCESS_TONES[entitlements.access_state]" :label="$t(`billing.access.${entitlements.access_state}`)" />
          </div>

          <dl v-if="subscription" class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div class="flex flex-col gap-0.5">
              <dt class="text-text-muted">{{ $t('billing.plan') }}</dt>
              <dd class="font-medium">{{ planName(currentPlan, subscription.plan_code) }}</dd>
            </div>
            <div class="flex flex-col gap-0.5">
              <dt class="text-text-muted">{{ $t('billing.status_label') }}</dt>
              <dd><StatusBadge :tone="STATUS_TONES[subscription.status]" :label="$t(`billing.status.${subscription.status}`)" /></dd>
            </div>
            <div v-if="subscription.trial_ends_at" class="flex flex-col gap-0.5">
              <dt class="text-text-muted">{{ $t('billing.trial_ends_at') }}</dt>
              <dd>{{ dateTime(subscription.trial_ends_at) }}</dd>
            </div>
            <div v-if="subscription.paid_until" class="flex flex-col gap-0.5">
              <dt class="text-text-muted">{{ subscription.cancel_at_period_end ? $t('billing.ends_at') : $t('billing.renews_at') }}</dt>
              <dd>{{ dateTime(subscription.paid_until) }}</dd>
            </div>
            <div v-if="subscription.status === 'past_due' && subscription.grace_until" class="flex flex-col gap-0.5">
              <dt class="text-text-muted">{{ $t('billing.grace_until') }}</dt>
              <dd class="text-warn">{{ dateTime(subscription.grace_until) }}</dd>
            </div>
            <div v-if="subscription.extra_store_quantity > 0" class="flex flex-col gap-0.5">
              <dt class="text-text-muted">{{ $t('billing.extra_stores') }}</dt>
              <dd>{{ count(subscription.extra_store_quantity) }}</dd>
            </div>
          </dl>
          <p v-else-if="isOwner" class="text-sm text-text-muted">{{ $t('billing.no_subscription') }}</p>
          <p v-else class="text-sm text-text-muted">
            {{ $t('billing.plan') }}: <span class="font-medium text-text">{{ planName(null, entitlements.plan_code) }}</span>. {{ $t('billing.owner_manages') }}
          </p>
          <p v-if="subscription?.cancel_at_period_end" class="text-sm text-warn">{{ $t('billing.cancel_scheduled') }}</p>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="usage-title" data-panel="usage">
          <div class="flex flex-col gap-1">
            <h2 id="usage-title" class="text-lg font-semibold">{{ $t('billing.usage') }}</h2>
            <p class="text-sm text-text-muted">{{ $t('billing.usage_hint') }}</p>
          </div>
          <ul class="flex flex-col divide-y divide-border rounded-lg border border-border">
            <li v-for="row in usageRows" :key="row.key" class="flex flex-wrap items-center justify-between gap-2 p-3" :data-usage="row.key">
              <div class="flex min-w-0 flex-col gap-0.5">
                <span class="font-medium">{{ $t(`billing.usage_row.${row.key}`) }}</span>
                <span v-if="row.note" class="text-[13px]" :class="row.tone === 'crit' ? 'text-crit' : row.tone === 'warn' ? 'text-warn' : 'text-text-muted'">{{ row.note }}</span>
              </div>
              <span class="text-sm tabular-nums">
                {{ row.used === null ? '—' : count(row.used) }} /
                {{ row.limit === null ? $t('billing.unlimited') : count(row.limit) }}
              </span>
            </li>
          </ul>
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="plans-title" data-panel="plans">
          <div class="flex flex-col gap-1">
            <h2 id="plans-title" class="text-lg font-semibold">{{ $t('billing.plans') }}</h2>
            <p class="text-sm text-text-muted">{{ $t('billing.tax_note') }}</p>
            <p v-if="paid" class="text-sm text-text-muted">{{ $t('billing.change_later') }}</p>
            <p v-else-if="!isOwner" class="text-sm text-text-muted">{{ $t('billing.owner_manages') }}</p>
            <p v-else-if="!catalog.checkout_available" class="text-sm text-text-muted">{{ $t('billing.checkout_unavailable') }}</p>
          </div>

          <form v-if="canBuy && catalog.data.length > 0" class="flex flex-wrap items-end gap-2" data-form="promo" @submit.prevent="applyPromo">
            <label class="flex flex-col gap-1.5 text-sm">
              <span class="font-medium">{{ $t('billing.discount.promo') }}</span>
              <input v-model="promo.input" maxlength="32" autocapitalize="characters" class="w-48 rounded-md border border-border-strong bg-surface px-3 py-2 font-mono uppercase" data-field="promo" />
            </label>
            <button type="submit" class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="promo.busy || promo.input.trim() === ''" data-action="apply-promo">
              {{ $t('billing.discount.apply') }}
            </button>
            <p v-if="promo.code" role="status" class="w-full text-sm text-ok" data-promo="applied">{{ $t('billing.discount.applied', { code: promo.code.toUpperCase() }) }}</p>
            <ErrorNotice v-if="promo.error" :error="promo.error" class="w-full" />
          </form>

          <p v-if="catalog.data.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('billing.no_plans') }}</p>

          <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <article
              v-for="plan in catalog.data"
              :key="plan.plan_version_id"
              class="flex flex-col gap-4 rounded-xl border bg-surface p-5"
              :class="plan.plan_version_id === subscription?.plan_version_id ? 'border-primary' : 'border-border'"
              :data-plan="plan.plan_code"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-base font-semibold">{{ planName(plan, plan.plan_code) }}</h3>
                <div class="flex gap-1.5">
                  <StatusBadge v-if="plan.plan_version_id === subscription?.plan_version_id" tone="ok" :label="$t('billing.current')" />
                  <StatusBadge v-if="plan.placeholder" tone="unknown" :label="$t('billing.placeholder')" />
                </div>
              </div>

              <div v-if="price(plan, 'base')" class="flex flex-col gap-1">
                <p class="text-2xl font-semibold tabular-nums">{{ money(price(plan, 'base')!) }} <span class="text-sm font-normal text-text-muted">{{ period(price(plan, 'base')!.interval, price(plan, 'base')!.interval_count) }}</span></p>
                <p v-if="price(plan, 'extra_store')" class="text-[13px] text-text-muted">
                  {{ $t('billing.extra_store_price', { price: money(price(plan, 'extra_store')!), period: period(price(plan, 'extra_store')!.interval, price(plan, 'extra_store')!.interval_count), max: count(maxExtra(plan)) }) }}
                </p>
              </div>
              <p v-else class="text-sm text-text-muted">{{ $t('billing.no_price') }}</p>
              <p v-if="discountFor(plan)" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" :data-discount="plan.plan_code">{{ discountText(discountFor(plan)!) }}</p>

              <ul class="flex flex-col gap-1 text-sm">
                <li v-for="code in PLAN_LIMITS" :key="code" class="flex justify-between gap-3">
                  <span class="text-text-muted">{{ $t(`billing.limit.${code}`) }}</span>
                  <span class="text-right whitespace-nowrap">{{ limitValue(code, plan.entitlements.limits[code]) }}</span>
                </li>
              </ul>

              <ul class="flex flex-col gap-1 text-sm">
                <li v-for="label in features(plan)" :key="label" class="flex gap-2"><span class="text-ok" aria-hidden="true">✓</span>{{ label }}</li>
              </ul>

              <div v-if="isOwner" class="mt-auto flex flex-col gap-3">
                <button
                  v-if="canTrial && plan.trial_days > 0"
                  type="button"
                  class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
                  :disabled="busy !== null"
                  :data-action="`trial-${plan.plan_code}`"
                  @click="trial(plan)"
                >
                  {{ $t('billing.start_trial', { days: plan.trial_days }) }}
                </button>

                <template v-if="canBuy && price(plan, 'base')">
                  <label v-if="price(plan, 'extra_store') && maxExtra(plan) > 0" class="flex items-center justify-between gap-3 text-sm">
                    <span>{{ $t('billing.extra_stores') }}</span>
                    <input
                      v-model.number="extraStores[plan.plan_version_id]"
                      type="number"
                      min="0"
                      :max="maxExtra(plan)"
                      step="1"
                      class="w-20 rounded-md border border-border-strong bg-surface px-2 py-1.5 text-right text-sm"
                      :data-extra="plan.plan_code"
                    />
                  </label>
                  <p v-if="discountFor(plan)" class="text-sm" data-total="discounted">
                    {{ $t('billing.discount.total_prefix') }} <s class="text-text-muted">{{ total(plan) }}</s> {{ discountedTotal(plan, discountFor(plan)!) }}
                    {{ $t('billing.discount.first_payment') }}
                  </p>
                  <p v-else class="text-sm">{{ $t('billing.total', { price: total(plan), period: period(price(plan, 'base')!.interval, price(plan, 'base')!.interval_count) }) }}</p>
                  <button
                    type="button"
                    class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
                    :disabled="busy !== null || checkoutPhase === 'confirming'"
                    :data-action="`buy-${plan.plan_code}`"
                    @click="buy(plan)"
                  >
                    {{ busy === `buy:${plan.plan_version_id}` ? $t('billing.opening_checkout') : $t('billing.buy') }}
                  </button>
                </template>
              </div>
            </article>
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
