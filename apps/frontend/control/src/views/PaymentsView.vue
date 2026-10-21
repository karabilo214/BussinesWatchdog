<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { formatMinor, parseMinor, StatusBadge } from '@bw/ui';
import { fetchPayments, requestRefund, type Payment, type PaymentMonth, type PaymentStatus } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import RefundsPanel from '@/components/RefundsPanel.vue';
import { useStaff } from '@/composables/useStaff';
import { PAYMENT_TONES } from '@/components/payments';
import { useFormat } from '@/composables/useFormat';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const STATUSES: PaymentStatus[] = ['completed', 'paid', 'billed', 'past_due', 'canceled', 'ready'];

const route = useRoute();
const router = useRouter();
const { locale } = useI18n();
const { dateTime } = useFormat();
const rows = ref<Payment[]>([]);
const month = ref<PaymentMonth[]>([]);
const mode = ref<'test' | 'live'>('test');
const cursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const status = ref<string>(typeof route.query.status === 'string' ? route.query.status : '');
const tenantId = typeof route.query.tenant_id === 'string' ? route.query.tenant_id : null;
const { can } = useStaff();
const refundsPanel = ref<InstanceType<typeof RefundsPanel> | null>(null);
const refund = reactive({ payment: null as Payment | null, amount: '', full: false, reason: '', busy: false, error: null as unknown, done: false });

function left(row: Payment): bigint {
  return BigInt(row.total_minor ?? '0') - BigInt(row.refunded_minor ?? '0');
}

function startRefund(row: Payment, full = false): void {
  const digits = left(row).toString().padStart(row.currency_exponent + 1, '0');
  const amount = row.currency_exponent === 0 ? digits : `${digits.slice(0, -row.currency_exponent)}.${digits.slice(-row.currency_exponent)}`;
  Object.assign(refund, { payment: row, amount, full, reason: '', error: null, done: false });
}

function refundMinor(): string | null {
  const minor = refund.payment === null ? null : parseMinor(refund.amount, refund.payment.currency_exponent);

  return minor === '0' ? null : minor;
}

async function submitRefund(): Promise<void> {
  const row = refund.payment;
  const minor = refundMinor();

  if (row === null || minor === null || refund.reason.trim().length < 3) return;
  refund.busy = true;
  refund.error = null;

  try {
    await requestRefund(row.id, { amount_minor: minor, reason: refund.reason.trim(), cancel_subscription: true });
    refund.payment = null;
    refund.done = true;
    await refundsPanel.value?.load();
  } catch (caught) {
    refund.error = caught;
  } finally {
    refund.busy = false;
  }
}

function money(minor: string | null, currency: string, exponent: number): string {
  return minor === null ? '—' : (formatMinor(minor, currency, exponent, locale.value) ?? `${minor} ${currency}`);
}

