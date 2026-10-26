<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Dispute } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { formatMinor, StatusBadge, type Tone } from '@bw/ui';

const props = defineProps<{ dispute: Dispute }>();
const { t, te, locale } = useI18n();
const { dateTime } = useFormat();

const amount = computed(() =>
  props.dispute.amount_minor === null ? null : formatMinor(props.dispute.amount_minor, props.dispute.currency, props.dispute.currency_exponent, locale.value),
);
const reason = computed(() => (props.dispute.reason && te(`dispute.reason.${props.dispute.reason}`) ? t(`dispute.reason.${props.dispute.reason}`) : t('dispute.reason.other')));
const tone = computed<Tone>(() => (props.dispute.open ? 'warn' : props.dispute.status === 'lost' ? 'crit' : 'ok'));
</script>

<template>
  <div class="flex flex-col gap-2 rounded-lg border border-border p-3 text-sm" :data-dispute-id="dispute.external_id">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <span class="font-medium">{{ $t(dispute.status.startsWith('warning_') ? 'dispute.inquiry' : 'dispute.title') }}</span>
      <StatusBadge :tone="tone" :label="$t(`dispute.status.${dispute.status}`)" />
    </div>
    <dl class="grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1">
      <dt class="text-text-muted">{{ $t('dispute.reason_label') }}</dt>
      <dd>{{ reason }}</dd>
      <dt class="text-text-muted">{{ $t('dispute.amount') }}</dt>
      <dd>
        <span v-if="amount" class="font-mono" data-dispute-amount>{{ amount }}</span>
        <span v-else>{{ $t('money_visibility.hidden_amount') }}</span>
      </dd>
      <dt class="text-text-muted">{{ $t('dispute.opened_at') }}</dt>
      <dd>{{ dateTime(dispute.opened_at) }}</dd>
      <template v-if="dispute.open && dispute.evidence_due_by">
        <dt class="text-text-muted">{{ $t('dispute.due_by') }}</dt>
        <dd class="font-medium" data-dispute-due>{{ dateTime(dispute.evidence_due_by) }}</dd>
      </template>
      <template v-if="dispute.closed_at">
        <dt class="text-text-muted">{{ $t('dispute.closed_at') }}</dt>
        <dd>{{ dateTime(dispute.closed_at) }}</dd>
      </template>
    </dl>
    <p class="break-all font-mono text-[13px] text-text-muted">{{ dispute.external_id }}</p>
    <p class="text-[13px] text-text-muted">{{ $t('dispute.note') }}</p>
  </div>
</template>
