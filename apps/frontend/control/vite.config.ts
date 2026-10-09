/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

const backend = process.env.BW_BACKEND_URL ?? 'http://127.0.0.1:8000';
const proxy = {
  '/platform-api': { target: backend, changeOrigin: false },
  '/sanctum': { target: backend, changeOrigin: false },
};

export default defineConfig({
  base: '/',
  plugins: [vue(), tailwindcss()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: {
    port: 5174,
    strictPort: true,
    proxy,
    allowedHosts: process.env.BW_ALLOWED_HOSTS ? process.env.BW_ALLOWED_HOSTS.split(',') : undefined,
  },
  preview: { port: 4174, strictPort: true, proxy },
  build: { outDir: 'dist', sourcemap: true },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
  },
});
