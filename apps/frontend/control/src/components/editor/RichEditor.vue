<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { CONTAINER_STYLES, LINK_STYLES, TEXT_STYLES, editorExtensions } from './extensions';

const props = defineProps<{ modelValue: string; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const panel = reactive({ open: null as 'link' | 'image' | null, href: '', linkStyle: '' as string, src: '', alt: '' });

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

function openImage(): void {
  panel.src = '';
  panel.alt = '';
  panel.open = 'image';
}

function applyImage(): void {
  const src = panel.src.trim();
  if (src !== '') editor.value?.chain().focus().setImage({ src, alt: panel.alt.trim() }).run();
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

    <form v-if="panel.open === 'image'" class="flex flex-wrap items-end gap-2 border-b border-border bg-surface-muted px-3 py-2 text-sm" data-panel="image" @submit.prevent="applyImage">
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
    </form>

    <EditorContent :editor="editor" class="max-h-[44rem] overflow-y-auto bg-canvas" />
  </div>
</template>
