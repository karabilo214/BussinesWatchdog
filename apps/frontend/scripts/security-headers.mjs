// Bundle hashes and HTML switch together with web-static's release symlink.
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const app = process.argv[2];
if (!['customer', 'control'].includes(app)) throw new Error('Expected customer or control');
const dist = resolve(import.meta.dirname, '..', app, 'dist');
let html = (await readFile(resolve(dist, 'index.html'), 'utf8')).replace(/ integrity="[^"]*"/g, '');
const hashes = [];
for (const match of html.matchAll(/<script\b[^>]*\bsrc="([^"]+)"[^>]*><\/script>/g)) {
  const src = match[1];
  if (!src.startsWith(app === 'customer' ? '/app/assets/' : '/assets/')) throw new Error(`Unexpected script: ${src}`);
  const path = src.replace(app === 'customer' ? '/app/' : '/', '');
  const digest = `sha384-${createHash('sha384').update(await readFile(resolve(dist, path))).digest('base64')}`;
  hashes.push(`'${digest}'`);
  html = html.replace(match[0], match[0].replace('<script ', `<script integrity="${digest}" `));
}
if (hashes.length !== 1) throw new Error('Expected one bundled module entry');
const paddle = app === 'customer';
const policy = [
  "default-src 'self'", `script-src ${hashes.join(' ')} 'strict-dynamic'`,
  "object-src 'none'", "base-uri 'none'", "frame-ancestors 'self'", "form-action 'self'",
  // Vue, Tiptap and sandboxed previews set presentation styles at runtime.
  `style-src 'self' 'unsafe-inline'${paddle ? ' https://cdn.paddle.com/paddle/v2/assets/css/paddle.css https://sandbox-cdn.paddle.com/paddle/v2/assets/css/paddle.css' : ''}`, "img-src 'self' data: blob: https:", "font-src 'self'",
  `connect-src 'self'${paddle ? ' https://*.paddle.com https://*.paddle.io' : ''}`,
  `frame-src 'self'${paddle ? ' https://*.paddle.com' : ''}`,
  "require-trusted-types-for 'script'", paddle ? 'trusted-types vue default' : 'trusted-types vue default dompurify',
].join('; ');
await writeFile(resolve(dist, 'index.html'), html);
await writeFile(resolve(dist, 'security.caddy'), [
  `root * /srv/web/current/${app}`,
  `header {$CSP_HEADER:Content-Security-Policy} "${policy}"`,
  'header Cache-Control "no-cache" {', '    match header Content-Type text/html*', '}', '',
].join('\n'));
