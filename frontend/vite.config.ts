import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  base: './',
  plugins: [tailwindcss()],
  build: {
    outDir: fileURLToPath(new URL('../build', import.meta.url)),
    emptyOutDir: true,
    manifest: 'manifest.json',
  },
});
