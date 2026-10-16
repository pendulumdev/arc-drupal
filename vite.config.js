import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

function jsxInJs() {
  return {
    name: 'jsx-in-js',
    enforce: 'pre',
    async load(id) {
      if (!id.includes('/ui/') || !id.endsWith('.js') || id.includes('node_modules')) {
        return null;
      }
      const source = await readFile(id, 'utf8');
      if (!source.includes('<')) {
        return null;
      }
      const result = await transform(source, {
        loader: 'jsx',
        jsx: 'automatic',
        sourcefile: id,
        format: 'esm',
      });
      return result.code;
    },
  };
}

export default defineConfig({
  plugins: [jsxInJs(), react()],
  test: {
    include: ['ui/**/*.test.js'],
  },
  build: {
    outDir: 'js/build',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'ui/index.js',
      output: {
        format: 'iife',
        entryFileNames: 'app.js',
        assetFileNames: 'app.css',
        inlineDynamicImports: true,
      },
    },
  },
});
