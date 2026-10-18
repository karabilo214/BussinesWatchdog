<script setup lang="ts">
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { StatusBadge } from '@bw/ui';
import { archivePage, deletePageVersion, discardPageDraft, fetchPage, fetchPageBlocks, previewPage, publishPage, restorePageVersion, savePageDraft, unpublishPage, updatePage, type PageBlock, type PageCard, type PageMenu, type PolicyType, type SiteLocale } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import RichEditor from '@/components/editor/RichEditor.vue';
import { CONTENT_CLASSES, SITE_LOCALES, publicUrl } from '@/components/site';
import { useFormat } from '@/composables/useFormat';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const HtmlCodeEditor = defineAsyncComponent(() => import('@/components/editor/HtmlCodeEditor.vue'));

const props = defineProps<{ id: string }>();
const router = useRouter();
const { dateTime } = useFormat();
const { t } = useI18n();

const page = ref<PageCard | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref(false);
const notice = ref<string | null>(null);
const locale = ref<SiteLocale>('ru');
const form = reactive({ title: '', description: '', body: '' });
const saved = ref({ title: '', description: '', body: '' });
const settings = reactive({ menu: 'none' as PageMenu, sort: 0, policy: '' as PolicyType | '' });
const confirm = reactive({ action: null as 'publish' | 'unpublish' | 'archive' | 'delete_version' | null, reason: '', version: '' });
const previewHtml = ref('');
const previewError = ref<unknown>(null);
const editorMode = ref<'visual' | 'html'>(storedMode());
const blocks = ref<PageBlock[]>([]);
const insertable = computed(() =>
  blocks.value
    .map((block) => ({ id: block.id, name: block.name, category: block.category, html: block.bodies[locale.value] ?? Object.values(block.bodies)[0] ?? '' }))
    .filter((block) => block.html !== ''),
);
let previewTimer: ReturnType<typeof setTimeout> | null = null;

function storedMode(): 'visual' | 'html' {
  try {
    return window.localStorage.getItem('bw.page-editor-mode') === 'html' ? 'html' : 'visual';
  } catch {
    return 'visual';
  }
}

function setEditorMode(mode: 'visual' | 'html'): void {
  editorMode.value = mode;
  try {
    window.localStorage.setItem('bw.page-editor-mode', mode);
  } catch {
    return;
  }
}

const content = computed(() => page.value?.content[locale.value] ?? null);
const dirty = computed(() => form.title !== saved.value.title || form.description !== saved.value.description || form.body !== saved.value.body);
const history = computed(() => page.value?.history.filter((row) => row.locale === locale.value) ?? []);
const titleReady = computed(() => form.title.trim() !== '' && (page.value?.kind !== 'page' || form.body.trim() !== ''));

function fill(): void {
  const source = content.value?.draft ?? content.value?.published ?? page.value?.builtin?.[locale.value] ?? null;
  const values = { title: source?.title ?? '', description: source?.description ?? '', body: source?.body ?? '' };
  Object.assign(form, values);
  saved.value = { ...values };
  schedulePreview(0);
}

function accept(card: PageCard): void {
  page.value = card;
  settings.menu = card.menu;
  settings.policy = card.policy_type ?? '';
  settings.sort = card.sort;
}

async function loadBlocks(): Promise<void> {
  try {
    blocks.value = await fetchPageBlocks();
  } catch {
    blocks.value = [];
  }
}

