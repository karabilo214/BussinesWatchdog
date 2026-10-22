<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import type { IncidentExplanation, IncidentExplanationAmount } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { useIncidentText } from './useIncidentText';

const props = defineProps<{ explanation: IncidentExplanation }>();
const { t, te } = useI18n();
const { dateTime } = useFormat();
const { amount, resolution } = useIncidentText();

const scenario = computed(() => props.explanation.scenario);
const params = computed(() => props.explanation.params ?? {});

function money(value: IncidentExplanationAmount | undefined): string | null {
  return value ? amount(value.minor, value.currency, value.exponent) : null;
}

function label(group: string, code: string | undefined | null): string {
  if (!code) return t('incident.explain.unknown');

  return te(`incident.explain.${group}.${code}`) ? t(`incident.explain.${group}.${code}`) : code;
}

/** Every text gets the same named values; a missing one has its own wording in the template, never an empty gap. */
function values(extra: Record<string, unknown> = {}): Record<string, string> {
  const merged = { ...params.value, ...extra } as Record<string, unknown>;
  const provider = typeof merged.provider === 'string' ? merged.provider : null;
  const error = typeof merged.error === 'string' ? merged.error : null;
  const reason = typeof merged.reason === 'string' ? merged.reason : null;

  const providerName = provider && te(`incident.explain.provider.${provider}`) ? t(`incident.explain.provider.${provider}`) : t('incident.explain.provider.default');
  const providerAt = provider && te(`incident.explain.provider_at.${provider}`) ? t(`incident.explain.provider_at.${provider}`) : t('incident.explain.provider_at.default');
  const reasonText = !reason
    ? ''
    : te(`incident.facts.connector_reason.${reason}`)
      ? t(`incident.facts.connector_reason.${reason}`)
      : te(`incident.explain.observation_reason.${reason}`)
        ? t(`incident.explain.observation_reason.${reason}`, { provider: providerName, provider_at: providerAt })
        : reason;

  return {
    order: String(merged.order ?? ''),
    reference: String(merged.reference ?? ''),
    capture: String(merged.capture ?? ''),
    refund: String(merged.refund ?? ''),
    ids: String(merged.ids ?? ''),
    count: String(merged.count ?? ''),
    difference: money(merged.difference as IncidentExplanationAmount | undefined) ?? '',
    amount: money(merged.amount as IncidentExplanationAmount | undefined) ?? '',
    expected: money(merged.expected as IncidentExplanationAmount | undefined) ?? '',
    actual: money(merged.actual as IncidentExplanationAmount | undefined) ?? '',
    provider: providerName,
    provider_at: providerAt,
    source: label('source', provider),
    step: label('check_step', merged.step as string | undefined),
    status: label('payment_status', merged.status as string | undefined),
    state: label('store_state', merged.state as string | undefined),
    error: error && te(`checks.error.${error}`) ? t(`checks.error.${error}`) : error ?? '',
    reason: reasonText,
    at: typeof merged.at === 'string' ? dateTime(merged.at) : '',
  };
}

const headline = computed(() => {
  const key = `incident.explain.${scenario.value}.headline`;
  const variant = scenario.value === 'browser_check_stopped' || scenario.value === 'source_unavailable' ? props.explanation.variant : params.value.order ? 'with_order' : 'no_order';

  return t(`${key}.${variant}`, values());
});

const fact = computed(() => {
  const base = `incident.explain.${scenario.value}.fact`;
  const variant = props.explanation.variant && te(`${base}.${props.explanation.variant}`) ? props.explanation.variant : 'default';
  const withAmount = params.value.amount && te(`${base}.${variant}_amount`) ? `${variant}_amount` : variant;

  return t(`${base}.${withAmount}`, values());
});

const observation = computed(() => props.explanation.observation);
/** Once new data confirmed the fix there is nothing left to check; a manual close confirms nothing, so the steps stay. */
const actionable = computed(() => props.explanation.status !== 'resolved_confirmed');
const outcome = computed(() => props.explanation.outcome);

function evidenceValue(row: IncidentExplanation['evidence'][number]): string {
  const key = `incident.explain.evidence.${row.item}.${row.value}`;

  return te(key) ? t(key, values(row.params)) : row.value;
}

const technical = computed(() =>
  Object.entries(props.explanation.technical ?? {}).map(([key, value]) => ({ key, value: Array.isArray(value) ? value.join(', ') : String(value) })),
);
</script>

