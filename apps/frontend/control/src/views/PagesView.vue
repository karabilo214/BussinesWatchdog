<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { StatusBadge } from '@bw/ui';
import { createPage, fetchPages, type PageMenu, type PageSummary, type SiteLocale } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { SITE_LOCALES, publicUrl } from '@/components/site';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const router = useRouter();
const pages = ref<PageSummary[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);
const creating = ref(false);
const createError = ref<unknown>(null);
const form = reactive({ slug: '', parent_id: '', menu: 'none' as PageMenu, sort: 0 });

const parents = computed(() => pages.value.filter((page) => page.kind === 'page' && page.parent_id === null));
const slugValid = computed(() => /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(form.slug) && form.slug.length <= 60);
const preview = computed(() => {
  const parent = parents.value.find((page) => page.id === form.parent_id);

  return `/ru/${parent ? `${parent.path}/` : ''}${form.slug || '…'}`;
});
const ordered = computed(() => {
  const roots = pages.value.filter((page) => page.parent_id === null);

  return roots.flatMap((root) => [root, ...pages.value.filter((page) => page.parent_id === root.id)]);
});

function status(page: PageSummary, locale: SiteLocale): { tone: 'ok' | 'note' | 'unknown'; label: string } {
  const state = page.locales[locale];

  if (state.published !== null) return { tone: 'ok', label: state.draft ? 'site.state.published_draft' : 'site.state.published' };
  if (state.draft) return { tone: 'note', label: 'site.state.draft' };

  return { tone: 'unknown', label: page.kind === 'page' ? 'site.state.none' : 'site.state.builtin' };
}

async function load(): Promise<void> {
  error.value = null;

  try {
    pages.value = await fetchPages();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function create(): Promise<void> {
  if (!slugValid.value || creating.value) return;
  creating.value = true;
  createError.value = null;

  try {
    const page = await createPage({ slug: form.slug, parent_id: form.parent_id === '' ? null : form.parent_id, menu: form.menu, sort: form.sort });
    await router.push({ name: 'owner-page', params: { id: page.id } });
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
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.site.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('site.hint') }}</p>
      </div>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[48rem] text-sm">
          <thead class="border-b border-border text-left text-text-muted">
            <tr>
              <th class="px-4 py-3 font-medium">{{ $t('site.col.page') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('site.col.menu') }}</th>
              <th v-for="locale in SITE_LOCALES" :key="locale" class="px-4 py-3 font-medium uppercase">{{ locale }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="page in ordered" :key="page.id" :data-page="page.path === '' ? '/' : page.path">
              <td class="px-4 py-3" :class="page.parent_id ? 'pl-10' : ''">
                <RouterLink :to="{ name: 'owner-page', params: { id: page.id } }" class="font-medium text-primary hover:underline">
                  {{ page.locales.ru.title ?? page.locales.en.title ?? page.locales.de.title ?? $t(`site.kind.${page.kind}`) }}
                </RouterLink>
                <span class="block font-mono text-[13px] text-text-muted">{{ publicUrl('ru', page.path).replace('/ru', '/{lang}') }}</span>
                <StatusBadge v-if="page.kind !== 'page'" tone="unknown" :label="$t('site.system')" class="mt-1" />
                <StatusBadge v-if="page.policy_type" tone="note" :label="$t(`site.policy.${page.policy_type}`)" class="mt-1" :data-policy="page.policy_type" />
              </td>
              <td class="px-4 py-3">{{ $t(`site.menu.${page.menu}`) }}</td>
              <td v-for="locale in SITE_LOCALES" :key="locale" class="px-4 py-3">
                <StatusBadge :tone="status(page, locale).tone" :label="$t(status(page, locale).label)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="page-create" @submit.prevent="create">
        <div class="flex flex-col gap-1">
          <h2 class="text-lg font-semibold">{{ $t('site.create.title') }}</h2>
          <p class="text-text-muted">{{ $t('site.create.hint') }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.create.parent') }}</span>
            <select v-model="form.parent_id" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="parent">
              <option value="">{{ $t('site.create.no_parent') }}</option>
              <option v-for="parent in parents" :key="parent.id" :value="parent.id">/{{ parent.path }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.create.slug') }}</span>
            <input v-model.trim="form.slug" maxlength="60" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono" :aria-invalid="form.slug !== '' && !slugValid" data-field="slug" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.col.menu') }}</span>
            <select v-model="form.menu" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="menu">
              <option v-for="menu in (['none', 'header', 'footer'] as const)" :key="menu" :value="menu">{{ $t(`site.menu.${menu}`) }}</option>
            </select>
          </label>
          <label class="flex w-24 flex-col gap-1.5">
            <span class="font-medium">{{ $t('site.sort') }}</span>
            <input v-model.number="form.sort" type="number" min="-1000" max="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="sort" />
          </label>
          <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="!slugValid || creating" data-action="create">{{ $t('site.create.submit') }}</button>
        </div>
        <p class="text-text-muted">{{ $t('site.create.address') }} <span class="font-mono" data-slug-preview>{{ preview }}</span></p>
        <p v-if="form.slug !== '' && !slugValid" class="text-crit">{{ $t('site.create.slug_rules') }}</p>
        <ErrorNotice v-if="createError" :error="createError" />
      </form>
    </div>
  </OwnerLayout>
</template>
