<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { StatusBadge } from '@bw/ui';
import { fetchTenantAudit, type AuditEntry } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StaffAvatar from '@/components/StaffAvatar.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ tenantId: string }>();
const { dateTime } = useFormat();
const entries = ref<AuditEntry[]>([]);
const cursor = ref<string | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const open = ref<string | null>(null);
const tones = { succeeded: 'ok', denied: 'crit', failed: 'crit', requested: 'note', unknown: 'warn' } as const;

async function load(more = false): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = null;
  try {
    const page = await fetchTenantAudit(props.tenantId, more ? cursor.value ?? undefined : undefined);
    entries.value = more ? [...entries.value, ...page.data] : page.data;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

onMounted(() => load());
watch(() => props.tenantId, () => { entries.value = []; cursor.value = null; open.value = null; void load(); });
</script>

<template>
  <section class="flex flex-col gap-2" data-section="audit">
    <ErrorNotice v-if="error" :error="error" />
    <p v-if="busy && entries.length === 0" role="status" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
    <p v-else-if="!error && entries.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('tenant.audit_empty') }}</p>
    <ul v-else class="flex flex-col gap-2 text-sm">
      <li v-for="entry in entries" :key="entry.id" class="flex flex-col gap-1 rounded-lg border border-border bg-surface px-4 py-3" :data-audit="entry.action">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ entry.presentation.title }}</span>
          <StatusBadge :tone="tones[entry.result]" :label="entry.presentation.result_label" />
          <span class="flex items-center gap-1.5 text-text-muted"><StaffAvatar v-if="entry.actor" :name="entry.actor.name" :photo-url="entry.actor.photo_url ?? null" />{{ entry.actor?.name ?? $t('audit.system') }} · {{ dateTime(entry.created_at) }}</span>
          <RouterLink v-if="entry.presentation.object.href" :to="entry.presentation.object.href" class="text-primary hover:underline">{{ entry.presentation.object.label }}</RouterLink>
          <span v-else class="text-text-muted">{{ entry.presentation.object.label }}</span>
          <button v-if="entry.presentation.details.length" type="button" class="ml-auto text-primary hover:underline" :data-toggle="entry.id" @click="open = open === entry.id ? null : entry.id">{{ open === entry.id ? $t('audit.less') : $t('audit.more') }}</button>
        </div>
        <p class="text-text-muted">{{ entry.presentation.description }}</p>
        <div v-if="entry.presentation.links.length" class="flex flex-wrap gap-3">
          <RouterLink v-for="link in entry.presentation.links" :key="link.href" :to="link.href" class="text-primary hover:underline">{{ link.label }}</RouterLink>
        </div>
        <div v-if="open === entry.id" class="flex flex-col gap-1 rounded-md bg-surface-muted p-3 text-sm" data-details>
          <span v-for="detail in entry.presentation.details" :key="detail">{{ detail }}</span>
        </div>
      </li>
    </ul>
    <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" data-action="more" @click="load(true)">{{ $t('audit.load_more') }}</button>
  </section>
</template>
