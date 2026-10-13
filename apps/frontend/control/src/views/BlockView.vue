<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { archivePageBlock, createPageBlock, fetchPageBlock, updatePageBlock, type PageBlock, type PageBlockCategory, type SiteLocale } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import RichEditor from '@/components/editor/RichEditor.vue';
import { SITE_LOCALES } from '@/components/site';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const CATEGORIES: PageBlockCategory[] = ['hero', 'features', 'steps', 'pricing', 'faq', 'cta', 'text', 'other'];

const props = defineProps<{ id: string }>();
const router = useRouter();
const { t } = useI18n();

const creating = computed(() => props.id === 'new');
const loading = ref(!creating.value);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref(false);
const notice = ref<string | null>(null);
const locale = ref<SiteLocale>('ru');
const mode = ref<'visual' | 'html'>('visual');
const form = reactive({ name: '', category: 'other' as PageBlockCategory, sort: 0, bodies: { ru: '', en: '', de: '' } as Record<SiteLocale, string> });
const saved = ref('');
const archive = reactive({ open: false, reason: '' });

const snapshot = computed(() => JSON.stringify(form));
const dirty = computed(() => snapshot.value !== saved.value);
const ready = computed(() => form.name.trim() !== '' && SITE_LOCALES.some((code) => form.bodies[code].trim() !== ''));

function accept(block: PageBlock): void {
  form.name = block.name;
  form.category = block.category;
  form.sort = block.sort;
  for (const code of SITE_LOCALES) form.bodies[code] = block.bodies[code] ?? '';
  saved.value = snapshot.value;
}

async function load(): Promise<void> {
  if (creating.value) {
    saved.value = snapshot.value;

    return;
  }

  try {
    accept(await fetchPageBlock(props.id));
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  if (!ready.value || busy.value) return;
  busy.value = true;
  actionError.value = null;
  notice.value = null;
  const input = { name: form.name.trim(), category: form.category, sort: form.sort, bodies: Object.fromEntries(SITE_LOCALES.map((code) => [code, form.bodies[code].trim() === '' ? null : form.bodies[code]])) as Record<SiteLocale, string | null> };

  try {
    if (creating.value) {
      const block = await createPageBlock(input);
      saved.value = snapshot.value;
      await router.replace({ name: 'owner-block', params: { id: block.id } });
      accept(block);
    } else {
      accept(await updatePageBlock(props.id, input));
    }
    notice.value = 'blocks.saved';
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

async function remove(): Promise<void> {
  if (archive.reason.trim().length < 3 || busy.value) return;
  busy.value = true;
  actionError.value = null;

  try {
    await archivePageBlock(props.id, archive.reason.trim());
    await router.push({ name: 'owner-blocks' });
  } catch (caught) {
    actionError.value = caught;
  } finally {
    busy.value = false;
  }
}

function leave(): void {
  if (dirty.value && !window.confirm(t('blocks.unsaved_leave'))) return;
  void router.push({ name: 'owner-blocks' });
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <button type="button" class="self-start text-sm text-primary hover:underline" @click="leave">← {{ $t('section.blocks.title') }}</button>
      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else>
        <h1 class="text-2xl font-semibold">{{ creating ? $t('blocks.new') : form.name || $t('blocks.untitled') }}</h1>
        <p v-if="notice" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-notice>{{ $t(notice) }}</p>
        <ErrorNotice v-if="actionError" :error="actionError" />

        <form class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="block" @submit.prevent="save">
          <div class="flex flex-wrap items-end gap-3">
            <label class="flex min-w-64 flex-1 flex-col gap-1.5">
              <span class="font-medium">{{ $t('blocks.col.name') }}</span>
              <input v-model="form.name" maxlength="120" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="name" />
            </label>
            <label class="flex flex-col gap-1.5">
              <span class="font-medium">{{ $t('blocks.col.category') }}</span>
              <select v-model="form.category" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="category">
                <option v-for="category in CATEGORIES" :key="category" :value="category">{{ $t(`editor.category.${category}`) }}</option>
              </select>
            </label>
            <label class="flex w-24 flex-col gap-1.5">
              <span class="font-medium">{{ $t('blocks.col.sort') }}</span>
              <input v-model.number="form.sort" type="number" min="-1000" max="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="sort" />
            </label>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-2">
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
                @click="locale = code"
              >
                {{ code }}<span v-if="form.bodies[code].trim() !== ''" class="ml-1 normal-case">✓</span>
              </button>
            </div>
            <div class="flex rounded-md border border-border-strong text-sm" role="group" :aria-label="$t('site.mode.label')">
              <button v-for="item in (['visual', 'html'] as const)" :key="item" type="button" class="px-3 py-1 font-medium first:rounded-l-md last:rounded-r-md" :class="mode === item ? 'bg-primary text-text-inverse' : 'text-primary hover:bg-primary-soft'" :aria-pressed="mode === item" :data-mode="item" @click="mode = item">{{ $t(`site.mode.${item}`) }}</button>
            </div>
          </div>

          <RichEditor v-if="mode === 'visual'" :key="locale" v-model="form.bodies[locale]" :label="$t('blocks.content', { locale: locale.toUpperCase() })" />
          <textarea v-else v-model="form.bodies[locale]" rows="18" spellcheck="false" :aria-label="$t('blocks.content', { locale: locale.toUpperCase() })" class="min-h-80 rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-[13px] leading-5" data-field="body" />
          <p class="text-text-muted">{{ $t('blocks.languages_hint') }}</p>

          <div class="flex flex-wrap items-center gap-2">
            <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !ready || !dirty" data-action="save">{{ $t(creating ? 'blocks.create' : 'blocks.save') }}</button>
            <span v-if="dirty" class="text-warn" data-unsaved>{{ $t('site.unsaved') }}</span>
            <button v-if="!creating" type="button" class="ml-auto rounded-md border border-crit-border px-4 py-2 font-medium text-crit hover:bg-crit-soft" data-action="archive" @click="archive.open = true">{{ $t('blocks.archive') }}</button>
          </div>
        </form>

        <form v-if="archive.open" class="flex flex-col gap-3 rounded-xl border border-note-border bg-note-soft p-5 text-sm" data-form="archive" @submit.prevent="remove">
          <p class="font-medium">{{ $t('blocks.archive_confirm') }}</p>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.confirm.reason') }}</span>
            <textarea v-model="archive.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
          </label>
          <div class="flex flex-wrap gap-2">
            <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || archive.reason.trim().length < 3" data-action="confirm">{{ $t('blocks.archive') }}</button>
            <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="archive.open = false">{{ $t('common.cancel') }}</button>
          </div>
        </form>
      </template>
    </div>
  </OwnerLayout>
</template>
