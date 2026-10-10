<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ApiError } from '@bw/api-client';
import { createScenario, updateScenario } from '@/api/checks';
import type { CheckScenario, CheckScenarioInput, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fieldErrorsOf } from '@/composables/errors';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import { INTERVALS } from './tones';

const props = defineProps<{ store: Store; scenario: CheckScenario | null; canManage: boolean }>();
const emit = defineEmits<{ saved: [scenario: CheckScenario]; stale: [] }>();
const { dateTime } = useFormat();

const form = reactive({ product_url: '', cart_url: '', checkout_url: '', interval_seconds: 900, enabled: false, origins: '', country: '', postcode: '' });
const editing = ref(false);
const saving = ref(false);
const error = ref<unknown>(null);
const saved = ref(false);

const invalid = computed(() => new Set(fieldErrorsOf(error.value).map((field) => field.split('.')[0]!)));
const verified = computed(() => props.store.verified_at !== null);
const showForm = computed(() => props.canManage && (props.scenario === null || editing.value));

function reset(): void {
  const scenario = props.scenario;
  form.product_url = scenario?.product_url ?? `${props.store.base_url}/`;
  form.cart_url = scenario?.cart_url ?? '';
  form.checkout_url = scenario?.checkout_url ?? '';
  form.interval_seconds = scenario?.interval_seconds ?? 900;
  form.enabled = scenario?.enabled ?? verified.value;
  form.origins = (scenario?.extra_allowed_origins ?? []).join('\n');
  form.country = scenario?.synthetic_location?.country ?? '';
  form.postcode = scenario?.synthetic_location?.postcode ?? '';
}

watch(() => props.scenario, reset, { immediate: true });

function input(): CheckScenarioInput {
  const origins = form.origins.split(/\s+/).map((value) => value.trim()).filter((value) => value !== '');

  return {
    product_url: form.product_url.trim(),
    cart_url: form.cart_url.trim() || null,
    checkout_url: form.checkout_url.trim() || null,
    interval_seconds: form.interval_seconds,
    enabled: form.enabled && verified.value,
    extra_allowed_origins: origins,
    synthetic_location: form.country.trim() === '' ? null : { country: form.country.trim().toUpperCase(), postcode: form.postcode.trim() || null },
  };
}

async function persist(changes: CheckScenarioInput): Promise<void> {
  saving.value = true;
  error.value = null;
  saved.value = false;

  try {
    const result = props.scenario === null ? await createScenario(props.store.id, changes) : await updateScenario(props.scenario, changes);
    editing.value = false;
    saved.value = true;
    emit('saved', result);
  } catch (caught) {
    error.value = caught;

    if (caught instanceof ApiError && caught.status === 409) {
      emit('stale');
    }
  } finally {
    saving.value = false;
  }
}

function toggle(): Promise<void> {
  return persist({ enabled: !props.scenario!.enabled });
}

