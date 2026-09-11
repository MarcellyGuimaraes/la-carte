import { useEffect, useState } from 'react'
import type { Menu } from './types/menu'
import { loadMenu, type MenuResult } from './data/menu-source'
import { byOrder } from './lib/menu'
import { CategorySection } from './components/CategorySection'

/**
 * ÚNICA ponte entre dados e apresentação.
 *
 * Consome o cardápio de forma assíncrona, como se já viesse da API. Quem
 * fornece os dados é `data/menu-source.ts`; este componente não sabe se a
 * origem é um módulo fixo, um fetch ou o cache do service worker.
 */

type State = { status: 'loading' } | { status: 'done'; result: MenuResult }

export default function App() {
  const [state, setState] = useState<State>({ status: 'loading' })

  useEffect(() => {
    let cancelled = false

    loadMenu().then((result) => {
      if (!cancelled) setState({ status: 'done', result })
    })

    return () => {
      cancelled = true
    }
  }, [])

  if (state.status === 'loading') {
    return <p className="feedback">Carregando cardápio…</p>
  }

  if (!state.result.ok) {
    return <p className="feedback">{state.result.error}</p>
  }

  return <MenuView menu={state.result.menu} />
}

function formatUpdatedAt(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(iso))
}

function MenuView({ menu }: { menu: Menu }) {
  return (
    <div className="app">
      <header className="header">
        <h1 className="header__title">{menu.tenant.name}</h1>
        <p className="header__updated">
          Cardápio atualizado em {formatUpdatedAt(menu.generated_at)}
        </p>
      </header>

      <main className="menu">
        {byOrder(menu.categories).map((category) => (
          <CategorySection key={category.id} category={category} />
        ))}
      </main>

      <footer className="footer">
        <p>Preços sujeitos a alteração. Consulte o garçom.</p>
      </footer>
    </div>
  )
}
