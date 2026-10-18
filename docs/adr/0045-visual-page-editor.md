# ADR 0045: Visual page editor in the owner panel

Status: accepted for now (owner's backlog item 2026-10-13: "a full editor for non-programmers with the site's Tailwind styles; the choice of editor goes through an ADR"). Chosen by the implementer while the owner was away; confirmed by the owner on 2026-10-16.

## Context

Pages of the public site (ADR 0039) were edited as raw HTML in a text field. The owner wants a visual editor that non-programmers can use, showing the content in the site's styles, and later ready block templates (hero, features, pricing, FAQ, CTA) inserted from the editor. The stored format must stay the sanitized HTML on the content classes, so the public site, preview, versions and audit do not change.

## Options

1. **TipTap 3 (ProseMirror), MIT** — structured WYSIWYG with a schema; Vue 3 bindings; custom nodes for sections with classes; output is clean HTML; about 140 KB gzip in the lazily loaded page editor chunk.
2. **GrapesJS** — drag-and-drop page builder; produces inline styles and its own CSS, which the sanitizer removes (`style` is dropped by design), and a large UI not matching the panel.
3. **Quill / CKEditor 5** — Quill flattens nested blocks (no sections/grids); CKEditor 5 is GPL or commercial.

## Decision

1. TipTap 3.31.4 (`@tiptap/vue-3`, `starter-kit`, `extension-image`, `extension-table`, `pm`, `core`), pinned exactly.
2. Schema: StarterKit (paragraphs, headings 2–4 in the toolbar, bold, italic, underline, lists, quote, divider, links without auto-link), images by https URL with an alt text, tables, a **container** node for `section`/`div`/`article`/`aside`/`header`/`footer`/`figure`/`nav` keeping `class` and `id`, `class`/`id` on paragraphs, headings, lists, quotes, images and tables, and `span` with a class. So the content classes (`hero`, `band`, `card`, `feature`, `grid-2`, `actions`, `lead`, `note`, button links …) survive a round trip.
3. Site styles in the editor: the content rules moved from the site stylesheet into `@bw/design/content.css`, imported by both the site and the panel; the editing area has the `site-content` class, so text looks as on the site. The sandboxed preview with the real layout stays.
4. Toolbar for non-programmers: text type, text look (lead, muted, small, centered, eyebrow, note, warning), bold/italic/underline, lists, quote, divider, link with a look (plain, button, secondary button), image, table (+row, +column, delete), "into section" with a section look (hero, band, card, feature, button row, two/three columns, note, warning, centered) and "remove section", undo/redo.
5. HTML mode stays as a switch ("Visual" / "HTML code", remembered in the browser); its help is shown only there. Markup the visual schema does not know (e.g. `figcaption`, attributes other than class/id/href/src/alt) may be simplified after switching back to visual; the panel says so. The server-side sanitizer remains the only guarantee.
6. The editor does not report a change just because it normalizes loaded HTML, so opening a page is not "unsaved"; trailing empty paragraphs it keeps for the cursor are not saved.

## Consequences

- No backend or contract change. The editor chunk grows by about 140 KB gzip, loaded only on the page editor.
- Block templates (next backlog items) are inserted as HTML into this editor.

## Owner decision (2026-10-16)

- TipTap is confirmed.
- Next: image upload in the editor, syntax highlighting in the HTML mode, and the block templates section moved from the main menu into the site pages section.
- Drag-and-drop of blocks is not needed for now.

## Syntax highlighting in the HTML mode (Step 171)

- The "HTML code" mode of the page and block template editors is CodeMirror 6 (MIT) instead of a plain text field: `@codemirror/state` 6.7.6, `view` 6.43.14, `language` 6.13.1, `lang-html` 6.4.12, `commands` 6.11.1, `autocomplete` 6.20.3, `@lezer/highlight` 1.2.5, pinned exactly. Highlighting of tags, attributes, values, comments and entities in the panel palette (light and dark), line numbers, active line, bracket matching, closing tags completed on `>`/`/`, undo/redo, Tab indents, long lines wrap.
- Alternatives: a self-made highlight layer under a transparent text field (no dependency, but selection, scrolling and IME break easily) and Prism/Shiki (highlight only, no editing). CodeMirror is the common editor component with the smallest footprint for editing.
- The component is loaded only when the HTML mode is opened: about 164 KB gzip in its own chunk; the visual editor chunk stays about 141 KB gzip. The stored value is the same text as before; nothing changes for the backend.
- Step 172: "Block templates" left the main menu of the owner panel; it is a tab next to "Pages" in the "Website" section (`/owner/site/blocks`, `/owner/site/blocks/{id}`; the old `/owner/blocks…` addresses redirect).