async function load(): Promise<void> {
  error.value = null;
  void loadBlocks();

  try {
    accept(await fetchPage(props.id));
    fill();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

function schedulePreview(delay = 600): void {
  if (previewTimer !== null) clearTimeout(previewTimer);
  previewTimer = setTimeout(() => void refreshPreview(), delay);
}

async function refreshPreview(): Promise<void> {
  if (page.value === null || !page.value.editable || form.title.trim() === '') {
    previewHtml.value = '';

    return;
  }

  try {
    previewHtml.value = (await previewPage(page.value.id, locale.value, { title: form.title.trim(), description: form.description.trim() || null, body: form.body })).html;
    previewError.value = null;
  } catch (caught) {
    previewError.value = caught;
  }
}

async function run(action: () => Promise<PageCard | void>, message: string, refill = false): Promise<void> {
  busy.value = true;
  actionError.value = null;
  notice.value = null;

  try {
    const card = await action();
    if (card) accept(card);
    if (refill) fill();
    notice.value = message;
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

async function save(): Promise<void> {
  if (page.value === null || !titleReady.value) return;
  const id = page.value.id;
  await run(() => savePageDraft(id, locale.value, { title: form.title.trim(), description: form.description.trim() || null, body: form.body }), 'site.done.saved', true);
}

async function discard(): Promise<void> {
  if (page.value === null) return;
  const id = page.value.id;
  await run(() => discardPageDraft(id, locale.value), 'site.done.discarded', true);
}

async function restore(versionId: string): Promise<void> {
  if (page.value === null) return;
  const id = page.value.id;
  await run(() => restorePageVersion(id, versionId), 'site.done.restored', true);
}

async function saveSettings(): Promise<void> {
  if (page.value === null) return;
  const id = page.value.id;
  await run(() => updatePage(id, { menu: settings.menu, sort: settings.sort, ...(page.value?.kind === 'page' ? { policy_type: settings.policy === '' ? null : settings.policy } : {}) }), 'site.done.settings');
}

async function confirmAction(): Promise<void> {
  if (page.value === null || confirm.action === null || confirm.reason.trim().length < 3) return;
  const id = page.value.id;
  const reason = confirm.reason.trim();
  const action = confirm.action;

  if (action === 'archive') {
    busy.value = true;
    actionError.value = null;

    try {
      await archivePage(id, reason);
      await router.push({ name: 'owner-site' });
    } catch (caught) {
      actionError.value = caught;
    } finally {
      busy.value = false;
    }

    return;
  }

  if (action === 'delete_version') {
    await run(() => deletePageVersion(id, confirm.version, reason), 'site.done.delete_version');
  } else {
    await run(() => (action === 'publish' ? publishPage(id, locale.value, reason) : unpublishPage(id, locale.value, reason)), `site.done.${action}`, true);
  }

  confirm.action = null;
  confirm.reason = '';
}

function ask(action: 'publish' | 'unpublish' | 'archive' | 'delete_version', version = ''): void {
  confirm.action = action;
  confirm.version = version;
  confirm.reason = '';
  actionError.value = null;
}

function isAllowRemove(row: PageCard['history'][number]): boolean {
  return row.state === 'retired' && !page.value?.policy_type;
}

function removeHistory(versionId: string): void {
  ask('delete_version', versionId);
}

function switchLocale(next: SiteLocale): void {
  if (dirty.value && !window.confirm(t('site.unsaved_switch'))) return;
  locale.value = next;
  confirm.action = null;
  notice.value = null;
  fill();
}

watch(() => [form.title, form.description, form.body], () => schedulePreview());
onMounted(load);
onBeforeUnmount(() => {
  if (previewTimer !== null) clearTimeout(previewTimer);
});
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-7xl flex-col gap-6">
      <RouterLink :to="{ name: 'owner-site' }" class="self-start text-sm text-primary hover:underline">← {{ $t('section.site.title') }}</RouterLink>
      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else-if="page">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ content?.published?.title ?? content?.draft?.title ?? $t(`site.kind.${page.kind}`) }}</h1>
          <p class="font-mono text-sm text-text-muted" data-path>{{ publicUrl(locale, page.path) }}</p>
          <p v-if="page.kind !== 'page'" class="text-sm text-text-muted">{{ $t(`site.system_hint.${page.kind}`) }}</p>
        </div>

        <p v-if="!page.editable" class="rounded-md border border-note-border bg-note-soft px-3 py-2 text-sm text-note" data-in-code>
          {{ $t('site.in_code') }}
          <a :href="publicUrl(locale, page.path)" target="_blank" rel="noopener" class="ml-1 font-medium underline">{{ $t('site.open') }}</a>
        </p>

        <p v-if="notice" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-notice>{{ $t(notice) }}</p>
        <ErrorNotice v-if="actionError" :error="actionError" />

        <template v-if="page.editable">
          <div class="flex flex-wrap gap-2" role="tablist">
            <button
              v-for="code in SITE_LOCALES"
              :key="code"
              type="button"
              role="tab"
              :aria-selected="code === locale"
              class="rounded-md border px-3 py-1.5 text-sm font-medium uppercase"
              :class="code === locale ? 'border-primary bg-primary text-text-inverse' : 'border-border-strong text-primary hover:bg-primary-soft'"
              :data-locale="code"
              @click="switchLocale(code)"
            >
              {{ code }}
              <span v-if="page.locales[code].published" class="ml-1 normal-case">✓</span>
            </button>
          </div>

          <div class="flex flex-wrap items-center gap-2 text-sm" data-state>
            <StatusBadge v-if="content?.published" tone="ok" :label="$t('site.state.published_at', { date: dateTime(content.published.published_at), version: content.published.version })" />
            <StatusBadge v-else tone="unknown" :label="$t(page.kind === 'page' ? 'site.state.none' : 'site.state.builtin')" />
            <StatusBadge v-if="content?.draft" tone="note" :label="$t('site.state.draft_version', { version: content.draft.version })" />
            <span v-if="dirty" class="text-warn" data-unsaved>{{ $t('site.unsaved') }}</span>
            <a v-if="content?.published" :href="publicUrl(locale, page.path)" target="_blank" rel="noopener" class="text-primary hover:underline">{{ $t('site.open') }}</a>
          </div>

          <div class="grid gap-6" :class="editorMode === 'html' && 'xl:grid-cols-2'">
            <form class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="page-draft" @submit.prevent="save">
              <label class="flex flex-col gap-1.5">
                <span class="font-medium">{{ $t('site.field.title') }}</span>
                <input v-model="form.title" maxlength="200" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="title" />
              </label>
              <label class="flex flex-col gap-1.5">
                <span class="font-medium">{{ $t('site.field.description') }}</span>
                <input v-model="form.description" maxlength="300" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="description" />
                <span class="text-text-muted">{{ $t('site.field.description_hint') }}</span>
              </label>
              <div class="flex flex-col gap-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-medium" id="page-body-label">{{ $t('site.field.content') }}</span>
                  <div class="flex rounded-md border border-border-strong text-sm" role="group" :aria-label="$t('site.mode.label')">
                    <button v-for="mode in (['visual', 'html'] as const)" :key="mode" type="button" class="px-3 py-1 font-medium first:rounded-l-md last:rounded-r-md" :class="editorMode === mode ? 'bg-primary text-text-inverse' : 'text-primary hover:bg-primary-soft'" :aria-pressed="editorMode === mode" :data-mode="mode" @click="setEditorMode(mode)">{{ $t(`site.mode.${mode}`) }}</button>
                  </div>
                </div>
                <RichEditor v-if="editorMode === 'visual'" v-model="form.body" :label="$t('site.field.content')" :blocks="insertable" />
                <HtmlCodeEditor v-else v-model="form.body" :label="$t('site.field.content')" />
                <span class="text-text-muted">{{ $t(page.kind === 'page' ? 'site.field.body_hint' : 'site.field.body_hint_system') }}</span>
                <span v-if="editorMode === 'html'" class="text-text-muted">{{ $t('site.mode.html_hint') }}</span>
              </div>
              <details v-if="editorMode === 'html'" class="rounded-md border border-border p-3 text-text-muted">
                <summary class="cursor-pointer font-medium text-text">{{ $t('site.help.title') }}</summary>
                <p class="mt-2">{{ $t('site.help.allowed') }}</p>
                <p class="mt-2">{{ $t('site.help.classes') }}</p>
                <p class="mt-1 font-mono text-[13px]" data-classes>{{ CONTENT_CLASSES.join(' · ') }}</p>
                <p class="mt-2">{{ $t('site.help.example') }}</p>
                <pre class="mt-1 overflow-x-auto rounded bg-surface-muted p-2 font-mono text-[12px] text-text">&lt;section class="hero"&gt;
    &lt;p class="eyebrow"&gt;…&lt;/p&gt;
    &lt;h1&gt;…&lt;/h1&gt;
    &lt;p&gt;…&lt;/p&gt;
    &lt;p class="actions"&gt;&lt;a class="button" href="/ru/pricing"&gt;…&lt;/a&gt;&lt;/p&gt;
  &lt;/section&gt;
  &lt;div class="grid-3"&gt;&lt;div class="card"&gt;…&lt;/div&gt;…&lt;/div&gt;</pre>
              </details>

              <div class="flex flex-wrap gap-2">
                <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !titleReady || !dirty" data-action="save">{{ $t('site.action.save') }}</button>
                <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || !content?.draft || dirty" data-action="publish" @click="ask('publish')">{{ $t('site.action.publish') }}</button>
                <button v-if="content?.draft" type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted disabled:opacity-60" :disabled="busy" data-action="discard" @click="discard">{{ $t('site.action.discard') }}</button>
                <button v-if="content?.published" type="button" class="rounded-md border border-crit-border px-4 py-2 font-medium text-crit hover:bg-crit-soft disabled:opacity-60" :disabled="busy" data-action="unpublish" @click="ask('unpublish')">{{ $t('site.action.unpublish') }}</button>
              </div>
              <p v-if="dirty && content?.draft" class="text-text-muted">{{ $t('site.save_first') }}</p>
            </form>

            <section class="flex flex-col gap-2" :aria-label="$t('site.preview')">
              <div class="flex items-center justify-between gap-2 text-sm">
                <span class="font-medium">{{ $t('site.preview') }}</span>
                <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" data-action="refresh-preview" @click="refreshPreview">{{ $t('site.action.refresh') }}</button>
              </div>
              <ErrorNotice v-if="previewError" :error="previewError" />
              <iframe :srcdoc="previewHtml" sandbox="" :title="$t('site.preview')" class="h-[44rem] w-full rounded-xl border border-border bg-surface" data-preview />
            </section>
          </div>

          <form v-if="confirm.action" class="flex flex-col gap-3 rounded-xl border border-note-border bg-note-soft p-5 text-sm" :data-form="confirm.action" @submit.prevent="confirmAction">
            <p class="font-medium">{{ $t(`site.confirm.${confirm.action}`, { locale: locale.toUpperCase(), path: publicUrl(locale, page.path), version: page.history.find((row) => row.id === confirm.version)?.version ?? '' }) }}</p>
            <label class="flex flex-col gap-1.5">
              <span class="font-medium">{{ $t('site.confirm.reason') }}</span>
              <textarea v-model="confirm.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
            </label>
            <div class="flex flex-wrap gap-2">
              <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || confirm.reason.trim().length < 3" data-action="confirm">{{ $t(`site.action.${confirm.action}`) }}</button>
              <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="confirm.action = null">{{ $t('common.cancel') }}</button>
            </div>
          </form>

          <section class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 text-sm" data-history>
            <h2 class="text-lg font-semibold">{{ $t('site.history.title', { locale: locale.toUpperCase() }) }}</h2>
            <p v-if="history.length === 0" class="text-text-muted">{{ $t('site.history.empty') }}</p>
            <table v-else class="w-full text-sm">
              <thead class="border-b border-border text-left text-text-muted">
                <tr>
                  <th class="py-2 pr-4 font-medium">{{ $t('site.history.version') }}</th>
                  <th class="py-2 pr-4 font-medium">{{ $t('site.field.title') }}</th>
                  <th class="py-2 pr-4 font-medium">{{ $t('site.history.published') }}</th>
                  <th class="py-2 pr-4 font-medium">{{ $t('site.history.retired') }}</th>
                  <th class="py-2" />
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="row in history" :key="row.id" :data-version="row.version">
                  <td class="py-2 pr-4">v{{ row.version }} <StatusBadge :tone="row.state === 'published' ? 'ok' : 'unknown'" :label="$t(row.state === 'published' ? 'site.history.published_state' : 'site.history.retired')" /></td>
                  <td class="py-2 pr-4">{{ row.title }}</td>
                  <td class="py-2 pr-4 whitespace-nowrap">{{ dateTime(row.published_at) }}<span v-if="row.published_by" class="text-text-muted"> · {{ row.published_by }}</span></td>
                  <td class="py-2 pr-4 whitespace-nowrap">{{ dateTime(row.retired_at) }}</td>
                  <td class="py-2 text-right">
                    <button type="button" class="rounded-md border border-border-strong px-3 py-1 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" data-action="restore" @click="restore(row.id)">{{ $t('site.action.restore') }}</button>
                    <button v-if="isAllowRemove(row)" type="button" class="ml-1 rounded-md border border-crit-border px-3 py-1 text-sm font-medium text-crit hover:bg-crit-soft disabled:opacity-60" :disabled="busy" data-action="remove" @click="removeHistory(row.id)">{{ $t('site.action.remove') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>
        </template>

        <form class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-5 text-sm" data-form="page-settings" @submit.prevent="saveSettings">
          <h2 class="w-full text-lg font-semibold">{{ $t('site.settings') }}</h2>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.col.menu') }}</span>
            <select v-model="settings.menu" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="menu">
              <option v-for="menu in (['none', 'header', 'footer'] as const)" :key="menu" :value="menu">{{ $t(`site.menu.${menu}`) }}</option>
            </select>
          </label>
          <label class="flex w-24 flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.sort') }}</span>
            <input v-model.number="settings.sort" type="number" min="-1000" max="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="sort" />
          </label>
          <label v-if="page.kind === 'page'" class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.policy.label') }}</span>
            <select v-model="settings.policy" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="policy">
              <option value="">{{ $t('site.policy.none') }}</option>
              <option v-for="type in (['service_terms', 'privacy_notice', 'recurring_payment'] as const)" :key="type" :value="type">{{ $t(`site.policy.${type}`) }}</option>
            </select>
          </label>
          <button type="submit" class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" data-action="save-settings">{{ $t('site.action.save_settings') }}</button>
          <button v-if="page.kind === 'page'" type="button" class="ml-auto rounded-md border border-crit-border px-4 py-2 font-medium text-crit hover:bg-crit-soft" data-action="archive" @click="ask('archive')">{{ $t('site.action.archive') }}</button>
          <p class="w-full text-text-muted">{{ $t('site.slug_fixed') }}</p>
          <p v-if="page.kind === 'page'" class="w-full text-text-muted">{{ $t('site.policy.hint') }}</p>
        </form>
      </template>
    </div>
  </OwnerLayout>
</template>
