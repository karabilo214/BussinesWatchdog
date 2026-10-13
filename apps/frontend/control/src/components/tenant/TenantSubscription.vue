<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { formatMinor, StatusBadge } from '@bw/ui';
import type { PlanChangeEntry, TenantCard } from '@/api';
import { PAYMENT_TONES } from '@/components/payments';
import { useFormat } from '@/composables/useFormat';

defineProps<{ billing: NonNullable<TenantCard['billing']> }>();
const { locale } = useI18n();
const { dateTime } = useFormat();

function changeMoney(change: PlanChangeEntry): string {
  return formatMinor(change.due_now_minor, change.currency, change.currency_exponent, locale.value) ?? `${change.due_now_minor} ${change.currency}`;
}
</script>

<template>
  <section class="flex flex-col gap-4" data-section="subscription">
    <p v-if="billing.mode === 'test'" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm text-warn">{{ $t('tenant.test_mode') }}</p>
    <dl v-if="billing.subscription" class="grid gap-x-6 gap-y-3 rounded-xl border border-border bg-surface p-5 text-sm sm:grid-cols-2" data-subscription>
      <div><dt class="text-text-muted">{{ $t('tenant.sub.plan') }}</dt><dd class="font-medium">{{ billing.subscription.plan_code }} <span v-if="billing.subscription.extra_store_quantity > 0">+ {{ $t('tenant.sub.extra', { n: billing.subscription.extra_store_quantity }) }}</span></dd></div>
      <div><dt class="text-text-muted">{{ $t('tenant.sub.provider') }}</dt><dd>{{ billing.subscription.provider }} <span v-if="billing.subscription.provider_subscription_ref" class="font-mono text-[13px]">{{ billing.subscription.provider_subscription_ref }}</span></dd></div>
      <div v-if="billing.subscription.paid_until"><dt class="text-text-muted">{{ $t('tenant.sub.paid_until') }}</dt><dd>{{ dateTime(billing.subscription.paid_until) }}</dd></div>
      <div v-if="billing.subscription.trial_ends_at"><dt class="text-text-muted">{{ $t('tenant.sub.trial_until') }}</dt><dd data-trial-ends>{{ dateTime(billing.subscription.trial_ends_at) }}</dd></div>
      <div v-if="billing.subscription.next_charge_at"><dt class="text-text-muted">{{ $t('tenant.sub.next_charge') }}</dt><dd>{{ dateTime(billing.subscription.next_charge_at) }}</dd></div>
      <div v-if="billing.subscription.past_due_since"><dt class="text-text-muted">{{ $t('tenant.sub.past_due_since') }}</dt><dd class="text-warn">{{ dateTime(billing.subscription.past_due_since) }}</dd></div>
      <div v-if="billing.subscription.cancel_at_period_end" class="sm:col-span-2"><dd class="text-warn" data-cancel-scheduled>{{ $t('tenant.sub.cancel_scheduled', { date: dateTime(billing.subscription.period_end) }) }}</dd></div>
      <div v-if="billing.subscription.scheduled_change" class="sm:col-span-2">
        <dd class="text-note">{{ $t('tenant.sub.scheduled_change', { plan: billing.subscription.scheduled_change.to.plan_code, date: dateTime(billing.subscription.scheduled_change.effective_at) }) }}</dd>
      </div>
    </dl>
    <p v-else class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('tenants.no_subscription') }}</p>

    <h2 class="text-base font-semibold">{{ $t('tenant.sub.contracts') }}</h2>
    <p v-if="billing.contracts.length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
    <ul v-else class="flex flex-col gap-2 text-sm">
      <li v-for="contract in billing.contracts" :key="contract.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border bg-surface px-4 py-3" :data-contract="contract.provider_subscription_ref">
        <span class="font-mono">{{ contract.provider_subscription_ref }}</span>
        <StatusBadge :tone="contract.state === 'active' ? 'ok' : contract.state === 'past_due' ? 'warn' : 'unknown'" :label="$t(`contract_state.${contract.state}`, contract.state)" />
        <span>{{ contract.plan_code ?? '—' }}</span>
        <span class="text-text-muted">{{ dateTime(contract.period_start) }} — {{ dateTime(contract.period_end) }}</span>
        <span class="text-text-muted">{{ contract.mode }}</span>
        <span v-if="contract.problem_code" class="text-crit">{{ contract.problem_code }}</span>
      </li>
    </ul>

    <h2 class="text-base font-semibold">{{ $t('tenant.sub.payments') }}</h2>
    <p v-if="(billing.transactions ?? []).length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
    <ul v-else class="flex flex-col gap-2 text-sm">
      <li v-for="payment in billing.transactions" :key="payment.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border bg-surface px-4 py-3" :data-payment="payment.provider_transaction_ref">
        <span>{{ dateTime(payment.billed_at) }}</span>
        <span class="font-medium tabular-nums">{{ formatMinor(payment.total_minor ?? '0', payment.currency, payment.currency_exponent, $i18n.locale) }}</span>
        <StatusBadge :tone="PAYMENT_TONES[payment.status]" :label="$t(`payments.status.${payment.status}`)" />
        <span class="font-mono text-[13px] text-text-muted">{{ payment.invoice_number ?? payment.provider_transaction_ref }}</span>
      </li>
    </ul>

    <h2 class="text-base font-semibold">{{ $t('tenant.sub.changes') }}</h2>
    <p v-if="billing.plan_changes.length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
    <ul v-else class="flex flex-col gap-2 text-sm">
      <li v-for="change in billing.plan_changes" :key="change.id" class="flex flex-wrap gap-x-3 rounded-lg border border-border bg-surface px-4 py-3">
        <span>{{ change.from.plan_code }} (+{{ change.from.extra_store_quantity }}) → {{ change.to.plan_code }} (+{{ change.to.extra_store_quantity }})</span>
        <span class="text-text-muted">{{ $t(`tenant.sub.change_status.${change.status}`, change.status) }}</span>
        <span v-if="change.kind === 'immediate'" class="tabular-nums">{{ $t('tenant.sub.charged', { amount: changeMoney(change) }) }}</span>
        <span class="text-text-muted">{{ dateTime(change.created_at ?? null) }}</span>
      </li>
    </ul>

    <h2 class="text-base font-semibold">{{ $t('tenant.sub.overrides') }}</h2>
    <p v-if="billing.overrides.length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
    <ul v-else class="flex flex-col gap-2 text-sm">
      <li v-for="override in billing.overrides" :key="override.id" class="flex flex-wrap items-center gap-x-3 rounded-lg border border-border bg-surface px-4 py-3" data-override>
        <StatusBadge :tone="override.active ? 'note' : 'unknown'" :label="override.active ? $t('tenant.sub.override_active') : override.revoked_at ? $t('tenant.sub.override_revoked') : $t('tenant.sub.override_ended')" />
        <span>{{ dateTime(override.starts_at) }} — {{ dateTime(override.ends_at) }}</span>
        <span class="text-text-muted">{{ override.reason }}</span>
      </li>
    </ul>
  </section>
</template>
