<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getOrganizationFacts, listInvitations, listMembers, type OrganizationFacts } from '@/api/account';
import { fetchPlans, fetchSubscription, type BillingCatalog, type BillingSubscription } from '@/api/billing';
import { listStores } from '@/api/stores';
import type { Store, TeamInvitation, TeamMember } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import { LOCALE_NAMES, type Locale } from '@bw/i18n';
import { StatusBadge } from '@bw/ui';

defineProps<{ name: string }>();

const { t, locale } = useI18n();
const { dateTime } = useFormat();
const PROVIDER_NAMES: Record<string, string> = { stripe: 'Stripe', paypal: 'PayPal' };
const { role } = useRole();

const stores = ref<Store[] | null>(null);
const members = ref<TeamMember[] | null>(null);
const invitations = ref<TeamInvitation[] | null>(null);
const subscription = ref<BillingSubscription | null | undefined>(undefined);
const catalog = ref<BillingCatalog | null>(null);
const facts = ref<OrganizationFacts | null>(null);

const visibleStores = computed(() => (stores.value ?? []).filter((store) => store.status !== 'deleted'));
const pendingInvitations = computed(() => (invitations.value ?? []).filter((invitation) => invitation.state === 'pending'));
const planName = computed(() => {
  const code = subscription.value?.plan_code;
  const plan = catalog.value?.data.find((item) => item.plan_version_id === subscription.value?.plan_version_id || item.plan_code === code);

  return plan ? (plan.name[locale.value] ?? plan.name.en ?? plan.plan_code) : (code ?? t('billing.no_plan'));
});
const storeLimit = computed(() => subscription.value?.capacity.limit ?? null);

onMounted(async () => {
  const [storeList, team, invites, current, plans, organization] = await Promise.allSettled([listStores(), listMembers(), listInvitations(), fetchSubscription(), fetchPlans(), getOrganizationFacts()]);
  stores.value = storeList.status === 'fulfilled' ? storeList.value : null;
  members.value = team.status === 'fulfilled' ? team.value.data : null;
  invitations.value = invites.status === 'fulfilled' ? invites.value : null;
  subscription.value = current.status === 'fulfilled' ? current.value : undefined;
  catalog.value = plans.status === 'fulfilled' ? plans.value : null;
  facts.value = organization.status === 'fulfilled' ? organization.value : null;
});
</script>

