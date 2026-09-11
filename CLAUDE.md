# CLAUDE.md — Cardápio Digital "La Carte"

Este arquivo orienta o Claude (Claude Code) a trabalhar neste projeto. Leia-o
inteiro antes de escrever ou alterar código.

---

## 0. Como me ajudar aqui (regras de ouro)

- **Faça a coisa mais simples que resolve.** Este é um projeto de um dev solo com
  ~10 clientes. Simplicidade > sofisticação. Se uma solução exige infra
  distribuída, provavelmente está errada para este estágio.
- **Não introduza dependências ou abstrações sem necessidade clara.** Pergunte-se
  "isso resolve uma dor real que já existe?" — se não, não faça.
- **Explique o porquê das decisões** ao propor código. Este projeto também é
  material de estudo do autor; decisões silenciosas têm menos valor.
- **Respeite a separação dos dois lados** (ver seção 2). Não misture a lógica do
  cardápio (leitura/offline) com a do painel (escrita/gestão).
- **Erros explícitos > exceções escondidas.** Trate falhas como valores previstos,
  não como surpresas.
- Quando uma decisão tiver trade-off relevante, **apresente as opções** em vez de
  escolher silenciosamente.

---

## 1. O que é o produto

SaaS de cardápio digital para restaurantes. Cada restaurante ("tenant") tem seu
cardápio acessado pelo cliente final via **QR code na mesa**. O dono/gerente
gerencia o cardápio por um **painel de administração**.

- **Contexto de escala:** MVP com ~10 clientes.
- **Requisito central:** funcionar bem em **internet ruim / offline** no lado da mesa.
- **Time:** 1 desenvolvedor (solo).
- **Duplo objetivo:** produto para vender **e** caso de estudo (documentar o porquê).

---

## 2. A decisão de arquitetura mais importante: dois lados opostos

O produto são, na prática, **dois produtos com requisitos opostos**. Toda escolha
técnica deriva disso.

| Lado | Quem usa | Perfil de uso | Requisito dominante |
|------|----------|---------------|---------------------|
| **Cardápio (mesa)** | Cliente final | ~100% leitura, muda pouco no dia | Carregar rápido e funcionar offline |
| **Painel (gestão)** | Dono / gerente | Escrita (edita preços, itens) | CRUD fácil, tenancy, billing |

**Por que separar:** as soluções são opostas. A mesa quer ser estática, cacheada
e independente do backend. O painel quer backend robusto com auth e isolamento de
dados. Resolver os dois com a mesma ferramenta piora os dois. Nunca acople esses
lados.

---

## 3. Stack

### Backend + Painel — Laravel
- **Framework:** Laravel (PHP)
- **Multi-tenancy:** pacote `stancl/tenancy`
- **Painel admin:** Filament (CRUD, upload de imagem e auth quase prontos)
- **Billing (quando necessário):** Laravel Cashier + Stripe

Motivo: para um dev solo, Laravel entrega prontas as partes chatas (tenancy,
painel, auth, migrations, filas, billing). Filament sozinho economiza semanas no
painel do dono.

### Cardápio (mesa) — PWA em Vite + React
- **Build/Framework:** Vite + React
- **Offline:** `vite-plugin-pwa` (service worker + cache)
- Alternativa mais leve, se fizer sentido: HTML/JS puro (é quase só leitura)

Motivo: o offline vive no **frontend** (service worker + cache), independente do
backend. Uma PWA pequena e isolada dá controle total sobre o cache offline, que é
o coração do produto. **Não usar Next.js:** o trunfo do Next é SSR, que aqui quase
não se usa (cardápio é estático cacheado), e PWA offline-first briga com server
components. Vite entrega a PWA com menos atrito.

### Dados e mídia
- **Banco:** PostgreSQL (instância gerenciada pequena — Neon, Railway ou RDS mínima)
- **Imagens:** Object storage (S3 ou Cloudflare R2) + CDN, servindo **WebP** comprimido

Motivo (imagens): imagem é o que mais pesa em "internet ruim". WebP + CDN impede o
requisito de offline/lentidão de voltar a morder.

---

## 4. Arquitetura (fluxo)

```
Cliente (mesa) escaneia QR ──▶ PWA (Vite/React, service worker, cache offline)
                                      │  consome JSON cacheado (via CDN)
                                      ▼
                          Endpoint JSON do cardápio (Laravel)
                                      ▲
Dono/Gerente ──▶ Painel (Laravel + Filament) ── lê/escreve ──▶ PostgreSQL (RLS por tenant_id)
                                                                     │
                                                                     ▼
                                                   Object Storage + CDN (imagens WebP)
```

- **Fluxo da mesa:** Laravel expõe um endpoint que serializa o cardápio de cada
  restaurante como **JSON**. A PWA consome, cacheia no service worker e roda
  offline. Servido via **CDN**, milhares de visualizações **não batem no Postgres**.
- **Fluxo do painel:** o dono edita pelo Filament com internet decente. Escreve
  direto no Postgres.

---

## 5. Multi-tenancy

**Estratégia:** *row-level* — coluna `tenant_id` em cada tabela + **Row Level
Security (RLS)** do Postgres.

