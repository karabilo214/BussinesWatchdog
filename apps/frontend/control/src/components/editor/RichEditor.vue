<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { deleteSiteMedia, fetchSiteMedia, uploadSiteMedia, type SiteMedia } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { CONTAINER_STYLES, LINK_STYLES, TEXT_STYLES, editorExtensions } from './extensions';

export interface InsertableBlock {
  id: string;
  name: string;
  category: string;
  html: string;
}

const props = withDefaults(defineProps<{ modelValue: string; label: string; blocks?: InsertableBlock[] }>(), { blocks: () => [] });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const panel = reactive({ open: null as 'link' | 'image' | 'blocks' | null, href: '', linkStyle: '' as string, src: '', alt: '' });

const editor = useEditor({
  content: props.modelValue,
  extensions: editorExtensions(),
  editorProps: { attributes: { class: 'site-content min-h-80 px-4 py-3 outline-none', 'data-visual': '', 'aria-multiline': 'true', role: 'textbox' } },
  onUpdate: () => emit('update:modelValue', html()),
});

function html(): string {
  const current = editor.value;
  if (!current || current.isEmpty) return '';

  return current.getHTML().replace(/(<p><\/p>)+$/, '');
}

watch(
  () => props.modelValue,
  (value) => {
    const current = editor.value;
    if (current && value !== html()) current.commands.setContent(value, { emitUpdate: false });
  },
);

onBeforeUnmount(() => editor.value?.destroy());

const blockType = computed(() => {
  const current = editor.value;
  if (!current) return 'paragraph';
  for (const level of [2, 3, 4] as const) if (current.isActive('heading', { level })) return `h${level}`;

  return 'paragraph';
});

const textStyle = computed(() => {
  const current = editor.value;
  if (!current) return '';
  const type = current.isActive('heading') ? 'heading' : 'paragraph';

  return (current.getAttributes(type).class as string | null) ?? '';
});

const containerStyle = computed(() => (editor.value?.isActive('container') ? ((editor.value.getAttributes('container').class as string | null) ?? '') : null));

function setBlockType(value: string): void {
  const chain = editor.value?.chain().focus();
  if (!chain) return;
  if (value === 'paragraph') chain.setParagraph().run();
  else chain.setHeading({ level: Number(value.slice(1)) as 2 | 3 | 4 }).run();
}

function setTextStyle(value: string): void {
  const current = editor.value;
  if (!current) return;
  const type = current.isActive('heading') ? 'heading' : 'paragraph';
  current.chain().focus().updateAttributes(type, { class: value === '' ? null : value }).run();
}

function setContainerStyle(value: string): void {
  editor.value?.chain().focus().updateAttributes('container', { class: value === '' ? null : value }).run();
}

function wrapInSection(): void {
  editor.value?.chain().focus().wrapIn('container', { tag: 'section' }).run();
}

function liftOutOfSection(): void {
  editor.value?.chain().focus().lift('container').run();
}

function openLink(): void {
  const attributes = editor.value?.getAttributes('link') ?? {};
  panel.href = (attributes.href as string | undefined) ?? '';
  panel.linkStyle = (attributes.class as string | undefined) ?? '';
  panel.open = 'link';
}

function applyLink(): void {
  const chain = editor.value?.chain().focus().extendMarkRange('link');
  if (!chain) return;
  const href = panel.href.trim();
  if (href === '') chain.unsetLink().run();
  else chain.setLink({ href, class: panel.linkStyle === '' ? null : panel.linkStyle }).run();
  panel.open = null;
}

const { t } = useI18n();
const media = ref<SiteMedia[]>([]);
const mediaBusy = ref(false);
const mediaError = ref<unknown>(null);

async function loadMedia(): Promise<void> {
  mediaError.value = null;

  try {
    media.value = await fetchSiteMedia();
  } catch (error) {
    mediaError.value = error;
  }
}

function openImage(): void {
  panel.src = '';
  panel.alt = '';
  panel.open = 'image';
  void loadMedia();
}

