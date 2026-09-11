import type { Menu } from './types/menu'
import { menuFixture } from './data/menu-fixture'
import { byOrder } from './lib/menu'
import { CategorySection } from './components/CategorySection'

/**
 * ÚNICA ponte entre dados e apresentação.
 *
 * Hoje o cardápio vem de um módulo fixo. Quando o endpoint Laravel existir,
 * só este arquivo muda: `menuFixture` vira o resultado de um fetch cacheado.
 * Nenhum componente abaixo daqui sabe a origem dos dados.
 */
const menu: Menu = menuFixture

function formatUpdatedAt(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(iso))
}

export default function App() {
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
