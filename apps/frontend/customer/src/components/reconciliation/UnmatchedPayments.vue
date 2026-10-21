<script setup lang="ts">
import { ref, watch } from 'vue';
import { allocatePayment, listUnmatchedPayments } from '@/api/reconciliation';
import type { UnmatchedCandidate, UnmatchedPayment } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useIncidentText } from '@/components/incidents/useIncidentText';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';

const props = defineProps<{ storeId: string; canAllocate: boolean }>();
const emit = defineEmits<{ allocated: [] }>();
const { dateTime } = useFormat();
const { amount } = useIncidentText();

const items = ref<UnmatchedPayment[]>([]);
const nextCursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const linking = ref<{ capture: string; order: string } | null>(null);
const reason = ref('');
const busy = ref(false);
const actionError = ref<unknown>(null);
const done = ref(false);

async function load(more = false): Promise<void> {
  if (!more) loading.value = true;
  error.value = null;

  try {
    const page = await listUnmatchedPayments(props.storeId, more ? (nextCursor.value ?? undefined) : undefined);
    items.value = more ? [...items.value, ...page.data] : page.data;
    nextCursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

const others = ref<UnmatchedPayment[]>([]);
const othersCursor = ref<string | null>(null);
const othersLoaded = ref(false);
const othersError = ref<unknown>(null);

/** Payments of the same provider account with another store's marker: loaded only when the section is opened. */
async function loadOthers(more = false): Promise<void> {
  othersError.value = null;

  try {
    const page = await listUnmatchedPayments(props.storeId, more ? (othersCursor.value ?? undefined) : undefined, 'other_stores');
    others.value = more ? [...others.value, ...page.data] : page.data;
    othersCursor.value = page.next_cursor;
    othersLoaded.value = true;
  } catch (caught) {
    othersError.value = caught;
  }
}

function toggleOthers(event: Event): void {
  if ((event.target as HTMLDetailsElement).open && !othersLoaded.value) {
    void loadOthers();
  }
}

function startLink(item: UnmatchedPayment, candidate: UnmatchedCandidate): void {
  linking.value = { capture: item.capture_transaction_id, order: candidate.order_id };
  reason.value = '';
  actionError.value = null;
  done.value = false;
}

async function link(item: UnmatchedPayment): Promise<void> {
  if (linking.value === null || item.payment_id === null) return;

  busy.value = true;
  actionError.value = null;

  try {
    await allocatePayment({
      order_id: linking.value.order,
      payment_id: item.payment_id,
      capture_transaction_id: item.capture_transaction_id,
      amount_minor: item.amount_minor,
      currency: item.currency,
      reason: reason.value.trim(),
    });
    linking.value = null;
    done.value = true;
    emit('allocated');
    await load();
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

watch(() => props.storeId, () => load(), { immediate: true });
</script>

<template>
  <div class="flex flex-col gap-4" data-panel="unmatched">
    <p class="max-w-3xl text-sm text-text-muted">{{ $t('reconciliation.unmatched.subtitle') }}</p>
    <p v-if="done" class="text-sm text-ok" role="status">{{ $t('reconciliation.unmatched.linked') }}</p>

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="error" :error="error" />
    <p v-else-if="items.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-6 text-sm text-text-muted">
      {{ $t('reconciliation.unmatched.empty') }}
    </p>

    <ul v-else class="flex flex-col gap-3">
      <li v-for="item in items" :key="item.capture_transaction_id" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4" :data-capture-id="item.capture_transaction_id">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex flex-col gap-0.5">
            <span class="font-mono text-base font-semibold">{{ amount(item.amount_minor, item.currency, item.currency_exponent) }}</span>
            <span class="text-[13px] text-text-muted">{{ $t('reconciliation.unmatched.captured_at', { time: dateTime(item.occurred_at) }) }}<template v-if="item.mode === 'test'"> · {{ $t('reconciliation.unmatched.test_mode') }}</template></span>
            <span class="break-all font-mono text-[13px] text-text-muted">{{ item.external_operation_id }}</span>
          </div>
          <StatusBadge :tone="item.reason === 'no_candidate_found' ? 'crit' : 'warn'" :label="$t(`reconciliation.unmatched.reason.${item.reason}`)" />
        </div>

        <ul v-if="item.candidates.length > 0" class="flex flex-col divide-y divide-border rounded-lg border border-border">
          <li v-for="candidate in item.candidates" :key="candidate.order_id" class="flex flex-col gap-3 p-3">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
              <div class="flex flex-col gap-0.5">
                <RouterLink :to="{ name: 'order', params: { id: candidate.order_id } }" class="font-medium text-primary hover:underline">
                  {{ candidate.display_number ?? $t('incident.evidence.order_unknown_number') }}
                </RouterLink>
                <span class="text-[13px] text-text-muted">{{ $t(`reconciliation.unmatched.confidence.${candidate.confidence}`) }} · <span class="font-mono">{{ amount(candidate.amount_minor, candidate.currency, candidate.currency_exponent) }}</span></span>
              </div>
              <button
                v-if="canAllocate && item.payment_id && !(linking?.capture === item.capture_transaction_id && linking.order === candidate.order_id)"
                type="button"
                class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft"
                @click="startLink(item, candidate)"
              >
                {{ $t('reconciliation.unmatched.link') }}
              </button>
            </div>
            <form
              v-if="linking?.capture === item.capture_transaction_id && linking.order === candidate.order_id"
              class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3"
              @submit.prevent="link(item)"
            >
              <p class="text-sm">{{ $t('reconciliation.unmatched.link_warning') }}</p>
              <label :for="`link-reason-${item.capture_transaction_id}`" class="text-sm font-medium">{{ $t('reconciliation.reason') }}</label>
              <textarea :id="`link-reason-${item.capture_transaction_id}`" v-model="reason" rows="2" maxlength="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm"></textarea>
              <ErrorNotice v-if="actionError" :error="actionError" />
              <div class="flex flex-wrap gap-2">
                <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || reason.trim().length < 5">
                  {{ $t('reconciliation.unmatched.link_confirm') }}
                </button>
                <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium" @click="linking = null">{{ $t('common.cancel') }}</button>
              </div>
            </form>
          </li>
        </ul>
        <p v-else class="text-sm text-text-muted">{{ $t('reconciliation.unmatched.no_candidates') }}</p>
      </li>
    </ul>

    <div v-if="nextCursor">
      <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" @click="load(true)">
        {{ $t('incidents.load_more') }}
      </button>
    </div>

    <details class="rounded-xl border border-border bg-surface p-4" data-panel="other-stores" @toggle="toggleOthers">
      <summary class="cursor-pointer text-sm font-medium">{{ $t('reconciliation.unmatched.other_stores.title') }}</summary>
      <div class="mt-3 flex flex-col gap-3">
        <p class="max-w-3xl text-sm text-text-muted">{{ $t('reconciliation.unmatched.other_stores.subtitle') }}</p>
        <ErrorNotice v-if="othersError" :error="othersError" />
        <p v-else-if="othersLoaded && others.length === 0" class="text-sm text-text-muted" data-empty-others>{{ $t('reconciliation.unmatched.other_stores.empty') }}</p>
        <ul v-else class="flex flex-col divide-y divide-border">
          <li v-for="item in others" :key="item.capture_transaction_id" class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm" :data-other-store="item.capture_transaction_id">
            <span class="font-mono">{{ amount(item.amount_minor, item.currency, item.currency_exponent) }} · {{ item.external_operation_id }}</span>
            <span class="text-text-muted">{{ dateTime(item.occurred_at) }} · {{ $te(`reconciliation.unmatched.other_stores.reason.${item.reason}`) ? $t(`reconciliation.unmatched.other_stores.reason.${item.reason}`) : item.reason }}</span>
          </li>
        </ul>
        <div v-if="othersCursor">
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" @click="loadOthers(true)">
            {{ $t('incidents.load_more') }}
          </button>
        </div>
      </div>
    </details>
  </div>
</template>