<template>
  <section class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5" aria-labelledby="organization-title" data-panel="organization-summary">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="organization-title" class="text-lg font-semibold">{{ $t('organization.summary.title') }}</h2>
      <StatusBadge v-if="role" tone="note" :label="$t('organization.summary.your_role', { role: $t(`team.role.${role}`) })" />
    </div>
    <p class="text-base font-medium" data-summary="name">{{ name }}</p>
    <dl v-if="facts" class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2" data-summary="facts">
      <div v-if="facts.owner_name" class="flex gap-2"><dt class="text-text-muted">{{ $t('organization.summary.owner') }}</dt><dd>{{ facts.owner_name }}</dd></div>
      <div v-if="facts.created_at" class="flex gap-2"><dt class="text-text-muted">{{ $t('organization.summary.created') }}</dt><dd>{{ dateTime(facts.created_at) }}</dd></div>
      <div v-if="facts.locale" class="flex gap-2"><dt class="text-text-muted">{{ $t('organization.summary.language') }}</dt><dd>{{ LOCALE_NAMES[facts.locale as Locale] ?? facts.locale }}</dd></div>
      <div v-if="facts.timezone" class="flex gap-2"><dt class="text-text-muted">{{ $t('organization.summary.timezone') }}</dt><dd>{{ facts.timezone }}</dd></div>
    </dl>

    <div v-if="subscription !== undefined" class="flex flex-col gap-1 text-sm" data-summary="subscription">
      <h3 class="font-semibold">{{ $t('organization.summary.subscription') }}</h3>
      <p v-if="subscription === null" class="text-text-muted">{{ $t('organization.summary.no_subscription') }}</p>
      <p v-else>
        {{ planName }} · {{ $t(`billing.status.${subscription.status}`) }}
        <span v-if="subscription.status === 'trial' && subscription.trial_ends_at" class="text-text-muted"> · {{ $t('organization.summary.until', { date: dateTime(subscription.trial_ends_at) }) }}</span>
        <span v-else-if="subscription.paid_until" class="text-text-muted"> · {{ $t('organization.summary.until', { date: dateTime(subscription.paid_until) }) }}</span>
      </p>
      <RouterLink :to="{ name: 'billing' }" class="self-start text-primary hover:underline">{{ $t('organization.summary.open_billing') }}</RouterLink>
    </div>

    <div v-if="stores" class="flex flex-col gap-2 text-sm" data-summary="stores">
      <h3 class="font-semibold">
        {{ storeLimit === null ? $t('organization.summary.stores', { n: visibleStores.length }) : $t('organization.summary.stores_of', { n: visibleStores.length, limit: storeLimit }) }}
      </h3>
      <p v-if="visibleStores.length === 0" class="text-text-muted">{{ $t('organization.summary.no_stores') }}</p>
      <ul v-else class="flex flex-col gap-1">
        <li v-for="store in visibleStores" :key="store.id" class="flex flex-wrap items-center gap-2" :data-summary-store="store.id">
          <RouterLink :to="{ name: 'store', params: { id: store.id } }" class="font-medium text-primary hover:underline">{{ store.name }}</RouterLink>
          <span class="text-text-muted">{{ $t(`store.form.platforms.${store.platform}`, store.platform) }}</span>
          <StatusBadge :tone="store.status === 'active' ? 'ok' : store.status === 'onboarding' ? 'unknown' : 'warn'" :label="$t(`store_status.${store.status}`)" />
          <StatusBadge v-if="store.capacity_paused_at" tone="warn" :label="$t('organization.summary.paused_by_limit')" />
        </li>
      </ul>
    </div>

    <div v-if="members" class="flex flex-col gap-2 text-sm" data-summary="team">
      <h3 class="font-semibold">{{ $t('organization.summary.team', { n: members.length }) }}</h3>
      <ul class="flex flex-col gap-1">
        <li v-for="member in members" :key="member.user_id" class="flex flex-wrap items-center gap-2" :data-summary-member="member.user_id">
          <span class="font-medium">{{ member.name || $t('organization.summary.no_name') }}<template v-if="member.is_you"> ({{ $t('team.you') }})</template></span>
          <span class="text-text-muted">{{ $t(`team.role.${member.role}`) }}</span>
        </li>
      </ul>
      <p v-if="pendingInvitations.length > 0" class="text-text-muted" data-summary="invitations">{{ $t('organization.summary.invitations', { n: pendingInvitations.length }) }}</p>
      <RouterLink :to="{ name: 'team' }" class="self-start text-primary hover:underline">{{ $t('organization.summary.open_team') }}</RouterLink>
    </div>

    <div v-if="facts" class="flex flex-col gap-2 text-sm" data-summary="providers">
      <h3 class="font-semibold">{{ $t('organization.summary.providers') }}</h3>
      <p v-if="facts.payment_providers.length === 0" class="text-text-muted">{{ $t('organization.summary.no_providers') }}</p>
      <ul v-else class="flex flex-col gap-1">
        <li v-for="item in facts.payment_providers" :key="item.provider" class="flex flex-wrap items-center gap-2" :data-summary-provider="item.provider">
          <span class="font-medium">{{ PROVIDER_NAMES[item.provider] ?? item.provider }}</span>
          <span class="text-text-muted">{{ $t('organization.summary.provider_stores', { n: item.stores }) }}</span>
          <StatusBadge v-if="item.attention > 0" tone="warn" :label="$t('organization.summary.provider_attention', { n: item.attention })" />
        </li>
      </ul>
      <p class="text-[13px] text-text-muted">{{ $t('organization.summary.providers_hint') }}</p>
    </div>

    <div v-if="facts?.notification_channels" class="flex flex-col gap-1 text-sm" data-summary="channels">
      <h3 class="font-semibold">{{ $t('organization.summary.channels', { n: facts.notification_channels.enabled }) }}</h3>
      <p v-if="facts.notification_channels.enabled === 0" class="text-text-muted">{{ $t('organization.summary.no_channels') }}</p>
      <p v-else class="flex flex-wrap gap-2">
        <StatusBadge v-if="facts.notification_channels.failing > 0" tone="crit" :label="$t('organization.summary.channels_failing', { n: facts.notification_channels.failing })" />
        <StatusBadge v-if="facts.notification_channels.unverified > 0" tone="warn" :label="$t('organization.summary.channels_unverified', { n: facts.notification_channels.unverified })" />
        <StatusBadge v-if="facts.notification_channels.failing === 0 && facts.notification_channels.unverified === 0" tone="ok" :label="$t('organization.summary.channels_ok')" />
      </p>
      <RouterLink :to="{ name: 'notifications' }" class="self-start text-primary hover:underline">{{ $t('organization.summary.open_notifications') }}</RouterLink>
    </div>
  </section>
</template>
