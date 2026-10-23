<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { StatusBadge } from '@bw/ui';
import { createCustomerStore, deleteCustomerStore, fetchTenant, type NewCustomerStore, type TenantCard } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ card: TenantCard; canManage?: boolean; canSupport?: boolean; canDelete?: boolean }>();
const emit = defineEmits<{ updated: [card: TenantCard] }>();
const { dateTime } = useFormat();

const adding = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);
const added = ref(false);
const deleting = ref<TenantCard['stores'][number] | null>(null);
const deleteForm = reactive({ name: '', reason: '', code: '' });
const deleteReady = computed(() => deleting.value !== null && deleteForm.name.trim() === deleting.value.name && deleteForm.reason.trim().length >= 3 && deleteForm.code.trim().length >= 6);
const form = reactive<NewCustomerStore>({ reason: '', name: '', base_url: 'https://', timezone: 'Europe/Berlin', default_currency: 'EUR', locale: 'de', platform: 'woocommerce' });
const ready = computed(() => form.name.trim() !== '' && /^https:\/\/[^/\s]+/.test(form.base_url.trim()) && /^[A-Z]{3}$/.test(form.default_currency) && form.timezone.trim() !== '' && form.reason.trim().length >= 3);

async function add(): Promise<void> {
  if (!ready.value || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    await createCustomerStore(props.card.id, { ...form, name: form.name.trim(), base_url: form.base_url.trim(), timezone: form.timezone.trim(), reason: form.reason.trim() });
    Object.assign(form, { reason: '', name: '', base_url: 'https://' });
    adding.value = false;
    added.value = true;
    emit('updated', await fetchTenant(props.card.id));
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

function openDelete(store: TenantCard['stores'][number]): void {
  deleting.value = store;
  Object.assign(deleteForm, { name: '', reason: '', code: '' });
  error.value = null;
}

async function remove(): Promise<void> {
  if (!deleteReady.value || busy.value || deleting.value === null) return;
  busy.value = true;
  error.value = null;

  try {
    await deleteCustomerStore(props.card.id, deleting.value.id, { name: deleteForm.name.trim(), reason: deleteForm.reason.trim(), confirmation_code: deleteForm.code.trim() });
    deleting.value = null;
    emit('updated', await fetchTenant(props.card.id));
  } catch (caught) {
    error.value = caught;
  } finally {
    deleteForm.code = '';
    busy.value = false;
  }
}
</script>

<template>
  <section class="flex flex-col gap-3" data-section="stores">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <p class="text-sm text-text-muted">{{ $t('tenant.stores_hint') }}</p>
      <button v-if="canManage && !adding" type="button" class="rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="add-store" @click="adding = true; added = false">{{ $t('store_data.add.submit') }}</button>
    </div>
    <p v-if="added" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-done="store">{{ $t('store_data.add.done') }}</p>

    <form v-if="adding" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="add-store" @submit.prevent="add">
      <div class="flex flex-col gap-1">
        <h2 class="text-lg font-semibold">{{ $t('store_data.add.title') }}</h2>
        <p class="text-text-muted">{{ $t('store_data.add.hint') }}</p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('store_data.add.name') }}</span>
          <input v-model="form.name" maxlength="100" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="store-name" />
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('store_data.add.url') }}</span>
          <input v-model="form.base_url" maxlength="2048" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono" data-field="store-url" />
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('store_data.add.platform') }}</span>
          <select v-model="form.platform" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="store-platform">
            <option value="woocommerce">WooCommerce</option>
            <option value="shopware">Shopware</option>
          </select>
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('store_data.add.timezone') }}</span>
          <input v-model="form.timezone" maxlength="64" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="store-timezone" />
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('store_data.add.currency') }}</span>
          <input v-model="form.default_currency" maxlength="3" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono uppercase" data-field="store-currency" @input="form.default_currency = form.default_currency.toUpperCase()" />
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="font-medium">{{ $t('store_data.add.locale') }}</span>
          <select v-model="form.locale" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="store-locale">
            <option v-for="code in (['de', 'en', 'ru'] as const)" :key="code" :value="code">{{ code.toUpperCase() }}</option>
          </select>
        </label>
      </div>
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('store_data.add.reason') }}</span>
        <textarea v-model="form.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
      </label>
      <ErrorNotice v-if="error" :error="error" />
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !ready" data-action="confirm-store">{{ $t('store_data.add.submit') }}</button>
        <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="adding = false">{{ $t('common.cancel') }}</button>
      </div>
    </form>
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
            <th v-if="canDelete" class="px-4 py-3 font-medium">{{ $t('store_data.remove.action') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="store in card.stores" :key="store.id" :data-store="store.host">
            <td class="px-4 py-3">
              <RouterLink v-if="canSupport" :to="{ name: 'owner-tenant', params: { id: card.id }, query: { tab: 'support' } }" class="font-medium text-primary hover:underline" data-open-store>{{ store.name }}</RouterLink>
              <span v-else class="font-medium">{{ store.name }}</span><span class="block text-[13px] text-text-muted">{{ store.host }}</span></td>
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
            <td v-if="canDelete" class="px-4 py-3"><button type="button" class="text-sm font-medium text-crit hover:underline" :data-delete-store="store.id" @click="openDelete(store)">{{ $t('store_data.remove.action') }}</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <form v-if="deleting" class="flex flex-col gap-3 rounded-xl border border-crit-border bg-crit-soft p-5 text-sm" data-form="delete-store" @submit.prevent="remove">
      <p class="font-semibold text-crit">{{ $t('store_data.remove.warning', { name: deleting.name }) }}</p>
      <label class="flex flex-col gap-1.5"><span>{{ $t('store_data.remove.name') }}</span><input v-model="deleteForm.name" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="store-name-confirm" /></label>
      <label class="flex flex-col gap-1.5"><span>{{ $t('store_data.remove.reason') }}</span><textarea v-model="deleteForm.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" /></label>
      <label class="flex flex-col gap-1.5"><span>{{ $t('store_data.remove.code') }}</span><input v-model="deleteForm.code" maxlength="32" autocomplete="one-time-code" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="step-up" /></label>
      <ErrorNotice v-if="error" :error="error" />
      <div class="flex gap-2"><button type="submit" class="rounded-md bg-crit-solid px-4 py-2 font-semibold text-white disabled:opacity-60" :disabled="busy || !deleteReady" data-action="confirm-store-deletion">{{ $t('store_data.remove.submit') }}</button><button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2" @click="deleting = null">{{ $t('common.cancel') }}</button></div>
    </form>
  </section>
</template>
