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
          {
            /*
             * O cardápio: stale-while-revalidate.
             *
             * Responde na hora com a cópia em cache e, em paralelo, busca a
             * versão nova para a próxima abertura. A tela nunca espera pela
             * rede, que é o requisito central em internet ruim. O preço é ver
             * a versão de ontem nesta visita; `generated_at` deixa isso visível.
             */
            urlPattern: ({ url }) => url.pathname.startsWith('/menu/'),
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'menu-json',
              expiration: {
                maxEntries: 10,
                maxAgeSeconds: 60 * 60 * 24 * 30,
              },
              cacheableResponse: { statuses: [0, 200] },
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
