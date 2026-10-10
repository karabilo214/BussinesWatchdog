<script setup lang="ts">
import { ref, watch } from 'vue';
import { listDeliveries } from '@/api/notifications';
import type { DeliveryStatus, NotificationChannel, NotificationDelivery } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import { DELIVERY_TONES } from './tones';

const STATUSES: DeliveryStatus[] = ['sent', 'queued', 'sending', 'uncertain', 'failed', 'dead_letter', 'suppressed'];

const props = defineProps<{ channels: NotificationChannel[]; refreshKey: number }>();
const { dateTime } = useFormat();

const items = ref<NotificationDelivery[]>([]);
const nextCursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const channelId = ref('');
const status = ref<DeliveryStatus | ''>('');
let request = 0;

function channelLabel(id: string): string {
  return props.channels.find((channel) => channel.id === id)?.label ?? '—';
}

async function load(more = false): Promise<void> {
  const current = ++request;

  if (!more) loading.value = true;

  error.value = null;

  try {
    const page = await listDeliveries({ channel_id: channelId.value || undefined, status: status.value || undefined, cursor: more ? (nextCursor.value ?? undefined) : undefined });

    if (current !== request) return;

    items.value = more ? [...items.value, ...page.data] : page.data;
    nextCursor.value = page.next_cursor;
  } catch (caught) {
    if (current === request) error.value = caught;
  } finally {
    if (current === request) loading.value = false;
  }
}

watch([channelId, status, () => props.refreshKey], () => load(), { immediate: true });
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="deliveries-title" data-panel="deliveries">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h2 id="deliveries-title" class="text-lg font-semibold">{{ $t('notifications.log.title') }}</h2>
        <p class="max-w-3xl text-sm text-text-muted">{{ $t('notifications.log.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-3">
        <label v-if="channels.length > 0" class="flex flex-col gap-1 text-sm font-medium">
          {{ $t('notifications.log.channel') }}
          <select v-model="channelId" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal">
            <option value="">{{ $t('reconciliation.filter.all') }}</option>
            <option v-for="channel in channels" :key="channel.id" :value="channel.id">{{ channel.label }}</option>
          </select>
        </label>
        <label class="flex flex-col gap-1 text-sm font-medium">
          {{ $t('notifications.log.status') }}
          <select v-model="status" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal">
            <option value="">{{ $t('reconciliation.filter.all') }}</option>
            <option v-for="value in STATUSES" :key="value" :value="value">{{ $t(`notifications.log.state.${value}`) }}</option>
          </select>
        </label>
      </div>
    </div>

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="error" :error="error" />
    <p v-else-if="items.length === 0" class="text-sm text-text-muted">{{ $t('notifications.log.empty') }}</p>

    <ul v-else class="flex flex-col divide-y divide-border rounded-lg border border-border">
      <li v-for="delivery in items" :key="delivery.id" class="flex flex-col gap-1 p-3 text-sm" :data-delivery-id="delivery.id">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="font-medium">
            {{ $t(`notifications.log.kind.${delivery.notification_kind}`) }}
            <RouterLink v-if="delivery.incident_id" :to="{ name: 'incident', params: { id: delivery.incident_id } }" class="font-normal text-primary hover:underline">· {{ $t('notifications.log.open_incident') }}</RouterLink>
          </span>
          <StatusBadge :tone="DELIVERY_TONES[delivery.status]" :label="$t(`notifications.log.state.${delivery.status}`)" />
        </div>
        <span class="text-[13px] text-text-muted">
          {{ channelLabel(delivery.channel_id) }} · {{ dateTime(delivery.sent_at ?? delivery.created_at) }} · {{ $t('notifications.log.attempts', { n: delivery.attempts }) }}
          <template v-if="delivery.next_attempt_at"> · {{ $t('notifications.log.next_attempt', { time: dateTime(delivery.next_attempt_at) }) }}</template>
        </span>
        <span v-if="delivery.status === 'uncertain'" class="text-[13px]">{{ $t('notifications.log.uncertain_note') }}</span>
        <span v-if="delivery.error_code" class="text-[13px] text-text-muted">{{ $te(`notifications.log.error.${delivery.error_code}`) ? $t(`notifications.log.error.${delivery.error_code}`) : delivery.error_code }}</span>
      </li>
    </ul>

    <div v-if="nextCursor">
      <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" @click="load(true)">{{ $t('incidents.load_more') }}</button>
    </div>
  </section>
</template>
