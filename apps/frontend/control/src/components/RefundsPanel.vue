<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMinor, StatusBadge, type Tone } from '@bw/ui';
import { approveRefund, fetchRefunds, rejectRefund, type Refund, type RefundStatus } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';

const emit = defineEmits<{ changed: [] }>();

const TONES: Record<RefundStatus, Tone> = { requested: 'warn', rejected: 'unknown', submitting: 'note', pending: 'note', refunded: 'ok', declined: 'crit', failed: 'crit', unknown: 'warn' };

const { locale } = useI18n();
const { dateTime } = useFormat();
const { can } = useStaff();
const refunds = ref<Refund[]>([]);
const error = ref<unknown>(null);
const busy = ref(false);
const decision = reactive({ id: '', action: '' as 'approve' | 'reject' | '', reason: '', code: '' });

function money(refund: Refund): string {
  return formatMinor(refund.amount_minor, refund.currency, refund.currency_exponent, locale.value) ?? `${refund.amount_minor} ${refund.currency}`;
}

async function load(): Promise<void> {
  try {
    refunds.value = await fetchRefunds();
  } catch (caught) {
    error.value = caught;
  }
}

function open(refund: Refund, action: 'approve' | 'reject'): void {
  Object.assign(decision, { id: refund.id, action, reason: '', code: '' });
  error.value = null;
}

async function decide(): Promise<void> {
  if (decision.reason.trim().length < 3 || (decision.action === 'approve' && decision.code.trim().length < 6)) return;
  busy.value = true;
  error.value = null;

  try {
    if (decision.action === 'approve') {
      await approveRefund(decision.id, { reason: decision.reason.trim(), confirmation_code: decision.code.trim() });
    } else {
      await rejectRefund(decision.id, decision.reason.trim());
    }

    decision.id = '';
    await load();
    emit('changed');
  } catch (caught) {
    error.value = caught;
    await load();
  } finally {
    busy.value = false;
  }
}

defineExpose({ load });
onMounted(load);
</script>

<template>
  <section class="flex flex-col gap-3" aria-labelledby="refunds-title" data-panel="refunds">
    <div class="flex flex-col gap-1">
      <h2 id="refunds-title" class="text-lg font-semibold">{{ $t('refunds.title') }}</h2>
      <p class="text-sm text-text-muted">{{ $t('refunds.hint') }}</p>
    </div>
    <ErrorNotice v-if="error" :error="error" />
    <p v-if="refunds.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('refunds.empty') }}</p>
    <ul v-else class="flex flex-col gap-2">
      <li v-for="refund in refunds" :key="refund.id" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4 text-sm" :data-refund="refund.id">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="font-semibold tabular-nums">{{ money(refund) }}</span>
          <StatusBadge :tone="TONES[refund.status]" :label="$t(`refunds.status.${refund.status}`)" />
          <RouterLink :to="{ name: 'owner-tenant', params: { id: refund.tenant.id }, query: { tab: 'subscription' } }" class="text-primary hover:underline">{{ refund.tenant.name }}</RouterLink>
          <span class="font-mono text-[13px] text-text-muted">{{ refund.invoice_number ?? refund.provider_transaction_ref }}</span>
          <span v-if="refund.cancel_subscription" class="text-warn">{{ $t('refunds.with_cancel') }}</span>
        </div>
        <p class="text-text-muted">{{ refund.reason }} · {{ $t('refunds.requested', { name: refund.requested_by ?? 'Paddle', date: dateTime(refund.created_at) }) }}<template v-if="refund.decided_by"> · {{ $t('refunds.decided', { name: refund.decided_by, date: dateTime(refund.decided_at) }) }}</template></p>
        <p v-if="refund.error_code" class="text-crit">{{ refund.error_code }}</p>
        <div v-if="refund.status === 'requested' && can('refunds.approve') && decision.id !== refund.id" class="flex flex-wrap gap-2">
          <button type="button" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover" data-action="approve-refund" @click="open(refund, 'approve')">{{ $t('refunds.approve') }}</button>
          <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" data-action="reject-refund" @click="open(refund, 'reject')">{{ $t('refunds.reject') }}</button>
        </div>
        <form v-if="decision.id === refund.id" class="flex flex-col gap-3 rounded-lg border border-note-border bg-note-soft p-3" :data-form="`refund-${decision.action}`" @submit.prevent="decide">
          <p class="font-medium">{{ $t(decision.action === 'approve' ? 'refunds.approve_hint' : 'refunds.reject_hint', { amount: money(refund) }) }}</p>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('refunds.decision_reason') }}</span>
            <textarea v-model="decision.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="decision-reason" />
          </label>
          <label v-if="decision.action === 'approve'" class="flex max-w-xs flex-col gap-1.5">
            <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
            <input v-model="decision.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono" data-field="decision-code" />
          </label>
          <div class="flex flex-wrap gap-2">
            <button type="submit" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || decision.reason.trim().length < 3 || (decision.action === 'approve' && decision.code.trim().length < 6)" data-action="confirm-decision">{{ $t(decision.action === 'approve' ? 'refunds.approve' : 'refunds.reject') }}</button>
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" @click="decision.id = ''">{{ $t('common.cancel') }}</button>
          </div>
        </form>
      </li>
    </ul>
  </section>
</template>
