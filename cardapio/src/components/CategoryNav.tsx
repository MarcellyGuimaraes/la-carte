import type { Category } from '../types/menu'

type Props = {
  /** Já ordenadas por quem chama. */
  categories: readonly Category[]
}

/**
 * Atalhos para as categorias, fixos no topo enquanto o cliente rola.
 *
 * São âncoras HTML puras, não estado de React: o navegador cuida da rolagem
 * e o link continua funcionando mesmo se o JavaScript falhar.
 */
export function CategoryNav({ categories }: Props) {
  return (
    <nav className="nav" aria-label="Categorias do cardápio">
      <ul className="nav__list">
        {categories.map((category) => (
          <li key={category.id}>
            <a className="nav__link" href={`#category-${category.id}`}>
              {category.name}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  )
}
