import { Extension, Mark, Node, mergeAttributes, type Extensions } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';

const CONTAINER_TAGS = ['section', 'div', 'article', 'aside', 'header', 'footer', 'figure', 'nav'];

const classAttribute = {
  default: null,
  parseHTML: (element: HTMLElement) => element.getAttribute('class') || null,
  renderHTML: (attributes: Record<string, unknown>) => (attributes.class ? { class: attributes.class } : {}),
};

const idAttribute = {
  default: null,
  parseHTML: (element: HTMLElement) => element.getAttribute('id') || null,
  renderHTML: (attributes: Record<string, unknown>) => (attributes.id ? { id: attributes.id } : {}),
};

export const Container = Node.create({
  name: 'container',
  group: 'block',
  content: 'block+',
  defining: true,

  addAttributes() {
    return {
      tag: { default: 'div', parseHTML: (element: HTMLElement) => element.tagName.toLowerCase(), renderHTML: () => ({}) },
      class: classAttribute,
      id: idAttribute,
    };
  },

  parseHTML() {
    return CONTAINER_TAGS.map((tag) => ({ tag }));
  },

  renderHTML({ node, HTMLAttributes }) {
    return [node.attrs.tag as string, mergeAttributes(HTMLAttributes), 0];
  },
});

export const StyledSpan = Mark.create({
  name: 'styledSpan',

  addAttributes() {
    return { class: classAttribute };
  },

  parseHTML() {
    return [{ tag: 'span[class]' }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['span', mergeAttributes(HTMLAttributes), 0];
  },
});

export const BlockClasses = Extension.create({
  name: 'blockClasses',

  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'image', 'table', 'horizontalRule'],
        attributes: { class: classAttribute, id: idAttribute },
      },
    ];
  },
});

export function editorExtensions(): Extensions {
  return [
    StarterKit.configure({
      codeBlock: false,
      link: { openOnClick: false, autolink: false, HTMLAttributes: { target: null, rel: null } },
    }),
    Image.configure({ inline: false }),
    TableKit.configure({ table: { resizable: false } }),
    Container,
    StyledSpan,
    BlockClasses,
  ];
}

export const TEXT_STYLES = ['', 'lead', 'muted', 'small', 'center', 'eyebrow', 'note', 'warning'] as const;
export const LINK_STYLES = ['', 'button', 'button button-secondary'] as const;
export const CONTAINER_STYLES = ['', 'hero', 'band', 'card', 'feature', 'actions', 'grid-2', 'grid-3', 'note', 'warning', 'center'] as const;