- Para ~10 clientes, row-level é suficiente e escala até milhares.
- Schema-por-tenant e banco-dedicado são overkill agora (não fazer).
- **Regra firme:** modele com `tenant_id` **desde a primeira migration**. Adicionar
  depois é retrabalho chato. Estrutura certa desde já, sem a complexidade
  operacional que 10 clientes não justificam.

---

## 6. Estratégia offline (requisito central)

### Lado mesa — só leitura (offline CRÍTICO, fazer bem desde já)
- Cardápio gerado como **snapshot JSON** por restaurante.
- Servido via **CDN**.
- PWA com **service worker** cacheia após o primeiro acesso.
- Resultado: cliente escaneia o QR, carrega uma vez, depois abre **instantâneo e
  offline**. Isto NÃO é over-engineering — é o coração do produto e é barato.

### Lado painel — escrita (offline é *nice-to-have*, NÃO requisito)
- Com 10 clientes: **não** montar infra de sync dedicada (ElectricSQL, RxDB,
  PowerSync).
- Se a edição offline for mesmo necessária: **fila de mutations no IndexedDB** +
  **last-write-wins** por timestamp. ~200 linhas honestas, não semanas de infra.
- Justificativa: volume de conflito é baixíssimo ("dono mudou o preço da coca").
  CRDT é desnecessário. Só migrar para algo robusto diante de **dor real**.
- O dono normalmente edita com internet decente — trate isso como nice-to-have.

---

## 7. Guardrails — o que fazer e o que NÃO fazer (10 clientes)

**Fazer:**
- `tenant_id` + RLS desde a primeira migration
- Uma instância pequena de Postgres
- Uma instância da API
- Snapshot JSON + PWA + CDN para o cardápio
- Imagens em WebP via object storage + CDN

**NÃO fazer (não ganha nada nesta escala):**
- Microserviços · Kubernetes · sync engine dedicado · cache distribuído ·
  réplica de leitura · sharding · schema/banco por tenant

**Princípio:** construir a arquitetura **certa na versão simples dela**. Sem cravar
decisões que travem o crescimento, mas sem pagar hoje complexidade que só se
justifica em escala futura. Para um dev solo, over-engineering atrasa mais do que
protege.

---

## 8. Billing

- Cobrança **manual** nos primeiros meses.
- Coluna `plano` no banco para controlar tier/limites.
- Laravel Cashier + Stripe quando quiser automatizar. Não montar billing
  automático no MVP.

---

## 9. Modelagem inicial (ponto de partida, todas com `tenant_id`)

- **tenants** — restaurantes (id, nome, slug, plano, …)
- **categorias** — bebida, petiscos, pratos (id, tenant_id, nome, ordem)
- **itens** — produtos (id, tenant_id, categoria_id, nome, descricao, preco,
  imagem_url, em_destaque, disponivel)
- **links** — links tipo "link na bio" (id, tenant_id, titulo, url, ordem)
- **users** — donos/gerentes do painel (id, tenant_id, …)

Esqueleto para destravar, não a modelagem final. Ajuste conforme as regras de
negócio aparecerem.

---

## 10. Fase atual e roteiro

> **FASE ATUAL: PWA do cardápio primeiro, com dados FIXOS (hardcoded).**
> O Laravel/endpoint JSON vem depois. Comece pela vitrine que o cliente vê.

**Passo a passo da fase atual (PWA-first):**
1. PWA em Vite + React renderizando um cardápio com **dados fixos no código**
   (sem backend ainda). Objetivo: ver categorias + itens na tela.
2. **Separar os dados do layout** — mover o cardápio fixo para um módulo de dados
   único, no **mesmo formato JSON** que o Laravel vai cuspir depois (contrato
   estável). Modele já pensando em `tenant → categorias → itens`.
3. **Mobile-first de verdade** — é onde o cliente vê. Caprichar aqui.
4. Adicionar **service worker** (`vite-plugin-pwa`) cacheando o app + o JSON.
   Testar offline (recarregar sem rede).

**Roteiro geral do projeto (depois da fase atual):**
5. Laravel + Filament, modelando com `tenant_id` (categorias, itens, links).
6. Cadastrar um restaurante de teste com dados reais.
7. Endpoint JSON que expõe o cardápio de um restaurante — **trocar os dados fixos
   da PWA por esse JSON real** (o contrato do passo 2 evita retrabalho).
8. Object storage + CDN para imagens em WebP.
9. (Depois) billing e página de links.

**Por que PWA-first aqui:** o autor quer ver a vitrine funcionando cedo para manter
o momentum e validar com um dono de bar real. O contrato JSON fixado no passo 2 é o
que torna a troca para o backend indolor. Ao construir a PWA, mantenha o formato de
dados idêntico ao que o endpoint Laravel produzirá.

---

## 11. Convenções de código

- **TypeScript no front**, com tipos como contratos. Modele os dados do cardápio
  com tipos explícitos (Tenant, Categoria, Item) desde o passo 1.
- **Imutabilidade e funções puras** onde possível; tratamento de erro explícito.
- **Componentes pequenos e focados**; dados separados da apresentação.
- **Nomes claros** em português ou inglês, mas **consistentes** — não misture.
- Commits pequenos e descritivos. Cada passo do roteiro é um "pronto" que funciona
  sozinho.
- Antes de adicionar biblioteca nova, justifique por que o que já existe não basta.