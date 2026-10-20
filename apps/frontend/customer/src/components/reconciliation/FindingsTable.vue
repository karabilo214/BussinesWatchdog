<script setup lang="ts">
import type { Finding } from '@/api/types';
import { useIncidentText } from '@/components/incidents/useIncidentText';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import { useI18n } from 'vue-i18n';
import { FINDING_TONES } from './tones';

withDefaults(defineProps<{ findings: Finding[]; showOrder?: boolean; showStatus?: boolean }>(), { showOrder: true, showStatus: true });
const { dateTime } = useFormat();
const { amount } = useIncidentText();
const { t } = useI18n();

/** Rules that compare facts, not sums: a missing amount there is "not applicable", not "unknown" (ADR 0071). */
const NO_AMOUNT_RULES = ['MONEY_PAYMENT_NOT_MATCHED', 'MONEY_MULTIPLE_CAPTURES', 'MONEY_CURRENCY_MISMATCH', 'MONEY_CANCELLED_PAID', 'MONEY_CAPTURE_NOT_RECORDED'];
const STORE_PAID_STATUSES = ['mismatch', 'pending', 'ok'];

function money(finding: Finding, minor: string | null): string {
  if (finding.money_amounts_hidden) {
    return t('money_visibility.hidden_amount');
  }

  const formatted = amount(minor, finding.currency, finding.currency_exponent);

  if (formatted !== null) {
    return formatted;
  }

  return NO_AMOUNT_RULES.includes(finding.rule_code) && finding.status !== 'unknown' && finding.status !== 'unsupported'
    ? t('incident.evidence.not_applicable')
    : t('incident.evidence.unknown');
}

function difference(finding: Finding): string {
  if (!finding.money_amounts_hidden && finding.rule_code === 'MONEY_CAPTURE_NOT_RECORDED' && STORE_PAID_STATUSES.includes(finding.status)) {
    return t(`incident.evidence.store_paid.${finding.status}`);
  }

  return money(finding, finding.difference_minor);
}

function operations(finding: Finding): string | null {
  const ids = finding.evidence?.capture_operation_ids;

  return Array.isArray(ids) && ids.length > 0 ? t('incident.evidence.provider_operations', { ids: ids.join(', ') }) : null;
}
</script>

<template>
  <div class="-mx-5 overflow-x-auto px-5">
    <table class="w-full min-w-[40rem] text-left text-sm">
      <caption class="sr-only">{{ $t('incident.evidence.findings') }}</caption>
      <thead class="text-[13px] text-text-muted">
        <tr class="border-b border-border">
          <th scope="col" class="py-2 pr-4 font-medium">{{ $t('incident.evidence.check') }}</th>
          <th v-if="showStatus" scope="col" class="py-2 pr-4 font-medium">{{ $t('reconciliation.status_column') }}</th>
          <th v-if="showOrder" scope="col" class="py-2 pr-4 font-medium">{{ $t('incident.evidence.order') }}</th>
          <th scope="col" class="py-2 pr-4 text-right font-medium">{{ $t('incident.evidence.expected') }}</th>
          <th scope="col" class="py-2 pr-4 text-right font-medium">{{ $t('incident.evidence.actual') }}</th>
          <th scope="col" class="py-2 text-right font-medium">{{ $t('incident.evidence.difference') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="finding in findings" :key="finding.id" class="border-b border-border align-top last:border-0" :data-finding-id="finding.id">
          <td class="py-2 pr-4">
            <span class="block">{{ $te(`reconciliation.rule.${finding.rule_code}`) ? $t(`reconciliation.rule.${finding.rule_code}`) : finding.rule_code }}</span>
            <span class="block text-[13px] text-text-muted">{{ dateTime(finding.evaluated_at) }}</span>
            <span v-if="operations(finding)" class="block font-mono text-[13px] text-text-muted" data-provider-operations>{{ operations(finding) }}</span>
          </td>
          <td v-if="showStatus" class="py-2 pr-4">
            <StatusBadge :tone="FINDING_TONES[finding.status]" :label="$t(`reconciliation.status.${finding.status}`)" />
          </td>
          <td v-if="showOrder" class="py-2 pr-4">
            <RouterLink v-if="finding.order_id" :to="{ name: 'order', params: { id: finding.order_id } }" class="text-primary hover:underline">
              {{ finding.order_display_number ?? $t('incident.evidence.order_unknown_number') }}
            </RouterLink>
            <span v-else class="text-text-muted">{{ $t('incident.evidence.no_order') }}</span>
          </td>
          <td class="py-2 pr-4 text-right font-mono whitespace-nowrap">{{ money(finding, finding.expected_minor) }}</td>
          <td class="py-2 pr-4 text-right font-mono whitespace-nowrap">{{ money(finding, finding.actual_minor) }}</td>
          <td class="py-2 text-right font-mono whitespace-nowrap">{{ difference(finding) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
