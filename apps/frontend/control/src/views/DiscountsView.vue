<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMinor, parseMinor, StatusBadge } from '@bw/ui';
import {
  archiveDiscount,
  createDiscount,
  fetchDiscounts,
  fetchPlans,
  lookupTenants,
  updateDiscount,
  type Discount,
  type DiscountAudience,
  type DiscountDuration,
  type Plan,
} from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import PlansTabs from '@/components/PlansTabs.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const LOCALES = ['ru', 'en', 'de'] as const;

const { locale, t } = useI18n();
const { can } = useStaff();
const { dateTime, localized } = useFormat();
const canManage = computed(() => can('plans.manage'));

const discounts = ref<Discount[]>([]);
const plans = ref<Plan[]>([]);
const currencies = ref<string[]>(['EUR']);
const loading = ref(true);
const loadError = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref(false);
const done = ref<string | null>(null);

const form = reactive({
  open: false,
  name: '',
  label: { ru: '', en: '', de: '' } as Record<(typeof LOCALES)[number], string>,
  audience: 'code' as DiscountAudience,
  code: '',
  kind: 'percentage' as Discount['kind'],
  percent: '',
  amount: '',
  currency: 'EUR',
  duration: 'once' as DiscountDuration,
  periods: 3,
  usageLimit: '',
  planIds: [] as string[],
  tenants: [] as { id: string; name: string }[],
  startsAt: '',
  expiresAt: '',
});
const search = reactive({ query: '', results: [] as { id: string; name: string }[] });
const confirm = reactive({ target: null as null | 'create' | { kind: 'archive' | 'edit'; discount: Discount }, reason: '', code: '', usageLimit: '', expiresAt: '' });

const percentBps = computed(() => {
  const parsed = parseMinor(form.percent, 2);

  return parsed === null ? null : Number.parseInt(parsed, 10);
});
const amountMinor = computed(() => parseMinor(form.amount, new Intl.NumberFormat('en', { style: 'currency', currency: form.currency }).resolvedOptions().maximumFractionDigits ?? 2));
const usageLimit = computed(() => (form.usageLimit.trim() === '' ? null : /^\d{1,9}$/.test(form.usageLimit.trim()) ? Number.parseInt(form.usageLimit, 10) : -1));
const formValid = computed(
  () =>
    form.name.trim() !== '' &&
    (form.audience !== 'code' || /^[A-Za-z0-9][A-Za-z0-9_-]{2,31}$/.test(form.code.trim())) &&
    (form.audience !== 'tenant' || form.tenants.length > 0) &&
    (form.kind === 'percentage' ? percentBps.value !== null && percentBps.value >= 1 && percentBps.value <= 10000 : amountMinor.value !== null && amountMinor.value !== '0') &&
    (form.duration !== 'periods' || (Number.isInteger(form.periods) && form.periods >= 1 && form.periods <= 1000)) &&
    usageLimit.value !== -1 &&
    usageLimit.value !== 0,
);

function value(discount: Discount): string {
  const amount =
    discount.kind === 'percentage'
      ? `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(Number(discount.percent))} %`
      : (formatMinor(discount.amount_minor ?? '0', discount.currency ?? 'EUR', discount.currency_exponent ?? 2, locale.value) ?? '');

  return `${amount} · ${t(`discounts.duration_text.${discount.duration}`, { n: discount.duration_periods ?? 0 })}`;
}

function planNames(discount: Discount): string {
  if (discount.plan_ids.length === 0) {
    return t('discounts.all_plans');
  }

  return discount.plan_ids.map((id) => {
    const plan = plans.value.find((item) => item.id === id);

    return plan ? localized(plan.versions[0]?.name ?? {}, plan.code) : id;
  }).join(', ');
}

async function load(): Promise<void> {
  loadError.value = null;

  try {
    const [loaded, catalog] = await Promise.all([fetchDiscounts(), fetchPlans()]);
    discounts.value = loaded.data;
    plans.value = catalog.data;
    currencies.value = catalog.catalog.currencies;
  } catch (caught) {
    loadError.value = caught;
  } finally {
    loading.value = false;
  }
}

