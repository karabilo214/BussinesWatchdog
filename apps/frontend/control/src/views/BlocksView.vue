<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchPageBlocks, type PageBlock } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import SiteTabs from '@/components/SiteTabs.vue';
import { SITE_LOCALES } from '@/components/site';
import { useFormat } from '@/composables/useFormat';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const { dateTime } = useFormat();
const blocks = ref<PageBlock[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);

async function load(): Promise<void> {
  error.value = null;

  try {
    blocks.value = await fetchPageBlocks();
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
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('section.site.title') }}</h1>
          <p class="text-sm text-text-muted">{{ $t('blocks.hint') }}</p>
        </div>
        <RouterLink :to="{ name: 'owner-block', params: { id: 'new' } }" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" data-action="new-block">{{ $t('blocks.new') }}</RouterLink>
      </div>

      <SiteTabs current="blocks" />

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <p v-else-if="blocks.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('blocks.empty') }}</p>

      <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[40rem] text-sm">
          <thead class="border-b border-border text-left text-text-muted">
            <tr>
              <th class="px-4 py-3 font-medium">{{ $t('blocks.col.name') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('blocks.col.category') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('blocks.col.languages') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('blocks.col.sort') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('blocks.col.updated') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="block in blocks" :key="block.id" :data-block-row="block.id">
              <td class="px-4 py-3">
                <RouterLink :to="{ name: 'owner-block', params: { id: block.id } }" class="font-medium text-primary hover:underline">{{ block.name }}</RouterLink>
              </td>
              <td class="px-4 py-3">{{ $t(`editor.category.${block.category}`) }}</td>
              <td class="px-4 py-3 font-mono uppercase">{{ SITE_LOCALES.filter((locale) => block.bodies[locale]).join(' · ') }}</td>
              <td class="px-4 py-3">{{ block.sort }}</td>
              <td class="px-4 py-3 whitespace-nowrap">{{ dateTime(block.updated_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </OwnerLayout>
</template>
