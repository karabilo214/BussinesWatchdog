import { describe, expect, it } from 'vitest';
import { sanitizeEditorHtml } from '@/security/trustedTypes';

describe('editor Trusted Types sanitizer', () => {
  it('removes executable markup from legacy editor sinks', () => {
    const clean = sanitizeEditorHtml('<p class="lead">Text</p><img src=x onerror="alert(1)"><script>alert(1)</script><iframe srcdoc="bad"></iframe><a href="javascript:alert(1)">Link</a>');
    expect(clean).toContain('<p class="lead">Text</p>');
    expect(clean).not.toMatch(/onerror|<script|<iframe|javascript:/i);
  });

  it('preserves the complete sandboxed preview and its built stylesheet', () => {
    const clean = sanitizeEditorHtml('<!doctype html><html lang="de"><head><style>p{color:red}</style></head><body><section class="card"><p>Vorschau</p></section></body></html>', true);
    expect(clean).toContain('<style>p{color:red}</style>');
    expect(clean).toContain('<html lang="de">');
    expect(clean).toContain('<section class="card"><p>Vorschau</p></section>');
  });
});