async function findTenants(): Promise<void> {
  if (search.query.trim().length < 2) {
    return;
  }

  try {
    search.results = (await lookupTenants(search.query.trim())).filter((tenant) => !form.tenants.some((chosen) => chosen.id === tenant.id));
  } catch (caught) {
    actionError.value = caught;
  }
}

function iso(local: string): string | null {
  return local === '' ? null : new Date(local).toISOString();
}

async function confirmAction(): Promise<void> {
  const target = confirm.target;

  if (target === null) {
    return;
  }

  busy.value = true;
  actionError.value = null;
  done.value = null;
  const confirmation = { reason: confirm.reason, confirmation_code: confirm.code.trim() };

  try {
    if (target === 'create') {
      await createDiscount(
        {
          name: form.name.trim(),
          label: { ...form.label },
          audience: form.audience,
          code: form.audience === 'code' ? form.code.trim() : null,
          kind: form.kind,
          percent_bps: form.kind === 'percentage' ? percentBps.value : null,
          amount_minor: form.kind === 'flat' ? amountMinor.value : null,
          currency: form.kind === 'flat' ? form.currency : null,
          duration: form.duration,
          duration_periods: form.duration === 'periods' ? form.periods : null,
          usage_limit: usageLimit.value,
          plan_ids: [...form.planIds],
          tenant_ids: form.tenants.map((tenant) => tenant.id),
          starts_at: iso(form.startsAt),
          expires_at: iso(form.expiresAt),
        },
        confirmation,
      );
      form.open = false;
      done.value = 'discounts.created';
    } else if (target.kind === 'archive') {
      await archiveDiscount(target.discount.id, confirmation);
      done.value = 'discounts.archived';
    } else {
      const limit = confirm.usageLimit.trim();
      await updateDiscount(target.discount.id, { usage_limit: limit === '' ? null : Number.parseInt(limit, 10), expires_at: iso(confirm.expiresAt) }, confirmation);
      done.value = 'discounts.updated';
    }

    confirm.target = null;
    confirm.reason = '';
    await load();
  } catch (caught) {
    actionError.value = caught;
  } finally {
    confirm.code = '';
    busy.value = false;
  }
}

