<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { StatusBadge } from '@bw/ui';
import { auditExportUrl, fetchAudit, type AuditEntry, type AuditFilters, type AuditPage } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const KEYS = ['actor', 'action', 'result', 'tenant', 'from', 'to'] as const;
const TONES = { succeeded: 'ok', denied: 'crit', failed: 'crit', requested: 'note', unknown: 'warn' } as const;

const route = useRoute();
const router = useRouter();
const { te, t } = useI18n();
const { dateTime } = useFormat();
const { can } = useStaff();
const allowed = computed(() => can('audit.read'));
const filters = reactive<Record<(typeof KEYS)[number], string>>(Object.fromEntries(KEYS.map((key) => [key, typeof route.query[key] === 'string' ? (route.query[key] as string) : ''])) as Record<(typeof KEYS)[number], string>);
const entries = ref<AuditEntry[]>([]);
const meta = ref<Pick<AuditPage, 'actions' | 'staff'>>({ actions: [], staff: [] });
const cursor = ref<string | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const open = ref<string | null>(null);

const active = computed<AuditFilters>(() => Object.fromEntries(KEYS.filter((key) => filters[key] !== '').map((key) => [key, filters[key]])));
const exportHref = computed(() => auditExportUrl(active.value));

function action(code: string): string {
  return te(`audit_action.${code}`) ? t(`audit_action.${code}`) : t('audit.unknown_action');
}

async function load(more = false): Promise<void> {
  if (!allowed.value) return;
  busy.value = true;
  error.value = null;

  try {
    const page = await fetchAudit(more && cursor.value ? { ...active.value, cursor: cursor.value } : active.value);
    entries.value = more ? [...entries.value, ...page.data] : page.data;
    cursor.value = page.next_cursor;
    meta.value = { actions: page.actions, staff: page.staff };
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function apply(): Promise<void> {
  await router.replace({ query: active.value });
  await load();
}

function reset(): void {
  for (const key of KEYS) filters[key] = '';
  void apply();
}

onMounted(() => void load());
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-7xl flex-col gap-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('section.audit.title') }}</h1>
          <p class="text-sm text-text-muted">{{ allowed ? $t('audit.hint') : $t('audit.forbidden') }}</p>
        </div>
        <a v-if="allowed" :href="exportHref" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" data-action="export">{{ $t('audit.export') }}</a>
      </div>

      <template v-if="allowed">
        <form class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-4 text-sm" data-form="audit" @submit.prevent="apply">
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('audit.actor') }}</span>
            <select v-model="filters.actor" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="actor">
              <option value="">{{ $t('audit.any') }}</option>
              <option value="system">{{ $t('audit.system') }}</option>
              <option v-for="member in meta.staff" :key="member.id" :value="member.id">{{ member.name ?? $t('audit.system') }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('audit.action') }}</span>
            <select v-model="filters.action" class="max-w-64 rounded-md border border-border-strong bg-surface px-3 py-2" data-field="action">
              <option value="">{{ $t('audit.any') }}</option>
              <option v-for="code in meta.actions" :key="code" :value="code">{{ action(code) }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('audit.result') }}</span>
            <select v-model="filters.result" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="result">
              <option value="">{{ $t('audit.any') }}</option>
              <option v-for="result in (['succeeded', 'denied', 'failed', 'requested', 'unknown'] as const)" :key="result" :value="result">{{ $t(`audit_result.${result}`, result) }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('audit.from') }}</span>
            <input v-model="filters.from" type="date" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="from" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('audit.to') }}</span>
            <input v-model="filters.to" type="date" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="to" />
          </label>
          <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" data-action="apply">{{ $t('audit.apply') }}</button>
          <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="reset">{{ $t('audit.reset') }}</button>
          <span v-if="filters.tenant" class="text-text-muted">{{ $t('audit.tenant_filter') }}</span>
        </form>

        <ErrorNotice v-if="error" :error="error" />
        <p v-if="!busy && entries.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted" data-empty>{{ $t('audit.empty') }}</p>

        <ul v-else class="flex flex-col gap-2 text-sm">
          <li v-for="entry in entries" :key="entry.id" class="flex flex-col gap-1 rounded-lg border border-border bg-surface px-4 py-3" :data-entry="entry.action">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ entry.presentation.title }}</span>
              <StatusBadge :tone="TONES[entry.result]" :label="$t(`audit_result.${entry.result}`, entry.result)" />
              <span class="text-text-muted">{{ entry.actor?.name ?? $t('audit.system') }} · {{ dateTime(entry.created_at) }}</span>
              <RouterLink v-if="entry.presentation.object.href" :to="entry.presentation.object.href" class="text-primary hover:underline">{{ entry.presentation.object.label }}</RouterLink>
              <span v-else class="text-text-muted">{{ entry.presentation.object.label }}</span>
              <button type="button" class="ml-auto text-primary hover:underline" :data-toggle="entry.id" @click="open = open === entry.id ? null : entry.id">{{ open === entry.id ? $t('audit.less') : $t('audit.more') }}</button>
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
      </template>
    </div>
  </OwnerLayout>
</template>
