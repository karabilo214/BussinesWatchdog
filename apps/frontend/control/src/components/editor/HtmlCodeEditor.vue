<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { autoCloseTags, html } from '@codemirror/lang-html';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { HighlightStyle, bracketMatching, indentOnInput, syntaxHighlighting } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import { EditorView, drawSelection, highlightActiveLine, highlightActiveLineGutter, keymap, lineNumbers } from '@codemirror/view';
import { tags } from '@lezer/highlight';

const props = defineProps<{ modelValue: string; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const host = ref<HTMLElement | null>(null);
let view: EditorView | null = null;

const colours = HighlightStyle.define([
  { tag: [tags.tagName, tags.angleBracket], color: 'var(--color-primary)' },
  { tag: tags.attributeName, color: 'var(--color-warn)' },
  { tag: tags.attributeValue, color: 'var(--color-ok)' },
  { tag: [tags.comment, tags.meta], color: 'var(--color-text-subtle)', fontStyle: 'italic' },
  { tag: tags.character, color: 'var(--color-note)' },
  { tag: tags.invalid, color: 'var(--color-crit)', textDecoration: 'underline wavy' },
]);

const theme = EditorView.theme({
  '&': { backgroundColor: 'var(--color-surface)', color: 'var(--color-text)', fontSize: '13px', minHeight: '20rem', maxHeight: '44rem' },
  '&.cm-focused': { outline: '2px solid var(--color-primary)', outlineOffset: '-1px' },
  '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', lineHeight: '1.25rem' },
  '.cm-content': { caretColor: 'var(--color-text)', padding: '0.5rem 0' },
  '.cm-gutters': { backgroundColor: 'var(--color-surface-muted)', color: 'var(--color-text-subtle)', border: 'none' },
  '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: 'color-mix(in srgb, var(--color-primary-soft) 60%, transparent)' },
  '&.cm-focused .cm-selectionBackground, .cm-selectionBackground': { backgroundColor: 'color-mix(in srgb, var(--color-primary) 22%, transparent)' },
  '.cm-matchingBracket': { backgroundColor: 'color-mix(in srgb, var(--color-ok) 18%, transparent)' },
});

onMounted(() => {
  view = new EditorView({
    parent: host.value as HTMLElement,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        lineNumbers(),
        highlightActiveLineGutter(),
        highlightActiveLine(),
        history(),
        drawSelection(),
        indentOnInput(),
        bracketMatching(),
        html({ autoCloseTags: false }),
        autoCloseTags,
        syntaxHighlighting(colours),
        EditorView.lineWrapping,
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
        EditorView.contentAttributes.of({ 'aria-label': props.label, 'aria-multiline': 'true', spellcheck: 'false', 'data-code': '' }),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) emit('update:modelValue', update.state.doc.toString());
        }),
        theme,
      ],
    }),
  });
});

watch(
  () => props.modelValue,
  (value) => {
    if (view && value !== view.state.doc.toString()) view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
  },
);

onBeforeUnmount(() => view?.destroy());

defineExpose({ view: () => view });
</script>

<template>
  <div ref="host" class="overflow-hidden rounded-md border border-border-strong" data-field="body" data-code-editor />
</template>
