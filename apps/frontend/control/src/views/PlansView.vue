<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { StatusBadge, type Tone } from '@bw/ui';
import { createPlan, fetchPlans, type PlanCatalog, type PlanState } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const STATE_TONES: Record<PlanState, Tone> = { draft: 'note', published: 'ok', retired: 'unknown' };

const router = useRouter();
const { can } = useStaff();
const { localized, price, dateTime } = useFormat();
const canManage = computed(() => can('plans.manage'));
const catalog = ref<PlanCatalog | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const creating = ref(false);
const createError = ref<unknown>(null);
const form = reactive({ open: false, code: '', ru: '', en: '', de: '' });

async function load(): Promise<void> {
  try {
    catalog.value = await fetchPlans();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function create(): Promise<void> {
  creating.value = true;
  createError.value = null;

  try {
    const version = await createPlan(form.code.trim(), { ru: form.ru, en: form.en, de: form.de });
    await router.push({ name: 'owner-plan-version', params: { id: version.id } });
  } catch (caught) {
    createError.value = caught;
  } finally {
    creating.value = false;
  }
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-5xl flex-col gap-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('section.plans.title') }}</h1>
          <p class="text-sm text-text-muted">{{ $t('plans.hint') }}</p>
        </div>
        <button
          v-if="canManage && !form.open"
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover"
          data-action="new-plan"
          @click="form.open = true"
        >
          {{ $t('plans.new') }}
        </button>
      </div>

      <p v-if="catalog && !catalog.provider_configured" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm text-warn">{{ $t('plans.provider_missing') }}</p>

      <form v-if="form.open" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" data-form="new-plan" @submit.prevent="create">
        <h2 class="text-lg font-semibold">{{ $t('plans.new') }}</h2>
        <ErrorNotice v-if="createError" :error="createError" />
        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('plans.code') }}</span>
          <input v-model="form.code" required maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono" data-field="code" />
          <span class="text-[13px] text-text-muted">{{ $t('plans.code_hint') }}</span>
        </label>
        <div class="grid gap-3 sm:grid-cols-3">
          <label v-for="locale in (['ru', 'en', 'de'] as const)" :key="locale" class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium">{{ $t('plans.name') }} · {{ locale.toUpperCase() }}</span>
            <input v-model="form[locale]" maxlength="80" class="rounded-md border border-border-strong bg-surface px-3 py-2" :data-field="`name-${locale}`" />
          </label>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="creating || form.code.trim() === ''">
            {{ $t('plans.create') }}
          </button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium" @click="form.open = false">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <p v-else-if="catalog && catalog.data.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('plans.empty') }}</p>

      <article v-for="plan in catalog?.data ?? []" :key="plan.id" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5" :data-plan="plan.code">
        <div class="flex flex-wrap items-center gap-3">
          <h2 class="text-lg font-semibold">{{ localized(plan.versions[0]?.name ?? {}, plan.code) }}</h2>
          <span class="font-mono text-[13px] text-text-muted">{{ plan.code }}</span>
          <StatusBadge :tone="STATE_TONES[plan.state]" :label="$t(`plans.state.${plan.state}`)" />
        </div>
        <ul class="flex flex-col divide-y divide-border rounded-lg border border-border">
          <li v-for="version in plan.versions" :key="version.id" class="flex flex-wrap items-center justify-between gap-3 p-3" :data-version="version.version">
            <div class="flex min-w-0 flex-col gap-1">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ $t('plans.version', { n: version.version }) }}</span>
                <StatusBadge :tone="STATE_TONES[version.state]" :label="$t(`plans.state.${version.state}`)" />
                <span v-if="version.published_at" class="text-[13px] text-text-muted">{{ $t('plans.published_at', { date: dateTime(version.published_at) }) }}</span>
              </div>
              <span class="text-[13px] text-text-muted">
                {{ version.prices.length === 0 ? $t('plans.no_prices') : version.prices.filter((item) => item.kind === 'base').map(price).join(' · ') }}
                · {{ $t('plans.subscribers', { n: version.subscribers }) }}
              </span>
            </div>
            <RouterLink :to="{ name: 'owner-plan-version', params: { id: version.id } }" class="rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft">
              {{ version.state === 'draft' && canManage ? $t('plans.edit') : $t('plans.open') }}
            </RouterLink>
          </li>
        </ul>
      </article>
    </div>
  </OwnerLayout>
</template>
