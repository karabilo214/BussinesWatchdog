<script setup lang="ts">
import { StatusBadge } from '@bw/ui';
import type { TenantCard } from '@/api';
import { useFormat } from '@/composables/useFormat';

defineProps<{ card: TenantCard }>();
const { dateTime } = useFormat();
</script>

<template>
  <section class="flex flex-col gap-2" data-section="audit">
    <p v-if="card.audit.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('tenant.audit_empty') }}</p>
    <ul v-else class="flex flex-col gap-2 text-sm">
      <li v-for="entry in card.audit" :key="entry.id" class="flex flex-col gap-1 rounded-lg border border-border bg-surface px-4 py-3" :data-audit="entry.action">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ $t(`audit_action.${entry.action}`, entry.action) }}</span>
          <StatusBadge :tone="entry.result === 'succeeded' ? 'ok' : 'crit'" :label="$t(`audit_result.${entry.result}`, entry.result)" />
          <span class="text-text-muted">{{ entry.actor ?? '—' }} · {{ dateTime(entry.created_at) }}</span>
        </div>
        <p v-if="entry.reason" class="text-text-muted">{{ entry.reason }}</p>
      </li>
    </ul>
  </section>
</template>
