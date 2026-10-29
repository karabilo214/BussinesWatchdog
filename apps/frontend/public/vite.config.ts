import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
  base: '/site/',
  plugins: [tailwindcss()],
  build: {
    outDir: fileURLToPath(new URL('../../backend/public/site', import.meta.url)),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: { input: ['src/site.css', 'src/paddle.ts'] },
  },
});
