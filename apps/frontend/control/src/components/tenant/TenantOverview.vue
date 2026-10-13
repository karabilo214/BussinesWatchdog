<script setup lang="ts">
import type { TenantCard } from '@/api';
import TenantActions from '@/components/TenantActions.vue';
import { useFormat } from '@/composables/useFormat';

defineProps<{ card: TenantCard; canManage: boolean }>();
const emit = defineEmits<{ updated: [card: TenantCard] }>();
const { dateTime } = useFormat();

function limit(value: number | null | undefined): string {
  return value === null || value === undefined ? '∞' : String(value);
}
</script>

<template>
  <section class="grid gap-4 md:grid-cols-2" data-section="overview">
    <dl class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 text-sm">
      <div><dt class="text-text-muted">{{ $t('tenant.access_reasons') }}</dt><dd>{{ card.access.reasons.map((reason) => $t(`reason.${reason}`, reason)).join(', ') || '—' }}</dd></div>
      <div><dt class="text-text-muted">{{ $t('tenant.created') }}</dt><dd>{{ dateTime(card.created_at) }}</dd></div>
      <div><dt class="text-text-muted">{{ $t('tenant.locale') }}</dt><dd>{{ card.locale }} · {{ card.timezone }}</dd></div>
      <div><dt class="text-text-muted">{{ $t('tenant.trial_used') }}</dt><dd>{{ card.trial_consumed_at ? dateTime(card.trial_consumed_at) : $t('tenant.no') }}</dd></div>
      <div>
        <dt class="text-text-muted">{{ $t('tenant.capacity') }}</dt>
        <dd>{{ $t('tenant.capacity_value', { stores: card.capacity.stores, limit: limit(card.capacity.limit), paused: card.capacity.paused }) }}</dd>
        <dd v-if="card.capacity.choice_required" class="text-crit">{{ $t('tenants.capacity_choice') }}</dd>
      </div>
      <div v-if="!card.access.enforced"><dd class="text-note">{{ $t('tenant.pilot') }}</dd></div>
    </dl>
    <TenantActions v-if="canManage" :card="card" @updated="emit('updated', $event)" />
  </section>
</template>
