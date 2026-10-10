<script setup lang="ts">
import type { Finding } from '@/api/types';
import { useIncidentText } from '@/components/incidents/useIncidentText';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import { FINDING_TONES } from './tones';

withDefaults(defineProps<{ findings: Finding[]; showOrder?: boolean; showStatus?: boolean }>(), { showOrder: true, showStatus: true });
const { dateTime } = useFormat();
const { amount } = useIncidentText();
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
            <span class="text-[13px] text-text-muted">{{ dateTime(finding.evaluated_at) }}</span>
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
          <td class="py-2 pr-4 text-right font-mono whitespace-nowrap">{{ amount(finding.expected_minor, finding.currency, finding.currency_exponent) ?? $t('incident.evidence.unknown') }}</td>
          <td class="py-2 pr-4 text-right font-mono whitespace-nowrap">{{ amount(finding.actual_minor, finding.currency, finding.currency_exponent) ?? $t('incident.evidence.unknown') }}</td>
          <td class="py-2 text-right font-mono whitespace-nowrap">{{ amount(finding.difference_minor, finding.currency, finding.currency_exponent) ?? $t('incident.evidence.unknown') }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
