import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      /* O service worker novo assume sozinho: quem está na mesa não decide nada. */
      registerType: 'autoUpdate',

      /* Permite testar o offline com `npm run dev`, não só no build. */
      devOptions: { enabled: true },

      workbox: {
        /*
         * Precache do app (cache-first implícito). É seguro porque o Vite
         * versiona cada arquivo por hash: o conteúdo nunca muda sob o mesmo
         * nome, então servir do cache não serve nada velho.
         */
        globPatterns: ['**/*.{js,css,html,svg,woff2}'],

        /* Qualquer rota cai no index.html: a PWA é uma página só. */
        navigateFallback: 'index.html',

        runtimeCaching: [
          /*
           * O cardápio publicado: menus/{slug}/v{n}.json, CacheFirst.
           *
           * Um v{n} nunca muda (o painel nunca reescreve um número), então
           * depois de baixado não há motivo para voltar à rede: responde do
           * aparelho na hora, com ou sem sinal. É isto que abre offline.
           * Quem descobre que há versão nova é o current.json, que de
           * propósito NÃO tem rota aqui: passa direto para a rede, sempre.
           */
          {
            urlPattern: ({ url }) => /^\/(?:.+\/)?menus\/[a-z0-9-]+\/v\d+\.json$/.test(url.pathname),
            handler: 'CacheFirst',
            options: {
              cacheName: 'menu-versions',
              expiration: {
                /*
                 * Só por quantidade, nunca por idade: expirar por tempo
                 * apagaria o único cardápio de quem ficou um mês sem abrir.
                 * Versões antigas não voltam a ser usadas e saem primeiro.
                 */
                maxEntries: 20,
              },
              /*
               * Só 200. Resposta opaca (status 0) poderia ser um 404 ou erro
               * disfarçado, e CacheFirst o guardaria para sempre. O bucket
               * responde com CORS, então a resposta nunca é opaca.
               */
              cacheableResponse: { statuses: [200] },
            },
          },
          {
            /*
             * Imagens do cardápio (passo 8): cache-first.
             *
             * Imagem é o que mais pesa em rede ruim e a URL virá versionada
             * pelo CDN, então revalidar não traria nada.
             */
            urlPattern: ({ request }) => request.destination === 'image',
            handler: 'CacheFirst',
            options: {
              cacheName: 'menu-images',
              expiration: {
                maxEntries: 100,
                maxAgeSeconds: 60 * 60 * 24 * 90,
              },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
        ],
      },

      manifest: {
        name: 'Bar do Tonho — Cardápio',
        short_name: 'Cardápio',
        description: 'Cardápio digital do Bar do Tonho.',
        lang: 'pt-BR',
        start_url: '.',
        display: 'standalone',
        background_color: '#14110f',
        theme_color: '#14110f',
        /* PENDENTE: PNGs de 192 e 512 px quando houver identidade visual. */
        icons: [
          {
            src: 'favicon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
            purpose: 'any',
          },
        ],
      },
    }),
  ],
})