function localInput(isoValue: string | null): string {
  if (isoValue === null) {
    return '';
  }

  const date = new Date(isoValue);
  const pad = (part: number): string => String(part).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function startEdit(discount: Discount): void {
  confirm.target = { kind: 'edit', discount };
  confirm.usageLimit = discount.usage_limit === null ? '' : String(discount.usage_limit);
  confirm.expiresAt = localInput(discount.expires_at);
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-5xl flex-col gap-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('section.plans.title') }}</h1>
          <p class="text-sm text-text-muted">{{ $t('discounts.hint') }}</p>
        </div>
        <button v-if="canManage && !form.open" type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" data-action="new-discount" @click="form.open = true">
          {{ $t('discounts.new') }}
        </button>
      </div>
      <PlansTabs />

      <ErrorNotice v-if="actionError" :error="actionError" />
      <p v-if="done" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok">{{ $t(done) }}</p>

      <form v-if="form.open" class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5" data-form="discount" @submit.prevent="confirm.target = 'create'">
        <h2 class="text-lg font-semibold">{{ $t('discounts.new') }}</h2>

        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('discounts.name') }}</span>
          <input v-model="form.name" maxlength="100" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="name" />
          <span class="text-[13px] text-text-muted">{{ $t('discounts.name_hint') }}</span>
        </label>
        <div class="grid gap-3 sm:grid-cols-3">
          <label v-for="item in LOCALES" :key="item" class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium">{{ $t('discounts.label') }} · {{ item.toUpperCase() }}</span>
            <input v-model="form.label[item]" maxlength="120" class="rounded-md border border-border-strong bg-surface px-3 py-2" :data-field="`label-${item}`" />
          </label>
        </div>

        <fieldset class="flex flex-col gap-2 text-sm">
          <legend class="mb-1 font-medium">{{ $t('discounts.audience') }}</legend>
          <label v-for="audience in (['code', 'tenant', 'automatic'] as const)" :key="audience" class="flex items-start gap-2">
            <input v-model="form.audience" type="radio" :value="audience" class="mt-0.5 size-4 accent-primary" :data-audience="audience" />
            <span>{{ $t(`discounts.audience_name.${audience}`) }} <span class="text-text-muted">— {{ $t(`discounts.audience_hint.${audience}`) }}</span></span>
          </label>
        </fieldset>
        <label v-if="form.audience === 'code'" class="flex max-w-xs flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('discounts.code') }}</span>
          <input v-model="form.code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono uppercase" data-field="code" />
        </label>
        <div v-if="form.audience === 'tenant'" class="flex flex-col gap-2 text-sm">
          <span class="font-medium">{{ $t('discounts.tenants') }}</span>
          <div class="flex gap-2">
            <input v-model="search.query" :placeholder="$t('discounts.tenant_search')" class="flex-1 rounded-md border border-border-strong bg-surface px-3 py-2" data-field="tenant-search" @keydown.enter.prevent="findTenants" />
            <button type="button" class="rounded-md border border-border-strong px-3 py-2 font-medium text-primary" data-action="find-tenants" @click="findTenants">{{ $t('discounts.find') }}</button>
          </div>
          <ul v-if="search.results.length > 0" class="flex flex-col divide-y divide-border rounded-lg border border-border">
            <li v-for="tenant in search.results" :key="tenant.id" class="flex items-center justify-between gap-2 p-2">
              <span>{{ tenant.name }}</span>
              <button type="button" class="text-primary hover:underline" :data-add-tenant="tenant.name" @click="form.tenants.push(tenant); search.results = search.results.filter((item) => item.id !== tenant.id)">{{ $t('discounts.add') }}</button>
            </li>
          </ul>
          <div class="flex flex-wrap gap-2">
            <span v-for="tenant in form.tenants" :key="tenant.id" class="inline-flex items-center gap-1 rounded-full border border-border-strong px-2.5 py-0.5">
              {{ tenant.name }}
              <button type="button" :aria-label="$t('discounts.remove')" @click="form.tenants = form.tenants.filter((item) => item.id !== tenant.id)">×</button>
            </span>
          </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 text-sm">
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.kind') }}</span>
            <select v-model="form.kind" class="rounded-md border border-border-strong bg-surface px-2 py-2" data-field="kind">
              <option value="percentage">{{ $t('discounts.kind_name.percentage') }}</option>
              <option value="flat">{{ $t('discounts.kind_name.flat') }}</option>
            </select>
          </label>
          <label v-if="form.kind === 'percentage'" class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.percent') }}</span>
            <input v-model="form.percent" inputmode="decimal" placeholder="20" class="w-24 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" data-field="percent" />
          </label>
          <template v-else>
            <label class="flex flex-col gap-1.5">
              <span class="font-medium">{{ $t('discounts.amount') }}</span>
              <input v-model="form.amount" inputmode="decimal" placeholder="5,00" class="w-28 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" data-field="amount" />
            </label>
            <label class="flex flex-col gap-1.5">
              <span class="font-medium">{{ $t('discounts.currency') }}</span>
              <select v-model="form.currency" class="rounded-md border border-border-strong bg-surface px-2 py-2">
                <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
              </select>
            </label>
          </template>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.duration') }}</span>
            <select v-model="form.duration" class="rounded-md border border-border-strong bg-surface px-2 py-2" data-field="duration">
              <option v-for="duration in (['once', 'periods', 'forever'] as const)" :key="duration" :value="duration">{{ $t(`discounts.duration_name.${duration}`) }}</option>
            </select>
          </label>
          <label v-if="form.duration === 'periods'" class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.periods') }}</span>
            <input v-model.number="form.periods" type="number" min="1" max="1000" class="w-20 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" data-field="periods" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.usage_limit') }}</span>
            <input v-model="form.usageLimit" inputmode="numeric" :placeholder="$t('discounts.no_limit')" class="w-32 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" data-field="usage-limit" />
          </label>
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.starts_at') }}</span>
            <input v-model="form.startsAt" type="datetime-local" class="rounded-md border border-border-strong bg-surface px-2 py-2" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.expires_at') }}</span>
            <input v-model="form.expiresAt" type="datetime-local" class="rounded-md border border-border-strong bg-surface px-2 py-2" data-field="expires-at" />
          </label>
        </div>

        <fieldset class="flex flex-col gap-2 text-sm">
          <legend class="mb-1 font-medium">{{ $t('discounts.plans') }}</legend>
          <label v-for="plan in plans" :key="plan.id" class="flex items-center gap-2">
            <input v-model="form.planIds" type="checkbox" :value="plan.id" class="size-4 accent-primary" :data-plan-option="plan.code" />
            {{ localized(plan.versions[0]?.name ?? {}, plan.code) }} <span class="font-mono text-[13px] text-text-muted">{{ plan.code }}</span>
          </label>
          <span class="text-[13px] text-text-muted">{{ $t('discounts.plans_hint') }}</span>
        </fieldset>

        <div class="flex gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="!formValid" data-action="create-discount">
            {{ $t('discounts.create') }}
          </button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium" @click="form.open = false">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <form v-if="confirm.target" class="flex flex-col gap-3 rounded-xl border border-border-strong bg-surface p-5" data-form="confirm" @submit.prevent="confirmAction">
        <p class="text-sm font-medium">
          {{ confirm.target === 'create' ? $t('discounts.confirm_create') : confirm.target.kind === 'archive' ? $t('discounts.confirm_archive', { name: confirm.target.discount.name }) : $t('discounts.confirm_edit', { name: confirm.target.discount.name }) }}
        </p>
        <div v-if="confirm.target !== 'create' && confirm.target.kind === 'edit'" class="flex flex-wrap gap-3 text-sm">
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.usage_limit') }}</span>
            <input v-model="confirm.usageLimit" inputmode="numeric" :placeholder="$t('discounts.no_limit')" class="w-32 rounded-md border border-border-strong bg-surface px-2 py-2 text-right" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('discounts.expires_at') }}</span>
            <input v-model="confirm.expiresAt" type="datetime-local" class="rounded-md border border-border-strong bg-surface px-2 py-2" />
          </label>
        </div>
        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('plans.reason') }}</span>
          <input v-model="confirm.reason" maxlength="500" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
        </label>
        <label class="flex max-w-xs flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
          <input v-model="confirm.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="step-up" />
        </label>
        <div class="flex gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || confirm.reason.trim().length < 3 || confirm.code.trim().length < 6" data-action="confirm">
            {{ $t('plans.confirm') }}
          </button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium" @click="confirm.target = null">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="loadError" :error="loadError" />
      <p v-else-if="discounts.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('discounts.empty') }}</p>

      <ul v-else class="flex flex-col gap-3">
        <li v-for="discount in discounts" :key="discount.id" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4" :data-discount="discount.code ?? discount.name">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-semibold">{{ discount.name }}</span>
            <span v-if="discount.code" class="rounded-md border border-border-strong px-2 py-0.5 font-mono text-[13px]">{{ discount.code }}</span>
            <StatusBadge :tone="discount.state === 'active' ? 'ok' : 'unknown'" :label="$t(`discounts.state.${discount.state}`)" />
            <StatusBadge tone="note" :label="$t(`discounts.audience_name.${discount.audience}`)" />
          </div>
          <p class="text-sm">{{ value(discount) }}</p>
          <p class="text-[13px] text-text-muted">
            {{ planNames(discount) }}
            <template v-if="discount.tenants.length > 0"> · {{ discount.tenants.map((tenant) => tenant.name).join(', ') }}</template>
            · {{ $t('discounts.used', { used: discount.used, limit: discount.usage_limit ?? '∞' }) }}
            <template v-if="discount.starts_at"> · {{ $t('discounts.from', { date: dateTime(discount.starts_at) }) }}</template>
            <template v-if="discount.expires_at"> · {{ $t('discounts.until', { date: dateTime(discount.expires_at) }) }}</template>
          </p>
          <div v-if="canManage && discount.state === 'active'" class="flex gap-2">
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" :data-action="`edit-${discount.id}`" @click="startEdit(discount)">{{ $t('discounts.edit') }}</button>
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" :data-action="`archive-${discount.id}`" @click="confirm.target = { kind: 'archive', discount }">{{ $t('discounts.archive') }}</button>
          </div>
        </li>
      </ul>
    </div>
  </OwnerLayout>
</template>