function hours(seconds: number): string {
  return `checks.interval.s${seconds}`;
}
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="scenario-title" data-panel="scenario">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h2 id="scenario-title" class="text-lg font-semibold">{{ $t('checks.scenario.title') }}</h2>
        <p class="max-w-3xl text-sm text-text-muted">{{ $t('checks.scenario.subtitle') }}</p>
      </div>
      <StatusBadge v-if="scenario" :tone="scenario.enabled ? 'ok' : 'unknown'" :label="scenario.enabled ? $t('checks.scenario.enabled') : $t('checks.scenario.disabled')" />
    </div>

    <p v-if="!scenario && !canManage" class="text-sm text-text-muted">{{ $t('checks.scenario.none') }}</p>

    <template v-if="scenario && !editing">
      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
        <dt class="text-text-muted">{{ $t('checks.scenario.product_url') }}</dt>
        <dd class="break-all font-mono text-[13px]">{{ scenario.product_url ?? '—' }}</dd>
        <dt class="text-text-muted">{{ $t('checks.scenario.cart_url') }}</dt>
        <dd v-if="scenario.cart_url" class="break-all font-mono text-[13px]">{{ scenario.cart_url }}</dd>
        <dd v-else class="text-text-muted">{{ $t('checks.scenario.auto') }}</dd>
        <dt class="text-text-muted">{{ $t('checks.scenario.checkout_url') }}</dt>
        <dd v-if="scenario.checkout_url" class="break-all font-mono text-[13px]">{{ scenario.checkout_url }}</dd>
        <dd v-else class="text-text-muted">{{ $t('checks.scenario.auto') }}</dd>
        <dt class="text-text-muted">{{ $t('checks.scenario.interval') }}</dt>
        <dd>{{ $te(hours(scenario.interval_seconds)) ? $t(hours(scenario.interval_seconds)) : $t('checks.interval.custom', { n: Math.round(scenario.interval_seconds / 60) }) }}</dd>
        <dt class="text-text-muted">{{ $t('checks.scenario.next_due') }}</dt>
        <dd>{{ scenario.enabled && scenario.next_due_at ? dateTime(scenario.next_due_at) : $t('checks.scenario.not_scheduled') }}</dd>
        <dt class="text-text-muted">{{ $t('checks.scenario.version') }}</dt>
        <dd>{{ $t('checks.scenario.version_value', { version: scenario.version, adapter: scenario.adapter_version }) }}</dd>
      </dl>

      <div class="grid gap-4 border-t border-border pt-4 md:grid-cols-2">
        <div class="flex flex-col gap-1.5">
          <h3 class="text-sm font-semibold">{{ $t('checks.scenario.steps') }}</h3>
          <ol class="list-decimal space-y-0.5 pl-5 text-sm">
            <li v-for="step in scenario.supported_steps" :key="step">{{ $te(`incident.facts.step.${step}`) ? $t(`incident.facts.step.${step}`) : step }}</li>
          </ol>
          <p class="text-[13px] text-text-muted">{{ $t('checks.scenario.no_purchase') }}</p>
        </div>
        <div class="flex flex-col gap-1.5">
          <h3 class="text-sm font-semibold">{{ $t('checks.scenario.untested') }}</h3>
          <ul class="list-disc space-y-0.5 pl-5 text-sm">
            <li v-for="component in scenario.untested_components" :key="component">{{ $te(`checks.untested.${component}`) ? $t(`checks.untested.${component}`) : component }}</li>
          </ul>
        </div>
      </div>

      <div v-if="canManage" class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
        <button
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-semibold disabled:opacity-60"
          :class="scenario.enabled ? 'border border-border-strong bg-surface hover:bg-surface-muted' : 'bg-primary text-text-inverse hover:bg-primary-hover'"
          :disabled="saving || (!scenario.enabled && !verified)"
          @click="toggle"
        >
          {{ scenario.enabled ? $t('checks.scenario.disable') : $t('checks.scenario.enable') }}
        </button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" @click="editing = true; saved = false">
          {{ $t('checks.scenario.edit') }}
        </button>
        <span v-if="saved" class="text-sm text-ok" role="status">{{ $t('common.saved') }}</span>
      </div>
    </template>

    <form v-if="showForm" class="flex flex-col gap-4" novalidate @submit.prevent="persist(input())">
      <div class="flex flex-col gap-1.5">
        <label for="scenario-product" class="text-sm font-medium">{{ $t('checks.scenario.product_url') }}</label>
        <input id="scenario-product" v-model="form.product_url" type="url" spellcheck="false" :aria-invalid="invalid.has('product_url')" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
        <p class="text-[13px] text-text-muted">{{ $t('checks.scenario.product_hint') }}</p>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-1.5">
          <label for="scenario-cart" class="text-sm font-medium">{{ $t('checks.scenario.cart_url') }}</label>
          <input id="scenario-cart" v-model="form.cart_url" type="url" spellcheck="false" :placeholder="$t('checks.scenario.auto')" :aria-invalid="invalid.has('cart_url')" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="scenario-checkout" class="text-sm font-medium">{{ $t('checks.scenario.checkout_url') }}</label>
          <input id="scenario-checkout" v-model="form.checkout_url" type="url" spellcheck="false" :placeholder="$t('checks.scenario.auto')" :aria-invalid="invalid.has('checkout_url')" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
        </div>
      </div>
      <div class="flex flex-col gap-1.5 sm:max-w-xs">
        <label for="scenario-interval" class="text-sm font-medium">{{ $t('checks.scenario.interval') }}</label>
        <select id="scenario-interval" v-model.number="form.interval_seconds" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm">
          <option v-for="seconds in INTERVALS" :key="seconds" :value="seconds">{{ $t(hours(seconds)) }}</option>
        </select>
      </div>
      <label class="flex items-start gap-2 text-sm" :class="{ 'text-text-muted': !verified }">
        <input v-model="form.enabled" type="checkbox" class="mt-0.5 size-4 accent-primary" :disabled="!verified" />
        <span>
          {{ $t('checks.scenario.enable_label') }}
          <span v-if="!verified" class="block text-[13px]">{{ $t('checks.readiness.verify_first') }}</span>
        </span>
      </label>
      <details class="rounded-lg border border-border p-3 text-sm">
        <summary class="cursor-pointer font-medium">{{ $t('checks.scenario.advanced') }}</summary>
        <div class="mt-3 flex flex-col gap-3">
          <div class="flex flex-col gap-1.5">
            <label for="scenario-origins" class="font-medium">{{ $t('checks.scenario.origins') }}</label>
            <textarea id="scenario-origins" v-model="form.origins" rows="3" spellcheck="false" :aria-invalid="invalid.has('extra_allowed_origins')" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-[13px]"></textarea>
            <p class="text-[13px] text-text-muted">{{ $t('checks.scenario.origins_hint') }}</p>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="flex flex-col gap-1.5">
              <label for="scenario-country" class="font-medium">{{ $t('checks.scenario.country') }}</label>
              <input id="scenario-country" v-model="form.country" maxlength="2" placeholder="DE" :aria-invalid="invalid.has('synthetic_location')" class="rounded-md border border-border-strong bg-surface px-3 py-2 uppercase" />
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="scenario-postcode" class="font-medium">{{ $t('checks.scenario.postcode') }}</label>
              <input id="scenario-postcode" v-model="form.postcode" maxlength="16" class="rounded-md border border-border-strong bg-surface px-3 py-2" />
            </div>
          </div>
          <p class="text-[13px] text-text-muted">{{ $t('checks.scenario.location_hint') }}</p>
        </div>
      </details>
      <ErrorNotice v-if="error" :error="error" />
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="saving || !/^https:\/\//.test(form.product_url.trim())">
          {{ scenario ? $t('common.save') : $t('checks.scenario.create') }}
        </button>
        <button v-if="scenario" type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium" @click="editing = false; reset()">{{ $t('common.cancel') }}</button>
      </div>
    </form>
    <ErrorNotice v-if="error && !showForm" :error="error" />
  </section>
</template>
