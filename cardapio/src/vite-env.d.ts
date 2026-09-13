/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

interface ImportMetaEnv {
  /** Base pública dos snapshots, sem barra no fim. Ex.: http://127.0.0.1:9000/la-carte */
  readonly VITE_SNAPSHOT_BASE_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
