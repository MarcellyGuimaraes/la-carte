import type { Category } from '../types/menu'
import { byOrder } from '../lib/menu'
import { MenuItemCard } from './MenuItemCard'

type Props = {
  category: Category
}

/**
 * Uma seção do cardápio: título da categoria e seus itens, na ordem do dono.
 */
export function CategorySection({ category }: Props) {
  return (
    <section className="category" aria-labelledby={`category-${category.id}`}>
      <h2 className="category__name" id={`category-${category.id}`}>
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