async function uploadImage(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = '';
  if (!file) return;
  mediaBusy.value = true;
  mediaError.value = null;

  try {
    const uploaded = await uploadSiteMedia(file);
    media.value = [uploaded, ...media.value.filter((item) => item.id !== uploaded.id)];
    panel.src = uploaded.url;
  } catch (error) {
    mediaError.value = error;
  } finally {
    mediaBusy.value = false;
  }
}

async function removeImage(item: SiteMedia): Promise<void> {
  if (!window.confirm(t('editor.media.delete_confirm', { name: item.name }))) return;
  mediaError.value = null;

  try {
    await deleteSiteMedia(item.id);
    media.value = media.value.filter((other) => other.id !== item.id);
    if (panel.src === item.url) panel.src = '';
  } catch (error) {
    mediaError.value = error;
  }
}

function applyImage(): void {
  const src = panel.src.trim();
  if (src !== '') editor.value?.chain().focus().setImage({ src, alt: panel.alt.trim() }).run();
  panel.open = null;
}

function insertBlock(block: InsertableBlock): void {
  const current = editor.value;
  if (!current) return;
  const { $from } = current.state.selection;

  if ($from.depth === 0) {
    current.chain().focus().insertContentAt($from.pos, block.html).run();
  } else {
    const top = $from.node(1);
    const range = top.isTextblock && top.content.size === 0 ? { from: $from.before(1), to: $from.after(1) } : $from.after(1);
    current.chain().focus().insertContentAt(range, block.html).run();
  }

  panel.open = null;
}

function insertTable(): void {
  editor.value?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
}

const buttonClass = 'rounded px-2 py-1 text-sm font-medium hover:bg-primary-soft disabled:opacity-40';
const activeClass = 'bg-primary-soft text-primary';

defineExpose({ editor });
</script>

