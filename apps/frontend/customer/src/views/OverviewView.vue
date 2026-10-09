<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError } from '@bw/api-client';
import { listStores } from '@/api/stores';
import type { Store } from '@/api/types';
import StoreCard from '@/components/StoreCard.vue';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';

const { canManageStores } = useRole();

const stores = ref<Store[]>([]);
const loading = ref(true);
const error = ref<ApiError | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    stores.value = await listStores();
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught : new ApiError(0, 'unexpected');
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('overview.title') }}</h1>
          <p class="max-w-3xl text-sm text-text-muted">{{ $t('overview.subtitle') }}</p>
        </div>
        <RouterLink
          v-if="canManageStores"
          :to="{ name: 'store-create' }"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover"
        >
          {{ $t('overview.add_store') }}
        </RouterLink>
      </div>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <div v-else-if="error" role="alert" class="flex flex-col items-start gap-3 rounded-xl border border-crit-border bg-crit-soft p-5 text-sm text-crit">
        <p>{{ error.status === 0 ? $t('common.error_network') : $t('common.error_generic') }}</p>
        <p v-if="error.requestId" class="font-mono text-xs">{{ $t('common.request_id', { id: error.requestId }) }}</p>
        <button type="button" class="rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load">
          {{ $t('common.retry') }}
        </button>
      </div>

      <div v-else-if="stores.length === 0" class="flex flex-col gap-1 rounded-xl border border-dashed border-border-strong bg-surface p-8">
        <h2 class="text-lg font-semibold">{{ $t('overview.empty_title') }}</h2>
        <p class="max-w-2xl text-sm text-text-muted">{{ $t('overview.empty_body') }}</p>
      </div>

      <div v-else class="grid gap-4 lg:grid-cols-2">
        <StoreCard v-for="store in stores" :key="store.id" :store="store" />
      </div>
    </div>
  </AppLayout>
</template>