<template>
  <section class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5" data-panel="incident-explanation" aria-labelledby="explanation-title">
    <div class="flex flex-col gap-2">
      <h2 id="explanation-title" class="text-lg font-semibold" data-headline>{{ headline }}</h2>
      <p class="text-sm" data-fact>{{ fact }}</p>
    </div>

    <dl class="grid gap-3 text-sm sm:grid-cols-2">
      <div class="flex flex-col gap-0.5 rounded-lg border border-border px-3 py-2" data-problem-status>
        <dt class="text-[13px] text-text-muted">{{ $t('incident.explain.problem_label') }}</dt>
        <dd class="font-medium">{{ $t(`incident.explain.status.${explanation.status}`) }}</dd>
      </div>
      <div v-if="observation" class="flex flex-col gap-0.5 rounded-lg border border-border px-3 py-2" data-observation>
        <dt class="text-[13px] text-text-muted">{{ $t('incident.explain.observation_label') }}</dt>
        <dd class="font-medium" :class="observation.state === 'fresh' ? '' : 'text-warn'">
          {{ $t(`incident.explain.observation.${observation.state}`) }}<template v-if="observation.reason"> — {{ values({ reason: observation.reason }).reason }}</template>
        </dd>
        <dd v-if="observation.checked_at" class="text-[13px] text-text-muted">{{ $t('incident.explain.checked_at', { at: dateTime(observation.checked_at) }) }}</dd>
      </div>
    </dl>

    <p v-if="outcome" class="rounded-lg bg-note-soft px-3 py-2 text-sm" data-outcome>
      {{ $t(`incident.explain.outcome.${outcome.code}`, { at: outcome.at ? dateTime(outcome.at) : '', reason: resolution(outcome.reason) ?? '' }) }}
    </p>

    <div class="flex flex-col gap-2">
      <h3 class="text-base font-semibold">{{ $t('incident.explain.evidence_title') }}</h3>
      <div class="overflow-x-auto">
        <table class="w-full text-sm" data-evidence>
          <thead class="text-left text-text-muted">
            <tr class="border-b border-border">
              <th scope="col" class="py-2 pr-4 font-medium">{{ $t('incident.explain.evidence_check') }}</th>
              <th scope="col" class="py-2 font-medium">{{ $t('incident.explain.evidence_result') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in explanation.evidence" :key="row.item" class="border-b border-border last:border-0" :data-evidence-item="row.item">
              <td class="py-2 pr-4 text-text-muted">{{ label('evidence_item', row.item) }}</td>
              <td class="py-2 break-words">{{ evidenceValue(row) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="actionable && explanation.causes.length > 0" class="flex flex-col gap-1">
      <h3 class="text-base font-semibold">{{ $t('incident.explain.causes_title') }}</h3>
      <p class="text-[13px] text-text-muted">{{ $t('incident.explain.causes_note') }}</p>
      <ul class="list-disc pl-5 text-sm" data-causes>
        <li v-for="cause in explanation.causes" :key="cause" :data-cause="cause">{{ $t(`incident.explain.cause.${cause}`, values()) }}</li>
      </ul>
    </div>

    <div v-if="actionable" class="flex flex-col gap-2 rounded-lg border border-note-border bg-note-soft p-4">
      <h3 class="text-base font-semibold">{{ $t('incident.explain.steps_title') }}</h3>
      <ol class="list-decimal pl-5 text-sm" data-steps>
        <li v-for="step in explanation.steps" :key="step" :data-step="step">
          {{ $t(`incident.explain.step.${step}`, values()) }}
          <RouterLink v-if="step === 'open_check_run' && params.check_run_id" :to="{ name: 'check-run', params: { id: String(params.check_run_id) } }" class="text-primary hover:underline">{{ $t('incident.explain.open_check_run_link') }}</RouterLink>
        </li>
      </ol>
      <p v-if="explanation.warning" class="text-sm font-medium" data-warning>{{ $t(`incident.explain.warning.${explanation.warning}`, values()) }}</p>
      <p v-if="explanation.auto_close && explanation.status !== 'resolved_confirmed' && explanation.status !== 'closed_by_user'" class="text-[13px] text-text-muted" data-auto-close>
        {{ $t(`incident.explain.auto_close.${explanation.auto_close}`, values()) }}
      </p>
    </div>

    <details v-if="technical.length > 0" class="text-sm" data-technical>
      <summary class="cursor-pointer text-text-muted">{{ $t('incident.explain.technical_title') }}</summary>
      <dl class="mt-2 grid gap-1 font-mono text-[13px] sm:grid-cols-[auto_1fr] sm:gap-x-4">
        <template v-for="row in technical" :key="row.key">
          <dt class="text-text-muted">{{ label('technical', row.key) }}</dt>
          <dd class="break-all">{{ row.value }}</dd>
        </template>
      </dl>
    </details>
  </section>
</template>