async function load(more = false): Promise<void> {
  error.value = null;

  try {
    const query: Record<string, string> = status.value === '' ? {} : { status: status.value };
    if (tenantId) query.tenant_id = tenantId;
    const page = await fetchPayments(more && cursor.value ? { ...query, cursor: cursor.value } : query);
    rows.value = more ? [...rows.value, ...page.data] : page.data;
    month.value = page.month;
    mode.value = page.mode;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function apply(): Promise<void> {
  await router.replace({ query: { ...(tenantId ? { tenant_id: tenantId } : {}), ...(status.value === '' ? {} : { status: status.value }) } });
  await load();
}

onMounted(() => load());
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.payments.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('payments.hint') }}</p>
      </div>

      <ErrorNotice v-if="error" :error="error" />

      <section class="flex flex-col gap-3" aria-labelledby="month-title" data-panel="month">
        <h2 id="month-title" class="text-lg font-semibold">{{ $t('payments.month') }} <span v-if="mode === 'test'" class="text-sm font-normal text-warn">· {{ $t('payments.test_mode') }}</span></h2>
        <p v-if="!loading && month.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('payments.month_empty') }}</p>
        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <article v-for="row in month" :key="row.currency" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4 text-sm" :data-month="row.currency">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">{{ row.currency }} · {{ $t('payments.count', { n: row.payments }) }}</p>
            <p class="text-2xl font-semibold tabular-nums">{{ money(row.total_minor, row.currency, row.currency_exponent) }}</p>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
              <dt class="text-text-muted">{{ $t('payments.tax') }}</dt><dd class="text-right tabular-nums">{{ money(row.tax_minor, row.currency, row.currency_exponent) }}</dd>
              <dt class="text-text-muted">{{ $t('payments.fee') }}</dt><dd class="text-right tabular-nums">{{ row.fee_minor === null ? $t('payments.unknown') : money(row.fee_minor, row.currency, row.currency_exponent) }}</dd>
              <dt class="text-text-muted">{{ $t('payments.earnings') }}</dt><dd class="text-right font-medium tabular-nums">{{ row.earnings_minor === null ? $t('payments.unknown') : money(row.earnings_minor, row.currency, row.currency_exponent) }}</dd>
              <dt class="text-text-muted">{{ $t('payments.refunded') }}</dt><dd class="text-right tabular-nums">{{ money(row.refunded_minor ?? '0', row.currency, row.currency_exponent) }}</dd>
            </dl>
          </article>
        </div>
      </section>

      <form class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-4 text-sm" data-form="payment-filters" @submit.prevent="apply">
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('payments.status_label') }}</span>
          <select v-model="status" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="status">
            <option value="">{{ $t('tenants.filter.any') }}</option>
            <option v-for="option in STATUSES" :key="option" :value="option">{{ $t(`payments.status.${option}`) }}</option>
          </select>
        </label>
        <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover" data-action="filter">{{ $t('tenants.apply') }}</button>
      </form>

      <p v-if="refund.done" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-refund="requested">{{ $t('refunds.requested_done') }}</p>
      <form v-if="refund.payment" class="flex flex-col gap-3 rounded-xl border border-note-border bg-note-soft p-4 text-sm" data-form="refund" @submit.prevent="submitRefund">
        <p class="font-medium">{{ $t('refunds.form_title', { payment: refund.payment.invoice_number ?? refund.payment.provider_transaction_ref, organisation: refund.payment.tenant?.name ?? '' }) }}</p>
        <label class="flex max-w-xs flex-col gap-1.5">
          <span class="font-medium">{{ $t('refunds.amount', { currency: refund.payment.currency }) }}</span>
          <input v-model="refund.amount" inputmode="decimal" :readonly="refund.full" class="rounded-md border border-border-strong bg-surface px-3 py-2 tabular-nums read-only:bg-surface-muted" data-field="refund-amount" />
          <span class="text-[13px] text-text-muted">{{ $t('refunds.left', { amount: money(left(refund.payment).toString(), refund.payment.currency, refund.payment.currency_exponent) }) }}</span>
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('refunds.reason') }}</span>
          <textarea v-model="refund.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="refund-reason" />
        </label>
        <p class="text-crit">{{ $t('refunds.cancel_subscription') }}</p>
        <p class="text-text-muted">{{ $t('refunds.form_hint') }}</p>
        <ErrorNotice v-if="refund.error" :error="refund.error" />
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="refund.busy || refundMinor() === null || refund.reason.trim().length < 3" data-action="submit-refund">{{ $t('refunds.submit') }}</button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="refund.payment = null">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <p v-else-if="rows.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('payments.empty') }}</p>
      <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[56rem] text-sm">
          <thead class="border-b border-border text-left text-text-muted">
            <tr>
              <th class="px-4 py-3 font-medium">{{ $t('payments.date') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('tenants.col.organisation') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ $t('payments.amount') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ $t('payments.fee') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('payments.status_label') }}</th>
              <th class="px-4 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="row in rows" :key="row.id" :data-payment="row.provider_transaction_ref">
              <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(row.billed_at) }}<span class="block font-mono text-[13px] text-text-muted">{{ row.invoice_number ?? row.provider_transaction_ref }}</span></td>
              <td class="px-4 py-3">
                <RouterLink v-if="row.tenant" :to="{ name: 'owner-tenant', params: { id: row.tenant.id }, query: { tab: 'subscription' } }" class="font-medium text-primary hover:underline">{{ row.tenant.name }}</RouterLink>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                {{ money(row.total_minor, row.currency, row.currency_exponent) }}
                <span v-if="row.tax_minor && row.tax_minor !== '0'" class="block text-[13px] text-text-muted">{{ $t('payments.tax_short', { amount: money(row.tax_minor, row.currency, row.currency_exponent) }) }}</span>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ money(row.fee_minor, row.currency, row.currency_exponent) }}</td>
              <td class="px-4 py-3">
                <StatusBadge :tone="PAYMENT_TONES[row.status]" :label="$t(`payments.status.${row.status}`)" />
                <span v-if="row.refunded_minor && row.refunded_minor !== '0'" class="block text-[13px] text-warn">{{ $t('payments.refunded_short', { amount: money(row.refunded_minor, row.currency, row.currency_exponent) }) }}</span>
              </td>
              <td class="px-4 py-3 text-right">
                <div v-if="can('refunds.request') && row.status === 'completed' && left(row) > 0n" class="flex flex-wrap justify-end gap-2">
                  <button type="button" class="rounded-md border border-border-strong px-3 py-1 text-sm font-medium text-primary hover:bg-primary-soft" data-action="refund" @click="startRefund(row)">{{ $t('refunds.action') }}</button>
                  <button v-if="left(row) === BigInt(row.total_minor ?? '0')" type="button" class="rounded-md border border-crit-border px-3 py-1 text-sm font-medium text-crit hover:bg-crit-soft" data-action="cancel-and-refund" @click="startRefund(row, true)">{{ $t('refunds.cancel_and_refund') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="more" @click="load(true)">{{ $t('tenants.more') }}</button>

      <RefundsPanel ref="refundsPanel" @changed="load()" />
    </div>
  </OwnerLayout>
</template>
