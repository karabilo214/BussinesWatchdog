<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { recheckMoney } from '@/api/incidents';
import { allocateRefund, getOrder, revokePaymentAllocation, revokeRefundAllocation } from '@/api/reconciliation';
import { getStore } from '@/api/stores';
import type { FinancialTransaction, OrderDetail, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ReasonAction from '@/components/ReasonAction.vue';
import { useIncidentText } from '@/components/incidents/useIncidentText';
import FindingsTable from '@/components/reconciliation/FindingsTable.vue';
import { linkableRefundAmount } from '@/components/reconciliation/linking';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const props = defineProps<{ id: string }>();
const { canManageStores, canHandleIncidents } = useRole();
const { dateTime } = useFormat();
const { amount } = useIncidentText();

const order = ref<OrderDetail | null>(null);
const store = ref<Store | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const rechecking = ref(false);
const recheckError = ref<unknown>(null);
const recheckDone = ref(false);
const refundForm = reactive({ open: false, refund: '', transaction: '', allocation: '', reason: '' });
const refundBusy = ref(false);
const refundError = ref<unknown>(null);

function money(minor: string | null | undefined, currency: string | null | undefined): string {
  if (minor === null || minor === undefined || currency === null || currency === undefined || order.value === null) {
    return '—';
  }

  const exponent = currency === order.value.currency ? order.value.currency_exponent : null;

  return amount(minor, currency, exponent) ?? '—';
}

function transactionMoney(transaction: FinancialTransaction): string {
  return amount(transaction.amount_minor, transaction.currency, transaction.currency_exponent) ?? '—';
}

/** G/C/RW/RP of the latest run, taken from the first finding that carries them. */
const totals = computed(() => {
  const finding = order.value?.findings.find((item) => item.gross_minor !== undefined && item.gross_minor !== null) ?? null;

  return {
    gross: finding?.gross_minor ?? null,
    captured: finding?.captured_minor ?? null,
    refundExpected: finding?.refund_expected_minor ?? null,
    refundActual: finding?.refund_actual_minor ?? null,
    currency: finding?.currency ?? order.value?.currency ?? null,
  };
});

const transactions = computed(() => new Map([...(order.value?.captures ?? []), ...(order.value?.refund_transactions ?? [])].map((item) => [item.id, item])));
const activeAllocations = computed(() => (order.value?.allocations ?? []).filter((item) => item.revoked_at === null));
const linkableRefunds = computed(() => (order.value?.refunds ?? []).filter((item) => item.status === 'recorded' || item.status === 'requested'));
const linkableTransactions = computed(() => (order.value?.refund_transactions ?? []).filter((item) => item.status === 'succeeded'));
const canLinkRefund = computed(() => canManageStores.value && activeAllocations.value.length > 0 && linkableRefunds.value.length > 0 && linkableTransactions.value.length > 0);

const refundAmount = computed(() => (order.value === null ? null : linkableRefundAmount(order.value, refundForm.refund, refundForm.transaction)));

async function load(): Promise<void> {
  error.value = null;

  try {
    order.value = await getOrder(props.id);

    if (store.value?.id !== order.value.store_id) {
      store.value = await getStore(order.value.store_id).catch(() => null);
    }
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function recheck(): Promise<void> {
  if (order.value === null) return;

  rechecking.value = true;
  recheckError.value = null;
  recheckDone.value = false;

  try {
    await recheckMoney(order.value.store_id, [order.value.id]);
    recheckDone.value = true;
    await load();
  } catch (caught) {
    recheckError.value = caught;
  } finally {
    rechecking.value = false;
  }
}

function openRefundForm(): void {
  refundForm.open = true;
  refundForm.refund = linkableRefunds.value[0]?.id ?? '';
  refundForm.transaction = linkableTransactions.value[0]?.id ?? '';
  refundForm.allocation = activeAllocations.value[0]?.id ?? '';
  refundForm.reason = '';
  refundError.value = null;
}

async function linkRefund(): Promise<void> {
  const transaction = linkableTransactions.value.find((item) => item.id === refundForm.transaction);

  if (refundAmount.value === null || transaction === undefined) return;

  refundBusy.value = true;
  refundError.value = null;

  try {
    await allocateRefund({
      refund_id: refundForm.refund,
      refund_transaction_id: refundForm.transaction,
      payment_allocation_id: refundForm.allocation,
      amount_minor: refundAmount.value,
      currency: transaction.currency,
      reason: refundForm.reason.trim(),
    });
    refundForm.open = false;
    await load();
  } catch (caught) {
    refundError.value = caught;
  } finally {
    refundBusy.value = false;
  }
}

watch(
  () => props.id,
  () => {
    loading.value = true;
    order.value = null;
    void load();
  },
  { immediate: true },
);
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <RouterLink :to="{ name: 'reconciliation', query: order ? { store: order.store_id } : {} }" class="text-sm text-primary hover:underline">← {{ $t('nav.reconciliation') }}</RouterLink>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <ErrorNotice v-else-if="error && !order" :error="error">
        <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load">{{ $t('common.retry') }}</button>
      </ErrorNotice>

      <template v-else-if="order">
        <header class="flex flex-col gap-2">
          <h1 class="text-2xl font-semibold">{{ $t('order.title', { number: order.display_number ?? order.external_id }) }}</h1>
          <p class="flex flex-wrap items-center gap-2 text-sm text-text-muted">
            <RouterLink v-if="store" :to="{ name: 'store', params: { id: store.id } }" class="hover:text-primary hover:underline">{{ store.name }}</RouterLink>
            <span v-if="store" aria-hidden="true">·</span>
            <span>{{ $t('order.status', { status: order.status }) }}</span>
            <StatusBadge v-if="order.mode === 'test'" tone="note" :label="$t('order.test_mode')" />
            <StatusBadge v-if="order.is_synthetic" tone="note" :label="$t('order.synthetic')" />
          </p>
        </header>

        <section class="grid gap-4 rounded-xl border border-border bg-surface p-5 sm:grid-cols-2 lg:grid-cols-4" :aria-label="$t('order.money_title')" data-panel="order-money">
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('order.gross') }}</span>
            <span class="font-mono text-sm font-medium">{{ money(order.total_minor, order.currency) }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('order.captured') }}</span>
            <span class="font-mono text-sm font-medium" data-captured>{{ totals.captured === null ? $t('incident.evidence.unknown') : money(totals.captured, totals.currency) }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('order.refund_expected') }}</span>
            <span class="font-mono text-sm font-medium">{{ totals.refundExpected === null ? $t('incident.evidence.unknown') : money(totals.refundExpected, totals.currency) }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('order.refund_actual') }}</span>
            <span class="font-mono text-sm font-medium">{{ totals.refundActual === null ? $t('incident.evidence.unknown') : money(totals.refundActual, totals.currency) }}</span>
          </div>
          <dl class="grid gap-x-6 gap-y-1 border-t border-border pt-3 text-[13px] sm:col-span-2 sm:grid-cols-2 lg:col-span-4">
            <div class="flex gap-2"><dt class="text-text-muted">{{ $t('order.paid_marked') }}</dt><dd>{{ order.paid_marked_at ? dateTime(order.paid_marked_at) : $t('order.not_marked') }}</dd></div>
            <div class="flex gap-2"><dt class="text-text-muted">{{ $t('order.gateway') }}</dt><dd>{{ order.gateway ?? '—' }}</dd></div>
            <div class="flex gap-2"><dt class="text-text-muted">{{ $t('order.transaction_ref') }}</dt><dd class="break-all font-mono">{{ order.transaction_ref ?? '—' }}</dd></div>
            <div class="flex gap-2"><dt class="text-text-muted">{{ $t('order.support') }}</dt><dd>{{ $t(`order.financial_support.${order.financial_support}`) }}</dd></div>
          </dl>
          <p class="text-[13px] text-text-muted sm:col-span-2 lg:col-span-4">{{ $t('order.paid_marker_note') }}</p>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="order-findings">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="order-findings" class="text-lg font-semibold">{{ $t('order.findings_title') }}</h2>
            <button
              v-if="canHandleIncidents"
              type="button"
              class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
              :disabled="rechecking"
              @click="recheck"
            >
              {{ $t('order.recheck') }}
            </button>
          </div>
          <p v-if="recheckDone" class="text-sm text-ok" role="status">{{ $t('incident.actions.recheck_money_done') }}</p>
          <ErrorNotice v-if="recheckError" :error="recheckError" />
          <FindingsTable v-if="order.findings.length > 0" :findings="order.findings" :show-order="false" />
          <p v-else class="text-sm text-text-muted">{{ $t('order.no_findings') }}</p>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="order-captures" data-panel="order-captures">
          <h2 id="order-captures" class="text-lg font-semibold">{{ $t('order.captures_title') }}</h2>
          <p v-if="order.allocations.length === 0" class="text-sm text-text-muted">{{ $t('order.no_allocations') }}</p>
          <ul v-else class="flex flex-col gap-3">
            <li v-for="allocation in order.allocations" :key="allocation.id" class="flex flex-col gap-2 rounded-lg border border-border p-3 text-sm" :data-allocation-id="allocation.id">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-mono font-medium">{{ money(allocation.amount_minor, allocation.currency) }}</span>
                <StatusBadge v-if="allocation.revoked_at" tone="unknown" :label="$t('order.revoked', { time: dateTime(allocation.revoked_at) })" />
                <StatusBadge v-else tone="ok" :label="$t(`order.strategy.${allocation.strategy}`)" />
              </div>
              <p class="break-all font-mono text-[13px] text-text-muted">{{ transactions.get(allocation.capture_transaction_id)?.external_operation_id ?? allocation.capture_transaction_id }}</p>
              <p class="text-[13px] text-text-muted">{{ $t('order.linked_at', { time: dateTime(allocation.created_at) }) }}</p>
              <ReasonAction
                v-if="canManageStores && allocation.revoked_at === null"
                :id="`revoke-${allocation.id}`"
                :label="$t('order.revoke')"
                :confirm-label="$t('order.revoke_confirm')"
                :warning="$t('order.revoke_capture_warning')"
                :action="(reason: string) => revokePaymentAllocation(allocation.id, reason)"
                danger
                @done="load"
              />
            </li>
          </ul>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="order-refunds" data-panel="order-refunds">
          <h2 id="order-refunds" class="text-lg font-semibold">{{ $t('order.refunds_title') }}</h2>
          <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
              <h3 class="text-sm font-semibold">{{ $t('order.store_refunds') }}</h3>
              <p v-if="order.refunds.length === 0" class="text-sm text-text-muted">{{ $t('order.none') }}</p>
              <ul v-else class="flex flex-col divide-y divide-border rounded-lg border border-border text-sm">
                <li v-for="refund in order.refunds" :key="refund.id" class="flex flex-wrap items-center justify-between gap-2 p-3">
                  <span class="font-mono">{{ amount(refund.amount_minor, refund.currency, refund.currency_exponent) }}</span>
                  <span class="text-[13px] text-text-muted">{{ $t(`order.refund_status.${refund.status}`) }} · {{ dateTime(refund.occurred_at) }}</span>
                </li>
              </ul>
            </div>
            <div class="flex flex-col gap-2">
              <h3 class="text-sm font-semibold">{{ $t('order.provider_refunds') }}</h3>
              <p v-if="order.refund_transactions.length === 0" class="text-sm text-text-muted">{{ $t('order.none') }}</p>
              <ul v-else class="flex flex-col divide-y divide-border rounded-lg border border-border text-sm">
                <li v-for="transaction in order.refund_transactions" :key="transaction.id" class="flex flex-col gap-0.5 p-3">
                  <span class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-mono">{{ transactionMoney(transaction) }}</span>
                    <span class="text-[13px] text-text-muted">{{ $t(`order.transaction_status.${transaction.status}`) }} · {{ dateTime(transaction.occurred_at) }}</span>
                  </span>
                  <span class="break-all font-mono text-[13px] text-text-muted">{{ transaction.external_operation_id }}</span>
                </li>
              </ul>
            </div>
          </div>

          <div v-if="order.refund_allocations.length > 0" class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">{{ $t('order.refund_links') }}</h3>
            <ul class="flex flex-col gap-3">
              <li v-for="link in order.refund_allocations" :key="link.id" class="flex flex-col gap-2 rounded-lg border border-border p-3 text-sm" :data-refund-allocation-id="link.id">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-mono font-medium">{{ money(link.amount_minor, link.currency) }}</span>
                  <StatusBadge v-if="link.revoked_at" tone="unknown" :label="$t('order.revoked', { time: dateTime(link.revoked_at) })" />
                  <StatusBadge v-else tone="ok" :label="$t(`order.strategy.${link.strategy}`)" />
                </div>
                <p class="break-all font-mono text-[13px] text-text-muted">{{ transactions.get(link.refund_transaction_id)?.external_operation_id ?? link.refund_transaction_id }}</p>
                <ReasonAction
                  v-if="canManageStores && link.revoked_at === null"
                  :id="`revoke-refund-${link.id}`"
                  :label="$t('order.revoke')"
                  :confirm-label="$t('order.revoke_confirm')"
                  :warning="$t('order.revoke_refund_warning')"
                  :action="(reason: string) => revokeRefundAllocation(link.id, reason)"
                  danger
                  @done="load"
                />
              </li>
            </ul>
          </div>

          <div v-if="canLinkRefund">
            <button
              v-if="!refundForm.open"
              type="button"
              class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft"
              @click="openRefundForm"
            >
              {{ $t('order.link_refund') }}
            </button>
            <form v-else class="flex flex-col gap-3 rounded-lg border border-warn-border bg-warn-soft p-4" @submit.prevent="linkRefund">
              <p class="text-sm">{{ $t('order.link_refund_warning') }}</p>
              <label class="flex flex-col gap-1 text-sm font-medium">
                {{ $t('order.store_refunds') }}
                <select v-model="refundForm.refund" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-normal">
                  <option v-for="refund in linkableRefunds" :key="refund.id" :value="refund.id">{{ amount(refund.amount_minor, refund.currency, refund.currency_exponent) }} · {{ dateTime(refund.occurred_at) }}</option>
                </select>
              </label>
              <label class="flex flex-col gap-1 text-sm font-medium">
                {{ $t('order.provider_refunds') }}
                <select v-model="refundForm.transaction" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-normal">
                  <option v-for="transaction in linkableTransactions" :key="transaction.id" :value="transaction.id">{{ transactionMoney(transaction) }} · {{ transaction.external_operation_id }}</option>
                </select>
              </label>
              <label v-if="activeAllocations.length > 1" class="flex flex-col gap-1 text-sm font-medium">
                {{ $t('order.captures_title') }}
                <select v-model="refundForm.allocation" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-normal">
                  <option v-for="allocation in activeAllocations" :key="allocation.id" :value="allocation.id">{{ money(allocation.amount_minor, allocation.currency) }} · {{ transactions.get(allocation.capture_transaction_id)?.external_operation_id ?? allocation.id }}</option>
                </select>
              </label>
              <p class="text-sm" data-refund-amount>
                {{ refundAmount === null ? $t('order.nothing_to_link') : $t('order.link_amount', { amount: money(refundAmount, linkableTransactions.find((item) => item.id === refundForm.transaction)?.currency) }) }}
              </p>
              <label for="refund-link-reason" class="text-sm font-medium">{{ $t('reconciliation.reason') }}</label>
              <textarea id="refund-link-reason" v-model="refundForm.reason" rows="2" maxlength="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm"></textarea>
              <ErrorNotice v-if="refundError" :error="refundError" />
              <div class="flex flex-wrap gap-2">
                <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="refundBusy || refundAmount === null || refundForm.reason.trim().length < 5">
                  {{ $t('order.link_refund_confirm') }}
                </button>
                <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium" @click="refundForm.open = false">{{ $t('common.cancel') }}</button>
              </div>
            </form>
          </div>
        </section>

        <section v-if="order.revisions.length > 0" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5" aria-labelledby="order-revisions">
          <h2 id="order-revisions" class="text-lg font-semibold">{{ $t('order.revisions_title') }}</h2>
          <ol class="flex flex-col divide-y divide-border text-sm">
            <li v-for="revision in [...order.revisions].reverse()" :key="revision.source_revision" class="flex flex-wrap justify-between gap-2 py-2">
              <span>{{ $t('order.revision', { n: revision.source_revision }) }} · {{ revision.status ?? '—' }}</span>
              <span class="text-text-muted"><span class="font-mono">{{ revision.total_minor === null ? '—' : money(revision.total_minor, order.currency) }}</span> · {{ dateTime(revision.observed_at) }}</span>
            </li>
          </ol>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
