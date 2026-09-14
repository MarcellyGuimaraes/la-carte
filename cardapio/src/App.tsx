import type { Menu } from './types/menu'
import { useMenu } from './data/use-menu'
import { byOrder } from './lib/menu'
import { slugFromPath } from './lib/slug'
import { CategoryNav } from './components/CategoryNav'
import { CategorySection } from './components/CategorySection'
import { PwaStatus } from './components/PwaStatus'
import { ErrorBoundary } from './components/ErrorBoundary'

/**
 * ÚNICA ponte entre dados e apresentação.
 *
 * Quem decide de onde vem o cardápio (cache do aparelho, ponteiro, versão
 * nova) é `data/use-menu.ts`; este componente só desenha o estado.
 */

export default function App() {
  const slug = slugFromPath(window.location.pathname, import.meta.env.BASE_URL)

  if (slug === null) {
    return <p className="feedback">Escaneie o QR code da mesa para abrir o cardápio.</p>
  }

  return (
    <ErrorBoundary>
      <MenuScreen slug={slug} />
    </ErrorBoundary>
  )
}

function MenuScreen({ slug }: { slug: string }) {
  const state = useMenu(slug)

  if (state.status === 'loading') {
    return <p className="feedback">Carregando cardápio…</p>
  }

  if (state.status === 'error') {
    return <p className="feedback">{state.error}</p>
  }

  return <MenuView menu={state.menu} version={state.version} />
}

/**
 * O diagnóstico da PWA não aparece para o cliente na mesa. Só em
 * desenvolvimento, ou quando a URL traz ?debug — útil para conferir o offline
 * em produção, no celular, sem DevTools.
 */
function showDiagnostics(): boolean {
  return import.meta.env.DEV || new URLSearchParams(window.location.search).has("debug")
}

function formatUpdatedAt(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(iso))
}

function MenuView({ menu, version }: { menu: Menu; version: number }) {
  const categories = byOrder(menu.categories)

  return (
    <div className="app">
      <header className="header">
        <h1 className="header__title">{menu.tenant.name}</h1>
        <p className="header__updated">
          Cardápio atualizado em {formatUpdatedAt(menu.generated_at)}
        </p>
      </header>

      <CategoryNav categories={categories} />

      <main className="menu">
        {categories.map((category) => (
          <CategorySection key={category.id} category={category} />
        ))}
      </main>

      <footer className="footer">
        <p>Preços sujeitos a alteração. Consulte o garçom.</p>
        {showDiagnostics() && (
          <>
            <PwaStatus />
            {/* Para conferir no celular qual versão publicada está na tela. */}
            <p className="pwa-status" data-testid="menu-version">versão publicada: v{version}</p>
          </>
        )}
      </footer>
    </div>
  )
}
