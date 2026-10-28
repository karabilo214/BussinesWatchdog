<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchMenuSections, saveMenuSection, type MenuSection } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';

const props = defineProps<{ menu?: 'header' | 'footer'; compact?: boolean }>();
const emit = defineEmits<{ saved: [section: MenuSection]; cancel: [] }>();
const { locale } = useI18n();
const sections = ref<MenuSection[]>([]);
const editing = ref('');
const form = reactive({ menu: props.menu ?? 'header', labels: { ru: '', en: '', de: '' }, sort: 0 });
const busy = ref(false);
const error = ref<unknown>(null);
const done = ref(false);
const choices = computed(() => sections.value.filter((section) => !props.menu || section.menu === props.menu));
const valid = computed(() => Object.values(form.labels).every((value) => value.trim().length > 0));
watch(editing, (id) => {
  const section = sections.value.find((row) => row.id === id);
  Object.assign(form, { menu: section?.menu ?? props.menu ?? 'header', labels: { ru: section?.labels.ru ?? '', en: section?.labels.en ?? '', de: section?.labels.de ?? '' }, sort: section?.sort ?? 0 });
  done.value = false;
});
async function load(): Promise<void> {
  try { sections.value = await fetchMenuSections(); } catch (caught) { error.value = caught; }
}
async function save(): Promise<void> {
  if (!valid.value || busy.value) return;
  busy.value = true;
  error.value = null;
  done.value = false;
  try {
    const section = await saveMenuSection(editing.value || null, { menu: form.menu, labels: { ...form.labels }, sort: form.sort });
    sections.value = [...sections.value.filter((row) => row.id !== section.id), section].sort((a, b) => a.sort - b.sort);
    editing.value = section.id;
    await nextTick();
    done.value = true;
    emit('saved', section);
  } catch (caught) { error.value = caught; } finally { busy.value = false; }
}
onMounted(load);
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-menu-sections>
    <h2 class="text-lg font-semibold">{{ $t('site.sections.title') }}</h2>
    <p class="text-text-muted">{{ $t('site.sections.hint') }}</p>
    <ErrorNotice v-if="error" :error="error" />
    <p v-if="done" role="status" class="text-ok">{{ $t('site.sections.saved') }}</p>
    <form class="flex flex-wrap items-end gap-3" data-form="menu-section" @submit.prevent="save">
      <label v-if="!compact" class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('site.sections.edit') }}</span>
        <select v-model="editing" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="section-edit" :disabled="busy">
          <option value="">{{ $t('site.sections.new') }}</option>
          <option v-for="section in choices" :key="section.id" :value="section.id">{{ $t(`site.menu.${section.menu}`) }} — {{ section.labels[locale as 'ru' | 'en' | 'de'] }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('site.col.menu') }}</span>
        <select v-model="form.menu" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="section-menu" :disabled="!!menu || !!editing || busy">
          <option value="header">{{ $t('site.menu.header') }}</option>
          <option value="footer">{{ $t('site.menu.footer') }}</option>
        </select>
      </label>
      <label v-for="lang in (['ru', 'en', 'de'] as const)" :key="lang" class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('site.sections.name') }} · {{ lang.toUpperCase() }}</span>
        <input v-model="form.labels[lang]" required maxlength="40" class="w-48 rounded-md border border-border-strong bg-surface px-3 py-2" :data-field="`section-label-${lang}`" :disabled="busy" />
      </label>
      <label class="flex w-24 flex-col gap-1.5">
        <span class="font-medium">{{ $t('site.sort') }}</span>
        <input v-model.number="form.sort" type="number" min="-1000" max="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="section-sort" :disabled="busy" />
      </label>
      <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="!valid || busy" data-action="save-section">{{ $t(editing ? 'site.action.save_settings' : 'site.sections.create') }}</button>
      <button v-if="compact" type="button" class="px-3 py-2 text-text-muted" @click="emit('cancel')">{{ $t('site.sections.cancel') }}</button>
    </form>
  </section>
</template>
