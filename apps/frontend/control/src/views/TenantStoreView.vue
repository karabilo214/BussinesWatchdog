<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { StatusBadge, formatMinor } from '@bw/ui';
import {
  changeStoreIncident,
  fetchCustomerArtifactUrl,
  fetchCustomerCheck,
  fetchCustomerOrder,
  fetchCustomerStore,
  fetchStoreChecks,
  fetchStoreFindings,
  fetchStoreIncidents,
  fetchStoreOrders,
  rerunStoreCheck,
  type CustomerStore,
  type Page,
  type StoreCheck,
  type StoreCheckDetail,
  type StoreFinding,
  type StoreIncident,
  type StoreOrder,
  type StoreOrderDetail,
} from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const TABS = ['findings', 'orders', 'checks', 'incidents'] as const;
type Tab = (typeof TABS)[number];

const props = defineProps<{ id: string; storeId: string }>();
const route = useRoute();
const router = useRouter();
const { locale, t, te } = useI18n();
const { dateTime } = useFormat();

const store = ref<CustomerStore | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const listError = ref<unknown>(null);
const busy = ref(false);
const findings = ref<StoreFinding[]>([]);
const orders = ref<StoreOrder[]>([]);
const checks = ref<StoreCheck[]>([]);
const incidents = ref<StoreIncident[]>([]);
const cursor = ref<string | null>(null);
const order = ref<StoreOrderDetail | null>(null);
const check = ref<StoreCheckDetail | null>(null);
const incident = ref<StoreIncident | null>(null);
const screenshots = ref<Record<string, string>>({});
const { can } = useStaff();
const canSupport = computed(() => can('tenants.support'));
const support = reactive({ kind: null as 'rerun' | 'acknowledge' | 'resolve' | null, reason: '', busy: false, error: null as unknown, done: null as string | null });
const scenarioToRerun = computed(() => checks.value[0]?.scenario_id ?? null);

function askSupport(kind: NonNullable<typeof support.kind>): void {
  Object.assign(support, { kind, reason: '', error: null, done: null });
}

async function confirmSupport(): Promise<void> {
  const kind = support.kind;
  const reason = support.reason.trim();
  if (kind === null || reason.length < 3 || support.busy) return;
  support.busy = true;
  support.error = null;

  try {
    if (kind === 'rerun' && scenarioToRerun.value) {
      await rerunStoreCheck(props.id, props.storeId, scenarioToRerun.value, reason);
      await loadTab();
    } else if (kind !== 'rerun' && incident.value) {
      incident.value = await changeStoreIncident(props.id, incident.value.id, kind, reason);
      incidents.value = incidents.value.map((row) => (row.id === incident.value?.id ? incident.value : row));
    }
    support.done = `store_data.support.done.${kind}`;
    support.kind = null;
  } catch (caught) {
    support.error = caught;
  } finally {
    support.busy = false;
  }
}

