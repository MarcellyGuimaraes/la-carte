import { defineConfig } from 'vitest/config'

/*
 * Config separada do vite.config.ts: os testes são da lógica de dados (sem
 * DOM, sem service worker), então não precisam do plugin do React nem do PWA.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
    /* menu-source.ts quebra alto sem esta variável, de propósito. */
    env: { VITE_SNAPSHOT_BASE_URL: 'https://cdn.test/la-carte' },
    restoreMocks: true,
    unstubGlobals: true,
  },
})
