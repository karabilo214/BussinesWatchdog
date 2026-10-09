/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import 'vite-ssg';

export default defineConfig({
  base: '/',
  plugins: [vue(), tailwindcss()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: { port: 5175, strictPort: true },
  preview: { port: 4175, strictPort: true },
  build: { outDir: 'dist' },
  ssgOptions: {
    includedRoutes: () => ['/', '/ru', '/en', '/de'],
    dirStyle: 'nested',
    formatting: 'none',
    script: 'async',
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
  },
});
