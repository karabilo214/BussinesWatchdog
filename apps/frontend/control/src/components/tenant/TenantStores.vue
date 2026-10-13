<script setup lang="ts">
import { StatusBadge } from '@bw/ui';
import type { TenantCard } from '@/api';
import { useFormat } from '@/composables/useFormat';

defineProps<{ card: TenantCard }>();
const { dateTime } = useFormat();
</script>

<template>
  <section class="flex flex-col gap-3" data-section="stores">
    <p class="text-sm text-text-muted">{{ $t('tenant.stores_hint') }}</p>
    <p v-if="card.stores.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('tenant.no_stores') }}</p>
    <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
      <table class="w-full min-w-[40rem] text-sm">
        <thead class="border-b border-border text-left text-text-muted">
          <tr>
            <th class="px-4 py-3 font-medium">{{ $t('tenant.store.name') }}</th>
            <th class="px-4 py-3 font-medium">{{ $t('tenant.store.platform') }}</th>
            <th class="px-4 py-3 font-medium">{{ $t('tenant.store.state') }}</th>
            <th class="px-4 py-3 font-medium">{{ $t('tenant.store.orders') }}</th>
            <th class="px-4 py-3 font-medium">{{ $t('tenants.col.incidents') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="store in card.stores" :key="store.id" :data-store="store.host">
            <td class="px-4 py-3"><span class="font-medium">{{ store.name }}</span><span class="block text-[13px] text-text-muted">{{ store.host }}</span></td>
            <td class="px-4 py-3">{{ store.platform }}</td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1.5">
                <StatusBadge :tone="store.status === 'active' ? 'ok' : 'unknown'" :label="$t(`store_status.${store.status}`, store.status)" />
                <StatusBadge :tone="store.verified ? 'ok' : 'warn'" :label="store.verified ? $t('tenant.store.verified') : $t('tenant.store.unverified')" />
                <StatusBadge v-if="store.capacity_paused_at" tone="unknown" :label="$t('tenant.store.capacity_paused')" />
              </div>
            </td>
            <td class="px-4 py-3" data-orders>
              <span class="tabular-nums">{{ store.orders_this_month }}</span>
              <span class="block text-[13px] text-text-muted">{{ $t('tenant.store.orders_average', { average: store.orders_average ?? '—', limit: card.orders_limit ?? '∞' }) }}</span>
              <StatusBadge v-if="store.orders_over_since" tone="warn" :label="$t('tenant.store.orders_over', { date: dateTime(store.orders_over_since) })" />
            </td>
            <td class="px-4 py-3 tabular-nums" :class="store.open_incidents > 0 ? 'text-crit' : 'text-text-muted'">{{ store.open_incidents }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
