<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { StatusBadge } from '@bw/ui';
import { fetchContactRequests, type ContactRequest, type ContactStatus } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { CONTACT_TONES } from '@/components/tones';
import { useFormat } from '@/composables/useFormat';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const STATUSES: ContactStatus[] = ['new', 'in_progress', 'closed'];

const route = useRoute();
const router = useRouter();
const { dateTime } = useFormat();
const rows = ref<ContactRequest[]>([]);
const counts = ref<Record<ContactStatus, number>>({ new: 0, in_progress: 0, closed: 0 });
const cursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const status = ref<ContactStatus | ''>(STATUSES.includes(route.query.status as ContactStatus) ? (route.query.status as ContactStatus) : 'new');

async function load(more = false): Promise<void> {
  error.value = null;

  try {
    const query: Record<string, string> = status.value === '' ? {} : { status: status.value };
    const page = await fetchContactRequests(more && cursor.value ? { ...query, cursor: cursor.value } : query);
    rows.value = more ? [...rows.value, ...page.data] : page.data;
    counts.value = page.counts;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function choose(next: ContactStatus | ''): Promise<void> {
  status.value = next;
  await router.replace({ query: next === 'new' ? {} : { status: next === '' ? 'all' : next } });
  await load();
}

onMounted(() => {
  if (route.query.status === 'all') status.value = '';
  void load();
});
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.contacts.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('contacts.hint') }}</p>
      </div>

      <div class="flex flex-wrap gap-2" role="tablist">
        <button
          v-for="option in [...STATUSES, '' as const]"
          :key="option"
          type="button"
          role="tab"
          :aria-selected="status === option"
          class="rounded-md border px-3 py-1.5 text-sm font-medium"
          :class="status === option ? 'border-primary bg-primary text-text-inverse' : 'border-border-strong text-primary hover:bg-primary-soft'"
          :data-status="option || 'all'"
          @click="choose(option)"
        >
          {{ option === '' ? $t('contacts.all') : $t(`contact_status.${option}`) }}
          <span v-if="option !== ''">({{ counts[option] }})</span>
        </button>
      </div>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <p v-else-if="rows.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('contacts.empty') }}</p>

      <ul v-else class="flex flex-col divide-y divide-border rounded-xl border border-border bg-surface">
        <li v-for="row in rows" :key="row.id" class="flex flex-col gap-1 px-4 py-3 text-sm" :data-contact="row.email">
          <div class="flex flex-wrap items-center gap-2">
            <RouterLink :to="{ name: 'owner-contact', params: { id: row.id } }" class="font-medium text-primary hover:underline">{{ row.name }}</RouterLink>
            <span class="text-text-muted">{{ row.email }}<template v-if="row.company"> · {{ row.company }}</template></span>
            <StatusBadge :tone="CONTACT_TONES[row.status]" :label="$t(`contact_status.${row.status}`)" />
            <span class="ml-auto whitespace-nowrap text-text-muted">{{ dateTime(row.created_at) }} · {{ row.locale.toUpperCase() }}</span>
          </div>
          <p class="text-text-muted">{{ row.message }}</p>
        </li>
      </ul>

      <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="more" @click="load(true)">{{ $t('tenants.more') }}</button>
    </div>
  </OwnerLayout>
</template>
