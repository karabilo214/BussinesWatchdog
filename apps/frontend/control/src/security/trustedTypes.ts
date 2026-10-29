import DOMPurify from 'dompurify';
import type { TrustedTypesWindow } from 'trusted-types/lib';

/** Legacy HTML sinks in Tiptap/ProseMirror and iframe.srcdoc go through a real sanitizer.
 * Script and ScriptURL sinks have no default conversion and remain blocked.
 * The separate Vue policy is used by Vue's compiled static templates (and server-generated MFA SVG).
 */
export function sanitizeEditorHtml(html: string, preview = false): string {
  return DOMPurify.sanitize(html, {
    RETURN_TRUSTED_TYPE: false,
    WHOLE_DOCUMENT: preview,
    ADD_TAGS: preview ? ['style'] : [], // The sandboxed preview carries the built stylesheet in its head.
    FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'base', ...(preview ? [] : ['style'])],
  });
}

const factory = typeof window === 'undefined' ? undefined : (window as unknown as TrustedTypesWindow).trustedTypes;
if (factory) {
  factory.createPolicy('default', { createHTML: (html, _type, sink) => sanitizeEditorHtml(html, sink === 'HTMLIFrameElement srcdoc') });
}
