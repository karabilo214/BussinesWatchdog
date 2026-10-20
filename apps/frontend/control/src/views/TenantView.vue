<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { StatusBadge } from '@bw/ui';
import { fetchTenant, type TenantCard } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import TenantAudit from '@/components/tenant/TenantAudit.vue';
import TenantDeletion from '@/components/tenant/TenantDeletion.vue';
import TenantMembers from '@/components/tenant/TenantMembers.vue';
import TenantOverview from '@/components/tenant/TenantOverview.vue';
import TenantReminders from '@/components/tenant/TenantReminders.vue';
import TenantStores from '@/components/tenant/TenantStores.vue';
import TenantSubscription from '@/components/tenant/TenantSubscription.vue';
import { ACCESS_TONES, SUBSCRIPTION_TONES } from '@/components/tones';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const TABS = ['overview', 'stores', 'subscription', 'members', 'reminders', 'audit'] as const;
type Tab = (typeof TABS)[number];

const props = defineProps<{ id: string }>();
const route = useRoute();
const router = useRouter();
const { me } = useStaff();
const card = ref<TenantCard | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

const tab = computed<Tab>(() => (TABS as readonly string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'overview');
const tabs = computed(() => TABS.filter((item) => item !== 'subscription' || card.value?.billing !== null));
const canManage = computed(() => me.value?.capabilities.includes('tenants.manage') === true);
const canResetMfa = computed(() => me.value?.capabilities.includes('customers.mfa_reset') === true);
const canDelete = computed(() => me.value?.capabilities.includes('tenants.delete') === true);
const canDeleteStore = computed(() => me.value?.role === 'platform_owner' && canManage.value);

async function load(): Promise<void> {
  try {
    card.value = await fetchTenant(props.id);
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <RouterLink :to="{ name: 'owner-tenants' }" class="self-start text-sm text-primary hover:underline">← {{ $t('section.tenants.title') }}</RouterLink>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else-if="card">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="text-2xl font-semibold">{{ card.name }}</h1>
          <StatusBadge :tone="ACCESS_TONES[card.access.state]" :label="$t(`access.${card.access.state}`)" data-badge="access" />
          <StatusBadge v-if="card.status === 'suspended'" tone="crit" solid :label="$t('tenant_status.suspended')" data-badge="suspended" />
          <StatusBadge v-if="card.status === 'deleting'" tone="crit" solid :label="$t('tenant_status.deleting')" data-badge="deleting" />
          <StatusBadge v-if="card.billing?.subscription" :tone="SUBSCRIPTION_TONES[card.billing.subscription.status]" :label="$t(`subscription_status.${card.billing.subscription.status}`)" data-badge="subscription" />
        </div>
        <p class="font-mono text-[13px] text-text-muted">{{ card.id }}</p>
        <TenantDeletion :card="card" :can-delete="canDelete" @updated="card = $event" />

        <nav :aria-label="card.name" class="flex flex-wrap gap-1 self-start rounded-lg border border-border bg-surface p-1">
          <button
            v-for="item in tabs"
            :key="item"
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="tab === item ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
            :data-tab="item"
            @click="router.replace({ query: { tab: item } })"
          >
            {{ $t(`tenant.tab.${item}`) }}
          </button>
        </nav>

        <TenantOverview v-if="tab === 'overview'" :card="card" :can-manage="canManage" @updated="card = $event" />
        <TenantStores v-else-if="tab === 'stores'" :card="card" :can-manage="canManage" :can-delete="canDeleteStore" @updated="card = $event" />
        <TenantSubscription v-else-if="tab === 'subscription' && card.billing" :billing="card.billing" />
        <TenantMembers v-else-if="tab === 'members'" :card="card" :can-manage="canManage" :can-reset-mfa="canResetMfa" @updated="card = $event" />
        <TenantReminders v-else-if="tab === 'reminders'" :tenant-id="card.id" />
        <TenantAudit v-else-if="tab === 'audit'" :card="card" />
      </template>
    </div>
  </OwnerLayout>
</template>
