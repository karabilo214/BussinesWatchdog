<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ApiError } from '@bw/api-client';
import { saveCheckoutLayout } from '@/api/stores';
import type { CheckoutLayoutFields, Store } from '@/api/types';
import ErrorNotice from './ErrorNotice.vue';

const FIELDS = ['confirm_path', 'finish_path', 'order_form', 'submit_button', 'payment_method', 'error_notice'] as const;
type Field = (typeof FIELDS)[number];

const props = defineProps<{ store: Store; canManage: boolean }>();
const emit = defineEmits<{ updated: [store: Store] }>();

const form = reactive<Record<Field, string>>({ confirm_path: '', finish_path: '', order_form: '', submit_button: '', payment_method: '', error_notice: '' });
const saving = ref(false);
const saved = ref(false);
const error = ref<unknown>(null);
const layout = computed(() => props.store.checkout_layout ?? null);
const fieldErrors = computed<Partial<Record<Field, string>>>(() => {
  if (!(error.value instanceof ApiError)) {
    return {};
  }

  return Object.fromEntries(Object.entries(error.value.fieldErrors).map(([field, codes]) => [field, codes[0]]));
});
const dirty = computed(() => FIELDS.some((field) => form[field].trim() !== (layout.value?.custom[field] ?? '')));

watch(
  layout,
  (current) => {
    for (const field of FIELDS) {
      form[field] = current?.custom[field] ?? '';
    }
  },
  { immediate: true },
);

async function save(): Promise<void> {
  saving.value = true;
  saved.value = false;
  error.value = null;
  const body: CheckoutLayoutFields = {};

  for (const field of FIELDS) {
    if (form[field].trim() !== '') {
      body[field] = form[field].trim();
    }
  }

  try {
    emit('updated', await saveCheckoutLayout(props.store.id, body));
    saved.value = true;
  } catch (caught) {
    error.value = caught;
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section v-if="layout" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="checkout-layout-title" data-panel="checkout-layout">
    <div class="flex flex-col gap-1">
      <h2 id="checkout-layout-title" class="text-lg font-semibold">{{ $t('checkout_layout.title') }}</h2>
      <p class="max-w-3xl text-sm text-text-muted">{{ $t('checkout_layout.intro') }}</p>
    </div>

    <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <div v-for="field in FIELDS" :key="field" class="flex flex-col gap-1.5">
        <label :for="`checkout-layout-${field}`" class="text-sm font-medium">{{ $t(`checkout_layout.field.${field}`) }}</label>
        <input
          :id="`checkout-layout-${field}`"
          v-model="form[field]"
          maxlength="200"
          spellcheck="false"
          :placeholder="layout.defaults[field]"
          :disabled="!canManage"
          :aria-invalid="fieldErrors[field] !== undefined"
          :data-layout-field="field"
          class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm disabled:bg-surface-muted"
        />
        <p v-if="fieldErrors[field]" class="text-[13px] text-crit">{{ $t(`checkout_layout.error.${fieldErrors[field]}`) }}</p>
      </div>
      <p class="text-[13px] text-text-muted sm:col-span-2">{{ $t('checkout_layout.hint') }}</p>
      <div v-if="canManage" class="flex flex-wrap items-center gap-3 sm:col-span-2">
        <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="saving || !dirty" data-action="save-checkout-layout">
          {{ $t('common.save') }}
        </button>
        <span v-if="saved" class="text-sm text-ok" role="status">{{ $t('common.saved') }}</span>
      </div>
    </form>

    <ErrorNotice v-if="error && Object.keys(fieldErrors).length === 0" :error="error" />
  </section>
</template>