const tab = computed<Tab>(() => ((TABS as readonly string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'findings'));

function money(minor: string | null, currency: string | null, exponent: number | null): string {
  if (minor === null || currency === null || exponent === null) return '—';

  return formatMinor(minor, currency, exponent, locale.value) ?? `${minor} ${currency}`;
}

function label(group: string, code: string | null): string {
  if (code === null) return '—';
  const key = `store_data.labels.${group}.${code}`;

  return te(key) ? t(key) : code;
}

async function page<T>(load: (cursor: string | null) => Promise<Page<T>>, target: { value: T[] }, more: boolean): Promise<void> {
  busy.value = true;
  listError.value = null;

  try {
    const result = await load(more ? cursor.value : null);
    target.value = more ? [...target.value, ...result.data] : result.data;
    cursor.value = result.next_cursor;
  } catch (caught) {
    listError.value = caught;
  } finally {
    busy.value = false;
  }
}

function loadTab(more = false): Promise<void> {
  if (!more) {
    order.value = null;
    check.value = null;
    incident.value = null;
  }

  switch (tab.value) {
    case 'orders':
      return page((next) => fetchStoreOrders(props.id, props.storeId, next), orders, more);
    case 'checks':
      return page((next) => fetchStoreChecks(props.id, props.storeId, next), checks, more);
    case 'incidents':
      return page((next) => fetchStoreIncidents(props.id, props.storeId, next), incidents, more);
    default:
      return page((next) => fetchStoreFindings(props.id, props.storeId, next), findings, more);
  }
}

async function openOrder(id: string): Promise<void> {
  listError.value = null;

  try {
    order.value = await fetchCustomerOrder(props.id, id);
  } catch (caught) {
    listError.value = caught;
  }
}

async function openCheck(id: string): Promise<void> {
  listError.value = null;
  screenshots.value = {};

  try {
    check.value = await fetchCustomerCheck(props.id, id);
  } catch (caught) {
    listError.value = caught;
  }
}

async function showScreenshot(id: string): Promise<void> {
  try {
    screenshots.value = { ...screenshots.value, [id]: (await fetchCustomerArtifactUrl(props.id, id)).url };
  } catch (caught) {
    listError.value = caught;
  }
}

async function findingOrder(finding: StoreFinding): Promise<void> {
  if (finding.order_id === null) return;
  await router.replace({ query: { tab: 'orders' } });
  await openOrder(finding.order_id);
}

onMounted(async () => {
  try {
    store.value = await fetchCustomerStore(props.id, props.storeId);
    await loadTab();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
});

watch(tab, () => void loadTab());
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-7xl flex-col gap-6">
      <RouterLink :to="{ name: 'owner-tenant', params: { id }, query: { tab: 'stores' } }" class="self-start text-sm text-primary hover:underline">← {{ $t('store_data.back') }}</RouterLink>
      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else-if="store">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ store.name }}</h1>
          <p class="font-mono text-sm text-text-muted" data-store-url>{{ store.base_url }} · {{ store.platform }} · {{ store.default_currency }}</p>
          <p class="text-sm text-text-muted">{{ $t('store_data.hint') }}</p>
        </div>

        <nav class="flex flex-wrap gap-1 self-start rounded-lg border border-border bg-surface p-1" :aria-label="$t('store_data.open')">
          <button
            v-for="item in TABS"
            :key="item"
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="tab === item ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
            :data-tab="item"
            @click="router.replace({ query: { tab: item } })"
          >
            {{ $t(`store_data.tab.${item}`) }}
          </button>
        </nav>

        <ErrorNotice v-if="listError" :error="listError" />

        <p v-if="support.done" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-support-done>{{ $t(support.done) }}</p>
        <button v-if="canSupport && tab === 'checks' && scenarioToRerun" type="button" class="self-start rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="support-rerun" @click="askSupport('rerun')">{{ $t('store_data.support.rerun') }}</button>
        <form v-if="support.kind" class="flex flex-col gap-3 rounded-xl border border-note-border bg-note-soft p-4 text-sm" :data-form="`support-${support.kind}`" @submit.prevent="confirmSupport">
          <p class="font-medium">{{ $t(`store_data.support.confirm.${support.kind}`) }}</p>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
            <textarea v-model="support.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="support-reason" />
          </label>
          <ErrorNotice v-if="support.error" :error="support.error" />
          <div class="flex flex-wrap gap-2">
            <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="support.busy || support.reason.trim().length < 3" data-action="support-confirm">{{ $t('staff.apply') }}</button>
            <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="support.kind = null">{{ $t('common.cancel') }}</button>
          </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-surface" :data-list="tab">
          <table v-if="tab === 'findings' && findings.length > 0" class="w-full min-w-[48rem] text-sm">
            <thead class="border-b border-border text-left text-text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.time') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.rule') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.status') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.order') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.difference') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="row in findings" :key="row.id" :data-finding="row.id">
                <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(row.evaluated_at) }}</td>
                <td class="px-4 py-3">{{ label('rule', row.rule_code) }}</td>
                <td class="px-4 py-3"><StatusBadge :tone="row.status === 'ok' ? 'ok' : row.status === 'mismatch' ? 'crit' : 'unknown'" :label="label('finding_status', row.status)" /></td>
                <td class="px-4 py-3">
                  <button v-if="row.order_id" type="button" class="font-medium text-primary hover:underline" @click="findingOrder(row)">{{ row.order_display_number ?? row.order_id }}</button>
                  <span v-else>—</span>
                </td>
                <td class="px-4 py-3 tabular-nums">{{ money(row.difference_minor, row.currency, row.currency_exponent) }}</td>
              </tr>
            </tbody>
          </table>

          <table v-else-if="tab === 'orders' && orders.length > 0" class="w-full min-w-[48rem] text-sm">
            <thead class="border-b border-border text-left text-text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.number') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.time') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.state') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.total') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.gateway') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.paid') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="row in orders" :key="row.id" :data-order="row.id">
                <td class="px-4 py-3">
                  <button type="button" class="font-medium text-primary hover:underline" @click="openOrder(row.id)">{{ row.display_number ?? row.id }}</button>
                  <StatusBadge v-if="row.is_synthetic" tone="unknown" :label="$t('store_data.order.synthetic')" class="ml-1" />
                  <StatusBadge v-if="row.mode === 'test'" tone="warn" :label="$t('store_data.order.test')" class="ml-1" />
                </td>
                <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(row.source_created_at) }}</td>
                <td class="px-4 py-3">{{ row.status }}</td>
                <td class="px-4 py-3 tabular-nums">{{ money(row.total_minor, row.currency, row.currency_exponent) }}</td>
                <td class="px-4 py-3">{{ row.gateway ?? '—' }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(row.paid_marked_at) }}</td>
              </tr>
            </tbody>
          </table>

          <table v-else-if="tab === 'checks' && checks.length > 0" class="w-full min-w-[40rem] text-sm">
            <thead class="border-b border-border text-left text-text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.time') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.trigger') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.status') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.error') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="row in checks" :key="row.id" :data-check="row.id">
                <td class="px-4 py-3 whitespace-nowrap"><button type="button" class="font-medium text-primary hover:underline" @click="openCheck(row.id)">{{ dateTime(row.finished_at ?? row.scheduled_at) }}</button></td>
                <td class="px-4 py-3">{{ label('check_trigger', row.trigger) }}</td>
                <td class="px-4 py-3"><StatusBadge :tone="row.status === 'passed' ? 'ok' : row.status === 'failed' ? 'crit' : 'unknown'" :label="label('check_status', row.status)" /></td>
                <td class="px-4 py-3">{{ label('check_error', row.error_code) }}</td>
              </tr>
            </tbody>
          </table>

          <table v-else-if="tab === 'incidents' && incidents.length > 0" class="w-full min-w-[48rem] text-sm">
            <thead class="border-b border-border text-left text-text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.title') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.severity') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.status') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('store_data.col.last_seen') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="row in incidents" :key="row.id" :data-incident="row.id">
                <td class="px-4 py-3"><button type="button" class="text-left font-medium text-primary hover:underline" @click="incident = row">{{ label('incident_title', row.title_code) }}</button></td>
                <td class="px-4 py-3"><StatusBadge :tone="row.severity === 'critical' ? 'crit' : row.severity === 'warning' ? 'warn' : 'unknown'" :label="label('incident_severity', row.severity)" /></td>
                <td class="px-4 py-3">{{ label('incident_state', row.state) }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(row.last_seen_at) }}</td>
              </tr>
            </tbody>
          </table>

          <p v-else-if="!busy" class="p-5 text-sm text-text-muted" data-empty>{{ $t('store_data.empty') }}</p>
          <p v-else class="p-5 text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
        </div>

        <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" data-action="load-more" @click="loadTab(true)">{{ $t('store_data.load_more') }}</button>

        <section v-if="order" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-detail="order">
          <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ $t('store_data.order.title', { number: order.display_number ?? order.id }) }}</h2>
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" @click="order = null">{{ $t('store_data.close') }}</button>
          </div>
          <dl class="grid gap-3 sm:grid-cols-3">
            <div><dt class="text-text-muted">{{ $t('store_data.col.total') }}</dt><dd class="tabular-nums">{{ money(order.total_minor, order.currency, order.currency_exponent) }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('store_data.col.state') }}</dt><dd>{{ order.status }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('store_data.col.paid') }}</dt><dd>{{ dateTime(order.paid_marked_at) }}</dd></div>
          </dl>
          <div class="flex flex-col gap-1">
            <h3 class="font-semibold">{{ $t('store_data.order.findings') }}</h3>
            <p v-if="order.findings.length === 0" class="text-text-muted">{{ $t('store_data.empty') }}</p>
            <p v-for="row in order.findings" :key="row.id">{{ label('rule', row.rule_code) }} — {{ label('finding_status', row.status) }}<span v-if="row.difference_minor"> · {{ money(row.difference_minor, row.currency, row.currency_exponent) }}</span></p>
          </div>
          <div class="flex flex-col gap-1">
            <h3 class="font-semibold">{{ $t('store_data.order.captures') }}</h3>
            <p v-if="order.captures.length === 0" class="text-text-muted">{{ $t('store_data.empty') }}</p>
            <p v-for="row in order.captures" :key="row.id" class="tabular-nums">{{ dateTime(row.occurred_at) }} · {{ row.kind }} · {{ row.status }} · {{ money(row.amount_minor, row.currency, row.currency_exponent) }}</p>
          </div>
          <div class="flex flex-col gap-1">
            <h3 class="font-semibold">{{ $t('store_data.order.refunds') }}</h3>
            <p v-if="order.refunds.length === 0" class="text-text-muted">{{ $t('store_data.empty') }}</p>
            <p v-for="row in order.refunds" :key="row.id" class="tabular-nums">{{ dateTime(row.occurred_at) }} · {{ row.status }} · {{ money(row.amount_minor, row.currency, row.currency_exponent) }}</p>
          </div>
          <p class="text-text-muted">{{ $t('store_data.order.revisions', { n: order.revisions.length }) }}</p>
        </section>

        <section v-if="check" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-detail="check">
          <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ dateTime(check.finished_at ?? check.scheduled_at) }} · {{ label('check_status', check.status) }}</h2>
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" @click="check = null">{{ $t('store_data.close') }}</button>
          </div>
          <article v-for="attempt in check.attempts" :key="attempt.id" class="flex flex-col gap-3 rounded-lg border border-border p-4" :data-attempt="attempt.attempt_number">
            <p class="font-medium">{{ $t('store_data.check.attempt', { n: attempt.attempt_number }) }} · {{ label('attempt_status', attempt.status) }}<span v-if="attempt.error_code"> · {{ label('check_error', attempt.error_code) }}</span></p>
            <div>
              <h3 class="font-semibold">{{ $t('store_data.check.steps') }}</h3>
              <ol class="mt-1 list-decimal pl-5">
                <li v-for="step in attempt.steps" :key="step.index">{{ step.code }} — {{ label('step_status', step.status) }}<span v-if="step.error_code"> ({{ step.error_code }})</span></li>
              </ol>
            </div>
            <div class="flex flex-col gap-2">
              <h3 class="font-semibold">{{ $t('store_data.check.screenshots') }}</h3>
              <p v-if="attempt.artifacts.length === 0" class="text-text-muted">{{ $t('store_data.check.no_screenshots') }}</p>
              <div v-for="artifact in attempt.artifacts" :key="artifact.id" class="flex flex-col gap-2" :data-artifact="artifact.id">
                <img v-if="screenshots[artifact.id]" :src="screenshots[artifact.id]" :alt="$t('store_data.check.screenshot_alt')" class="max-w-full rounded-md border border-border" />
                <button v-else type="button" class="self-start rounded-md border border-border-strong px-3 py-1.5 font-medium text-primary hover:bg-primary-soft" data-action="show-screenshot" @click="showScreenshot(artifact.id)">{{ $t('store_data.check.show') }} ({{ Math.ceil(artifact.size_bytes / 1024) }} KB)</button>
              </div>
            </div>
          </article>
        </section>

        <section v-if="incident" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 text-sm" data-detail="incident">
          <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ label('incident_title', incident.title_code) }}</h2>
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" @click="incident = null">{{ $t('store_data.close') }}</button>
          </div>
          <dl class="grid gap-3 sm:grid-cols-4">
            <div><dt class="text-text-muted">{{ $t('store_data.col.severity') }}</dt><dd>{{ label('incident_severity', incident.severity) }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('store_data.incident.first_seen') }}</dt><dd>{{ dateTime(incident.first_seen_at) }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('store_data.col.last_seen') }}</dt><dd>{{ dateTime(incident.last_seen_at) }}</dd></div>
            <div><dt class="text-text-muted">{{ $t('store_data.incident.discrepancy') }}</dt><dd class="tabular-nums">{{ money(incident.verified_discrepancy_minor, incident.currency, incident.currency_exponent) }}</dd></div>
          </dl>
          <p v-if="incident.resolved_at">{{ $t('store_data.incident.resolved') }}: {{ dateTime(incident.resolved_at) }}</p>
          <div v-if="canSupport && incident.state !== 'resolved'" class="flex flex-wrap gap-2">
            <button v-if="incident.state === 'open'" type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium text-primary hover:bg-primary-soft" data-action="support-acknowledge" @click="askSupport('acknowledge')">{{ $t('store_data.support.acknowledge') }}</button>
            <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium text-primary hover:bg-primary-soft" data-action="support-resolve" @click="askSupport('resolve')">{{ $t('store_data.support.resolve') }}</button>
          </div>
        </section>
      </template>
    </div>
  </OwnerLayout>
</template>
