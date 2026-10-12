<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { formatMinor, StatusBadge } from '@bw/ui';
import { fetchTenant, type PlanChangeEntry, type TenantCard } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import TenantActions from '@/components/TenantActions.vue';
import { ACCESS_TONES, SUBSCRIPTION_TONES } from '@/components/tones';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const TABS = ['overview', 'stores', 'subscription', 'members', 'audit'] as const;
type Tab = (typeof TABS)[number];

const props = defineProps<{ id: string }>();
const route = useRoute();
const router = useRouter();
const { locale } = useI18n();
const { dateTime } = useFormat();
const { me } = useStaff();
const card = ref<TenantCard | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

const tab = computed<Tab>(() => (TABS as readonly string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'overview');
const tabs = computed(() => TABS.filter((item) => item !== 'subscription' || card.value?.billing !== null));
const canManage = computed(() => me.value?.capabilities.includes('tenants.manage') === true);

function changeMoney(change: PlanChangeEntry): string {
  return formatMinor(change.due_now_minor, change.currency, change.currency_exponent, locale.value) ?? `${change.due_now_minor} ${change.currency}`;
}

function limit(value: number | null | undefined): string {
  return value === null || value === undefined ? '∞' : String(value);
}

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
          <StatusBadge v-if="card.billing?.subscription" :tone="SUBSCRIPTION_TONES[card.billing.subscription.status]" :label="$t(`subscription_status.${card.billing.subscription.status}`)" data-badge="subscription" />
        </div>
        <p class="font-mono text-[13px] text-text-muted">{{ card.id }}</p>

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

        <section v-if="tab === 'overview'" class="grid gap-4 md:grid-cols-2" data-section="overview">
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
          <TenantActions v-if="canManage" :card="card" @updated="card = $event" />
        </section>

        <section v-else-if="tab === 'stores'" class="flex flex-col gap-3" data-section="stores">
          <p class="text-sm text-text-muted">{{ $t('tenant.stores_hint') }}</p>
          <p v-if="card.stores.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('tenant.no_stores') }}</p>
          <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
            <table class="w-full min-w-[40rem] text-sm">
              <thead class="border-b border-border text-left text-text-muted">
                <tr>
                  <th class="px-4 py-3 font-medium">{{ $t('tenant.store.name') }}</th>
                  <th class="px-4 py-3 font-medium">{{ $t('tenant.store.platform') }}</th>
                  <th class="px-4 py-3 font-medium">{{ $t('tenant.store.state') }}</th>
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
                  <td class="px-4 py-3 tabular-nums" :class="store.open_incidents > 0 ? 'text-crit' : 'text-text-muted'">{{ store.open_incidents }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-else-if="tab === 'subscription' && card.billing" class="flex flex-col gap-4" data-section="subscription">
          <p v-if="card.billing.mode === 'test'" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm text-warn">{{ $t('tenant.test_mode') }}</p>
          <dl v-if="card.billing.subscription" class="grid gap-x-6 gap-y-3 rounded-xl border border-border bg-surface p-5 text-sm sm:grid-cols-2" data-subscription>
            <div><dt class="text-text-muted">{{ $t('tenant.sub.plan') }}</dt><dd class="font-medium">{{ card.billing.subscription.plan_code }} <span v-if="card.billing.subscription.extra_store_quantity > 0">+ {{ $t('tenant.sub.extra', { n: card.billing.subscription.extra_store_quantity }) }}</span></dd></div>
            <div><dt class="text-text-muted">{{ $t('tenant.sub.provider') }}</dt><dd>{{ card.billing.subscription.provider }} <span v-if="card.billing.subscription.provider_subscription_ref" class="font-mono text-[13px]">{{ card.billing.subscription.provider_subscription_ref }}</span></dd></div>
            <div v-if="card.billing.subscription.paid_until"><dt class="text-text-muted">{{ $t('tenant.sub.paid_until') }}</dt><dd>{{ dateTime(card.billing.subscription.paid_until) }}</dd></div>
            <div v-if="card.billing.subscription.trial_ends_at"><dt class="text-text-muted">{{ $t('tenant.sub.trial_until') }}</dt><dd data-trial-ends>{{ dateTime(card.billing.subscription.trial_ends_at) }}</dd></div>
            <div v-if="card.billing.subscription.next_charge_at"><dt class="text-text-muted">{{ $t('tenant.sub.next_charge') }}</dt><dd>{{ dateTime(card.billing.subscription.next_charge_at) }}</dd></div>
            <div v-if="card.billing.subscription.past_due_since"><dt class="text-text-muted">{{ $t('tenant.sub.past_due_since') }}</dt><dd class="text-warn">{{ dateTime(card.billing.subscription.past_due_since) }}</dd></div>
            <div v-if="card.billing.subscription.cancel_at_period_end" class="sm:col-span-2"><dd class="text-warn" data-cancel-scheduled>{{ $t('tenant.sub.cancel_scheduled', { date: dateTime(card.billing.subscription.period_end) }) }}</dd></div>
            <div v-if="card.billing.subscription.scheduled_change" class="sm:col-span-2">
              <dd class="text-note">{{ $t('tenant.sub.scheduled_change', { plan: card.billing.subscription.scheduled_change.to.plan_code, date: dateTime(card.billing.subscription.scheduled_change.effective_at) }) }}</dd>
            </div>
          </dl>
          <p v-else class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('tenants.no_subscription') }}</p>

          <h2 class="text-base font-semibold">{{ $t('tenant.sub.contracts') }}</h2>
          <p v-if="card.billing.contracts.length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
          <ul v-else class="flex flex-col gap-2 text-sm">
            <li v-for="contract in card.billing.contracts" :key="contract.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border bg-surface px-4 py-3" :data-contract="contract.provider_subscription_ref">
              <span class="font-mono">{{ contract.provider_subscription_ref }}</span>
              <StatusBadge :tone="contract.state === 'active' ? 'ok' : contract.state === 'past_due' ? 'warn' : 'unknown'" :label="$t(`contract_state.${contract.state}`, contract.state)" />
              <span>{{ contract.plan_code ?? '—' }}</span>
              <span class="text-text-muted">{{ dateTime(contract.period_start) }} — {{ dateTime(contract.period_end) }}</span>
              <span class="text-text-muted">{{ contract.mode }}</span>
              <span v-if="contract.problem_code" class="text-crit">{{ contract.problem_code }}</span>
            </li>
          </ul>

          <h2 class="text-base font-semibold">{{ $t('tenant.sub.changes') }}</h2>
          <p v-if="card.billing.plan_changes.length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
          <ul v-else class="flex flex-col gap-2 text-sm">
            <li v-for="change in card.billing.plan_changes" :key="change.id" class="flex flex-wrap gap-x-3 rounded-lg border border-border bg-surface px-4 py-3">
              <span>{{ change.from.plan_code }} (+{{ change.from.extra_store_quantity }}) → {{ change.to.plan_code }} (+{{ change.to.extra_store_quantity }})</span>
              <span class="text-text-muted">{{ $t(`tenant.sub.change_status.${change.status}`, change.status) }}</span>
              <span v-if="change.kind === 'immediate'" class="tabular-nums">{{ $t('tenant.sub.charged', { amount: changeMoney(change) }) }}</span>
              <span class="text-text-muted">{{ dateTime(change.created_at ?? null) }}</span>
            </li>
          </ul>

          <h2 class="text-base font-semibold">{{ $t('tenant.sub.overrides') }}</h2>
          <p v-if="card.billing.overrides.length === 0" class="text-sm text-text-muted">{{ $t('tenant.sub.none') }}</p>
          <ul v-else class="flex flex-col gap-2 text-sm">
            <li v-for="override in card.billing.overrides" :key="override.id" class="flex flex-wrap items-center gap-x-3 rounded-lg border border-border bg-surface px-4 py-3" data-override>
              <StatusBadge :tone="override.active ? 'note' : 'unknown'" :label="override.active ? $t('tenant.sub.override_active') : override.revoked_at ? $t('tenant.sub.override_revoked') : $t('tenant.sub.override_ended')" />
              <span>{{ dateTime(override.starts_at) }} — {{ dateTime(override.ends_at) }}</span>
              <span class="text-text-muted">{{ override.reason }}</span>
            </li>
          </ul>
        </section>

        <section v-else-if="tab === 'members'" class="overflow-x-auto rounded-xl border border-border bg-surface" data-section="members">
          <table class="w-full min-w-[32rem] text-sm">
            <tbody class="divide-y divide-border">
              <tr v-for="member in card.members" :key="`${member.email}-${member.role}`">
                <td class="px-4 py-3 font-medium">{{ member.name ?? '—' }}</td>
                <td class="px-4 py-3">{{ member.email ?? '—' }} <span v-if="member.email_verified" class="text-ok">✓</span></td>
                <td class="px-4 py-3">{{ $t(`member_role.${member.role}`, member.role) }}</td>
                <td class="px-4 py-3 text-text-muted">{{ dateTime(member.joined_at) }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section v-else-if="tab === 'audit'" class="flex flex-col gap-2" data-section="audit">
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
    </div>
  </OwnerLayout>
</template>
