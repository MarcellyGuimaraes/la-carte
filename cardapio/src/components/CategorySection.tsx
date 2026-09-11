import type { Category } from '../types/menu'
import { byOrder } from '../lib/menu'
import { MenuItemCard } from './MenuItemCard'

type Props = {
  category: Category
}

/**
 * Uma seção do cardápio: título da categoria e seus itens, na ordem do dono.
 * O `id` na section é o alvo das âncoras de `CategoryNav`.
 */
export function CategorySection({ category }: Props) {
  const headingId = `category-${category.id}-heading`

  return (
    <section
      className="category"
      id={`category-${category.id}`}
      aria-labelledby={headingId}
    >
      <h2 className="category__name" id={headingId}>
        {category.name}
      </h2>
      <ul className="category__items">
        {byOrder(category.items).map((item) => (
          <MenuItemCard key={item.id} item={item} />
        ))}
      </ul>
    </section>
  )
}
