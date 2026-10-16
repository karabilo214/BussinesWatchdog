<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { fetchPublicStorefrontChecks, type PublicStorefrontCheckHistory } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const { can } = useStaff();
const { dateTime } = useFormat();
const allowed = computed(() => can('service.read'));
const rows = ref<PublicStorefrontCheckHistory[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);

function browser(userAgent: string | null): string {
  if (!userAgent) return '—';
  const match = userAgent.match(/(?:Edg|Firefox|Chrome|Version)\/[\d.]+/);
  if (!match) return userAgent;
  const [name, version] = match[0].split('/');
  return `${name === 'Edg' ? 'Edge' : name === 'Version' ? 'Safari' : name} ${version}`;
}

async function load(more = false): Promise<void> {
  if (!allowed.value) return;
  loading.value = true;
  error.value = null;
  try {
    const page = await fetchPublicStorefrontChecks(more ? cursor.value ?? undefined : undefined);
    rows.value = more ? [...rows.value, ...page.data] : page.data;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

onMounted(() => void load());
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-7xl flex-col gap-5">
      <h1 class="text-2xl font-semibold">{{ $t('section.public_checks.title') }}</h1>
      <p class="text-sm text-text-muted">{{ $t('public_checks.hint') }}</p>
      <p v-if="!allowed" class="text-sm text-text-muted">{{ $t('service.forbidden') }}</p>
      <ErrorNotice v-if="error" :error="error" />
      <p v-if="loading && rows.length === 0" role="status" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
      <p v-else-if="allowed && rows.length === 0 && !error" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('public_checks.empty') }}</p>
      <div v-if="rows.length" class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[900px] text-left text-sm">
          <thead class="bg-surface-muted text-text-muted">
            <tr>
              <th class="px-4 py-3">{{ $t('public_checks.date') }}</th>
              <th class="px-4 py-3">{{ $t('public_checks.url') }}</th>
              <th class="px-4 py-3">{{ $t('public_checks.ip') }}</th>
              <th class="px-4 py-3">{{ $t('public_checks.browser') }}</th>
              <th class="px-4 py-3">{{ $t('public_checks.result') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="row in rows" :key="row.id" :data-check="row.id">
              <td class="whitespace-nowrap px-4 py-3">{{ dateTime(row.created_at) }}</td>
              <td class="max-w-sm break-all px-4 py-3">{{ row.url }}</td>
              <td class="whitespace-nowrap px-4 py-3">{{ row.ip || '—' }}</td>
              <td class="max-w-xs break-all px-4 py-3" :title="row.user_agent || ''">
                {{ browser(row.user_agent) }}
                <span v-if="row.user_agent" class="block text-xs text-text-muted">{{ row.user_agent }}</span>
                <span v-if="row.worker_browser" class="block text-xs text-text-muted">{{ $t('public_checks.worker') }}: {{ row.worker_browser }}</span>
              </td>
              <td class="px-4 py-3">{{ $t(`public_checks.status.${row.status}`) }}<span v-if="row.verdict" class="block text-xs text-text-muted">{{ row.verdict }}</span><span v-if="row.error_code" class="block text-xs text-text-muted">{{ row.error_code }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button v-if="cursor && !loading" type="button" class="self-start rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="more" @click="load(true)">{{ $t('public_checks.more') }}</button>
    </div>
  </OwnerLayout>
</template>
