<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { StatusBadge } from '@bw/ui';
import { fetchSubscriptions, type SubscriptionRow } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ACCESS_TONES, SUBSCRIPTION_TONES } from '@/components/tones';
import { useFormat } from '@/composables/useFormat';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const route = useRoute();
const router = useRouter();
const { dateTime } = useFormat();
const rows = ref<SubscriptionRow[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const filters = reactive({ status: typeof route.query.status === 'string' ? route.query.status : '', cancel_scheduled: route.query.cancel_scheduled === '1' ? '1' : '' });

async function load(more = false): Promise<void> {
  error.value = null;

  try {
    const query: Record<string, string> = {};
    if (filters.status) query.status = filters.status;
    if (filters.cancel_scheduled) query.cancel_scheduled = '1';
    const page = await fetchSubscriptions(more && cursor.value ? { ...query, cursor: cursor.value } : query);
    rows.value = more ? [...rows.value, ...page.data] : page.data;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function apply(): Promise<void> {
  await router.replace({ query: Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')) });
  await load();
}

onMounted(() => load());
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.subscriptions.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('subscriptions.hint') }}</p>
      </div>

      <form class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-4 text-sm" data-form="subscription-filters" @submit.prevent="apply">
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('tenants.filter.subscription') }}</span>
          <select v-model="filters.status" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="status">
            <option value="">{{ $t('tenants.filter.any') }}</option>
            <option v-for="status in (['trial', 'active', 'past_due', 'suspended', 'cancelled'] as const)" :key="status" :value="status">{{ $t(`subscription_status.${status}`) }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 py-2">
          <input v-model="filters.cancel_scheduled" type="checkbox" true-value="1" false-value="" class="size-4 accent-primary" data-field="cancel-scheduled" />
          {{ $t('tenants.filter.cancel_scheduled') }}
        </label>
        <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover" data-action="filter">{{ $t('tenants.apply') }}</button>
      </form>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <p v-else-if="rows.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('subscriptions.empty') }}</p>

      <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[52rem] text-sm">
          <thead class="border-b border-border text-left text-text-muted">
            <tr>
              <th class="px-4 py-3 font-medium">{{ $t('tenants.col.organisation') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('tenant.sub.plan') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('tenants.col.subscription') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('tenants.col.access') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('tenants.col.until') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('tenant.sub.next_charge') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="row in rows" :key="row.id" :data-subscription="row.tenant.name">
              <td class="px-4 py-3">
                <RouterLink :to="{ name: 'owner-tenant', params: { id: row.tenant.id }, query: { tab: 'subscription' } }" class="font-medium text-primary hover:underline">{{ row.tenant.name }}</RouterLink>
                <span v-if="row.provider_subscription_ref" class="block font-mono text-[13px] text-text-muted">{{ row.provider_subscription_ref }} · {{ row.mode }}</span>
              </td>
              <td class="px-4 py-3">{{ row.plan_code }}<span v-if="row.extra_store_quantity > 0" class="text-text-muted"> + {{ row.extra_store_quantity }}</span></td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-1.5">
                  <StatusBadge :tone="SUBSCRIPTION_TONES[row.status]" :label="$t(`subscription_status.${row.status}`)" />
                  <StatusBadge v-if="row.cancel_at_period_end" tone="warn" :label="$t('tenants.cancel_scheduled')" />
                </div>
              </td>
              <td class="px-4 py-3"><StatusBadge :tone="ACCESS_TONES[row.access.state]" :label="$t(`access.${row.access.state}`)" /></td>
              <td class="px-4 py-3 whitespace-nowrap">{{ row.paid_until ? dateTime(row.paid_until) : row.trial_ends_at ? dateTime(row.trial_ends_at) : '—' }}</td>
              <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(row.next_charge_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="more" @click="load(true)">{{ $t('tenants.more') }}</button>
    </div>
  </OwnerLayout>
</template>
