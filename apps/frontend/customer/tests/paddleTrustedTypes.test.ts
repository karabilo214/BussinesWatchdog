import { describe, expect, it } from 'vitest';
import { paddleHtmlText, paddleScriptUrl } from '@bw/security/paddle';

describe('Paddle Trusted Types boundary', () => {
  it('accepts only reviewed SDK CSS and empty clears, not arbitrary HTML or script text', () => {
    const inlineCss = '.paddle-frame-inline::-webkit-scrollbar { display: none !important; }';
    expect(paddleHtmlText(inlineCss)).toBe(inlineCss);
    expect(paddleHtmlText('')).toBe('');
    for (const html of ['window.__injected = true', 'body > div { display: none }', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg/onload=alert(1)>', '<style>body{display:none}</style>']) {
      expect(() => paddleHtmlText(html)).toThrow();
    }
  });
  it('allows only the SDK and the reviewed Retain bootstrap with its one auth parameter', () => {
    for (const url of ['https://cdn.paddle.com/paddle/v2/paddle.js', 'https://public.profitwell.com/js/profitwell.js?auth=paddletoken_live_example']) expect(paddleScriptUrl(url)).toBe(url);
    for (const url of ['javascript:alert(1)', 'https://evil.test/paddle.js', 'https://cdn.paddle.com/other.js', 'https://cdn.paddle.com/paddle/v2/paddle.js?callback=alert', 'https://public.profitwell.com/js/profitwell.js?auth=x&callback=alert', 'https://public.profitwell.com.evil.test/js/profitwell.js?auth=x', 'https://user@public.profitwell.com/js/profitwell.js?auth=x', 'https://public.profitwell.com/js/profitwell.js?auth=x#bad', 'https://public.profitwell.com/js/profitwell.js?auth=']) expect(() => paddleScriptUrl(url)).toThrow();
  });
});
