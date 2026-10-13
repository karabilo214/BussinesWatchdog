# ADR 0046: Block templates for the page editor

Status: accepted for now (owner's backlog items 2026-10-13: ready block and section templates inserted from the editor, and a separate section to create and edit them). Chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions

1. **Storage**: `page_blocks` (category, name, HTML per language `bodies` {ru, en, de}, order, who created/changed, archived). Templates are data in the database, not code, so the owner can change them in the panel (next backlog item). Built-in templates are inserted by the migration from `database/data/page_blocks.php`; afterwards they are ordinary templates.
2. **Categories**: hero, features, steps, pricing, faq, cta, text, other — only for grouping and labels.
3. **Built-in set**: hero with two buttons, three features, three steps, plan comparison **without prices**, FAQ (headings + answers, no `details`), call to action, two text cards, note. Names are in Russian (the owner's language); texts are placeholders in each language with links to that language's pages.
4. **No prices in templates**: prices live only on the pricing page from the published catalog (ADR 0039 p. 6). A static price copied into a page would go stale silently; the comparison template links to the pricing page instead.
5. **Insertion**: the visual editor inserts the template of the page language (if missing — the first language it has) after the top-level block with the cursor, or in place of an empty paragraph, so a section is never nested into another section by accident. The page is then saved and sanitized as usual; templates add nothing the sanitizer would not allow.
6. **Access**: `content.manage` (platform owner), like pages.
7. **Own section** (Step 124): «Шаблоны блоков» in the owner panel lists templates (group, languages, order); a template is created and edited with the same visual editor (or HTML) per language and deleted with a reason (archived, audited). Changing or deleting a template never changes pages it was already inserted into — a page keeps its own copy. HTML is sanitized on save like a page body; at least one language must stay.