<template>
  <div class="flex flex-col rounded-md border border-border-strong bg-surface" data-rich-editor>
    <div v-if="editor" class="flex flex-wrap items-center gap-1 border-b border-border px-2 py-1.5" role="toolbar" :aria-label="label">
      <select :value="blockType" class="rounded border border-border px-1.5 py-1 text-sm" :aria-label="$t('editor.block')" data-tool="block" @change="setBlockType(($event.target as HTMLSelectElement).value)">
        <option value="paragraph">{{ $t('editor.paragraph') }}</option>
        <option value="h2">{{ $t('editor.heading', { level: 2 }) }}</option>
        <option value="h3">{{ $t('editor.heading', { level: 3 }) }}</option>
        <option value="h4">{{ $t('editor.heading', { level: 4 }) }}</option>
      </select>
      <select :value="textStyle" class="rounded border border-border px-1.5 py-1 text-sm" :aria-label="$t('editor.text_style')" data-tool="text-style" @change="setTextStyle(($event.target as HTMLSelectElement).value)">
        <option v-for="style in TEXT_STYLES" :key="style" :value="style">{{ $t(`editor.style.${style || 'none'}`) }}</option>
      </select>
      <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
      <button type="button" :class="[buttonClass, editor.isActive('bold') && activeClass]" :title="$t('editor.bold')" data-tool="bold" @click="editor.chain().focus().toggleBold().run()"><b>B</b></button>
      <button type="button" :class="[buttonClass, editor.isActive('italic') && activeClass]" :title="$t('editor.italic')" data-tool="italic" @click="editor.chain().focus().toggleItalic().run()"><i>I</i></button>
      <button type="button" :class="[buttonClass, editor.isActive('underline') && activeClass]" :title="$t('editor.underline')" data-tool="underline" @click="editor.chain().focus().toggleUnderline().run()"><u>U</u></button>
      <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
      <button type="button" :class="[buttonClass, editor.isActive('bulletList') && activeClass]" :title="$t('editor.bullet_list')" data-tool="bullet-list" @click="editor.chain().focus().toggleBulletList().run()">•≡</button>
      <button type="button" :class="[buttonClass, editor.isActive('orderedList') && activeClass]" :title="$t('editor.ordered_list')" data-tool="ordered-list" @click="editor.chain().focus().toggleOrderedList().run()">1≡</button>
      <button type="button" :class="[buttonClass, editor.isActive('blockquote') && activeClass]" :title="$t('editor.quote')" data-tool="quote" @click="editor.chain().focus().toggleBlockquote().run()">❝</button>
      <button type="button" :class="buttonClass" :title="$t('editor.rule')" data-tool="rule" @click="editor.chain().focus().setHorizontalRule().run()">―</button>
      <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
      <button type="button" :class="[buttonClass, editor.isActive('link') && activeClass]" data-tool="link" @click="openLink">{{ $t('editor.link') }}</button>
      <button type="button" :class="buttonClass" data-tool="image" @click="openImage">{{ $t('editor.image') }}</button>
      <button type="button" :class="buttonClass" data-tool="table" @click="insertTable">{{ $t('editor.table') }}</button>
      <button v-if="blocks.length > 0" type="button" :class="[buttonClass, 'text-primary', panel.open === 'blocks' && activeClass]" data-tool="blocks" @click="panel.open = panel.open === 'blocks' ? null : 'blocks'">{{ $t('editor.insert_block') }}</button>
      <template v-if="editor.isActive('table')">
        <button type="button" :class="buttonClass" data-tool="row" @click="editor.chain().focus().addRowAfter().run()">{{ $t('editor.add_row') }}</button>
        <button type="button" :class="buttonClass" data-tool="column" @click="editor.chain().focus().addColumnAfter().run()">{{ $t('editor.add_column') }}</button>
        <button type="button" :class="buttonClass" data-tool="delete-table" @click="editor.chain().focus().deleteTable().run()">{{ $t('editor.delete_table') }}</button>
      </template>
      <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
      <button type="button" :class="buttonClass" data-tool="section" @click="wrapInSection">{{ $t('editor.wrap_section') }}</button>
      <template v-if="containerStyle !== null">
        <select :value="containerStyle" class="rounded border border-border px-1.5 py-1 text-sm" :aria-label="$t('editor.section_style')" data-tool="section-style" @change="setContainerStyle(($event.target as HTMLSelectElement).value)">
          <option v-for="style in CONTAINER_STYLES" :key="style" :value="style">{{ $t(`editor.section.${style || 'none'}`) }}</option>
        </select>
        <button type="button" :class="buttonClass" data-tool="unwrap" @click="liftOutOfSection">{{ $t('editor.unwrap_section') }}</button>
      </template>
      <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
      <button type="button" :class="buttonClass" :disabled="!editor.can().undo()" :title="$t('editor.undo')" data-tool="undo" @click="editor.chain().focus().undo().run()">↶</button>
      <button type="button" :class="buttonClass" :disabled="!editor.can().redo()" :title="$t('editor.redo')" data-tool="redo" @click="editor.chain().focus().redo().run()">↷</button>
      <slot name="tools" :editor="editor" />
    </div>

    <form v-if="panel.open === 'link'" class="flex flex-wrap items-end gap-2 border-b border-border bg-surface-muted px-3 py-2 text-sm" data-panel="link" @submit.prevent="applyLink">
      <label class="flex min-w-60 flex-1 flex-col gap-1">
        <span class="font-medium">{{ $t('editor.link_href') }}</span>
        <input v-model="panel.href" class="rounded border border-border-strong bg-surface px-2 py-1" placeholder="/ru/pricing" data-field="href" />
      </label>
      <label class="flex flex-col gap-1">
        <span class="font-medium">{{ $t('editor.link_style') }}</span>
        <select v-model="panel.linkStyle" class="rounded border border-border-strong bg-surface px-2 py-1" data-field="link-style">
          <option v-for="style in LINK_STYLES" :key="style" :value="style">{{ $t(`editor.link_styles.${style === '' ? 'plain' : style === 'button' ? 'button' : 'secondary'}`) }}</option>
        </select>
      </label>
      <button type="submit" class="rounded bg-primary px-3 py-1 font-semibold text-text-inverse" data-action="apply-link">{{ $t('editor.apply') }}</button>
      <button type="button" class="rounded border border-border-strong px-3 py-1" @click="panel.open = null">{{ $t('common.cancel') }}</button>
      <p class="w-full text-text-muted">{{ $t('editor.link_hint') }}</p>
    </form>

    <form v-if="panel.open === 'image'" class="flex flex-col gap-2 border-b border-border bg-surface-muted px-3 py-2 text-sm" data-panel="image" @submit.prevent="applyImage">
      <div class="flex flex-wrap items-end gap-2">
        <label class="flex flex-col gap-1">
          <span class="font-medium">{{ $t('editor.media.upload') }}</span>
          <input type="file" accept="image/jpeg,image/png,image/webp" class="text-sm" :disabled="mediaBusy" data-field="file" @change="uploadImage" />
        </label>
        <label class="flex min-w-60 flex-1 flex-col gap-1">
          <span class="font-medium">{{ $t('editor.image_src') }}</span>
          <input v-model="panel.src" class="rounded border border-border-strong bg-surface px-2 py-1" placeholder="https://" data-field="src" />
        </label>
        <label class="flex min-w-40 flex-1 flex-col gap-1">
          <span class="font-medium">{{ $t('editor.image_alt') }}</span>
          <input v-model="panel.alt" class="rounded border border-border-strong bg-surface px-2 py-1" data-field="alt" />
        </label>
        <button type="submit" class="rounded bg-primary px-3 py-1 font-semibold text-text-inverse" data-action="apply-image">{{ $t('editor.apply') }}</button>
        <button type="button" class="rounded border border-border-strong px-3 py-1" @click="panel.open = null">{{ $t('common.cancel') }}</button>
      </div>
      <p class="text-text-muted">{{ mediaBusy ? $t('editor.media.uploading') : $t('editor.media.hint') }}</p>
      <ErrorNotice v-if="mediaError" :error="mediaError" />
      <ul v-if="media.length" class="flex max-h-56 flex-wrap gap-2 overflow-y-auto" data-media-library>
        <li v-for="item in media" :key="item.id" class="flex w-28 flex-col gap-1">
          <button type="button" class="overflow-hidden rounded border bg-surface" :class="panel.src === item.url ? 'border-primary ring-2 ring-primary' : 'border-border'" :title="item.name" :data-media="item.id" @click="panel.src = item.url">
            <img :src="item.url" :alt="item.name" class="h-20 w-full object-cover" loading="lazy" />
          </button>
          <span class="truncate text-xs text-text-muted" :title="item.name">{{ item.name }}</span>
          <button type="button" class="self-start text-xs text-crit hover:underline" :data-delete-media="item.id" @click="removeImage(item)">{{ $t('editor.media.delete') }}</button>
        </li>
      </ul>
    </form>

    <div v-if="panel.open === 'blocks'" class="flex flex-col gap-2 border-b border-border bg-surface-muted px-3 py-2 text-sm" data-panel="blocks">
      <p class="text-text-muted">{{ $t('editor.insert_block_hint') }}</p>
      <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <li v-for="block in blocks" :key="block.id">
          <button type="button" class="flex w-full flex-col items-start rounded-md border border-border bg-surface px-3 py-2 text-left hover:border-primary hover:bg-primary-soft" :data-block="block.id" @click="insertBlock(block)">
            <span class="font-medium">{{ block.name }}</span>
            <span class="text-xs text-text-muted">{{ $te(`editor.category.${block.category}`) ? $t(`editor.category.${block.category}`) : block.category }}</span>
          </button>
        </li>
      </ul>
    </div>

    <EditorContent :editor="editor" class="max-h-[44rem] overflow-y-auto bg-canvas" />
  </div>
</template>
