# CLAUDE.md — Cardápio Digital "La Carte"

Este arquivo orienta o Claude (Claude Code) a trabalhar neste projeto. Leia-o
inteiro antes de escrever ou alterar código.

> **Nota de versão:** este documento já incorpora os deltas de arquitetura do
> painel (painel da plataforma, fluxo de publicação, snapshot versionado, pipeline
> de imagem, tenancy nativo do Filament e Postgres em dev). Onde algo divergir de
> conversas antigas, **vale este arquivo**.

---

## 0. Como me ajudar aqui (regras de ouro)

- **Faça a coisa mais simples que resolve.** Dev solo, ~10 clientes.
  Simplicidade > sofisticação. Solução que exige infra distribuída provavelmente
  está errada para este estágio.
- **Não introduza dependências ou abstrações sem necessidade clara.** Pergunte-se
  "isso resolve uma dor real que já existe?" — se não, não faça.
- **Explique o porquê das decisões** ao propor código. Este projeto também é
  material de estudo do autor.
- **Respeite a separação dos dois lados** (seção 2). Não misture a lógica do
  cardápio (leitura/offline) com a do painel (escrita/gestão).
- **Erros explícitos > exceções escondidas.**
- Em pontos com trade-off relevante, **apresente as opções e espere o ok** em vez
  de escolher silenciosamente. Pontos críticos: modelagem, RLS/isolamento de
  tenant, cache headers do snapshot.

---

## 1. O que é o produto

SaaS de cardápio digital. Cada restaurante ("tenant") tem seu cardápio acessado
pelo cliente final via **QR code na mesa**. O dono gerencia pelo **painel**; a dona
do SaaS administra tudo por um **painel da plataforma**.

- **Escala atual:** MVP com ~10 clientes.
- **Requisito central:** cardápio funcionar bem em **internet ruim / offline**.
- **Time:** 1 desenvolvedor (solo).
- **Duplo objetivo:** produto para vender **e** caso de estudo.

---

## 2. A decisão de arquitetura mais importante: dois lados opostos

O produto são **dois lados com requisitos opostos**. Toda escolha deriva disso.

| Lado | Quem usa | Perfil | Requisito dominante |
|------|----------|--------|---------------------|
| **Cardápio (mesa)** | Cliente final | ~100% leitura, muda pouco | Rápido + offline |
| **Painel (gestão)** | Dono / gerente | Escrita (edita cardápio) | CRUD, tenancy |

Há **três públicos**, mas ainda dois lados: cliente (leitura) · dono (escrita, painel
`/admin`) · dona do SaaS (administração, painel `/plataforma`). Nunca acople o lado
leitura ao lado escrita.

---

## 3. Stack

### Backend + Painéis — Laravel + Filament
- **Framework:** Laravel (PHP)
- **Painéis:** **Filament multi-panel** — `/admin` (dono do restaurante) e
  `/plataforma` (dona do SaaS). Mesma aplicação, dois painéis.
- **Multi-tenancy:** **tenancy nativo do Filament v3** (NÃO usar `stancl/tenancy`).
  Row-level com `tenant_id` + **RLS** do Postgres. O Filament faz o scoping do
  painel; a RLS faz o scoping do banco (defesa em profundidade).
- **Filas/worker:** necessárias já (pipeline de imagem e job de publicação). Em dev,
  driver `database`; em produção, um worker rodando.
- **Billing (futuro):** Laravel Cashier + Stripe. Não agora.

Motivo: para dev solo, Filament entrega CRUD, auth e multi-panel quase prontos.
Tenancy nativo do Filament é mais leve que o stancl e é feito exatamente para o
modelo row-level single-DB.

### Cardápio (mesa) — PWA em Vite + React
- **Build:** Vite + React + TypeScript
- **Offline:** `vite-plugin-pwa` (service worker + cache)
- **Não usar Next.js** (SSR quase não se usa aqui; PWA offline-first briga com
  server components). Vive em `cardapio/`.

### Dados e mídia
- **Banco:** **PostgreSQL em dev E em produção.** (Dev local em Postgres — não
  SQLite — porque **RLS é peça de segurança e SQLite não tem RLS**; precisa ser
  testável localmente.) Herd Pro/DBngin ou Postgres local.
- **Imagens:** upload no Filament → **job** converte para **WebP em 2–3 tamanhos**
  → grava em **R2/S3** → o snapshot referencia a URL final.
- **Entrega do cardápio:** snapshots JSON em object storage + **CDN**.

---

## 4. Arquitetura (fluxo)

```
Cliente (mesa) escaneia QR
        │
        ▼
PWA (Vite/React + service worker)
   lê current.json (rede, no-cache) ─▶ descobre a versão atual
   lê v{n}.json (imutável, cache eterno) ─▶ conteúdo do cardápio (offline)
        ▲
        │ (snapshots servidos via CDN — NÃO batem no Postgres)
        │
Object Storage (R2/S3) + CDN ◀── job de PUBLICAÇÃO grava v{n}.json e atualiza current.json
        ▲                                   ▲
        │ (imagens WebP)                     │ dispara no botão "Publicar"
Job de imagem ◀── upload                Painel /admin (dono) ── escreve RASCUNHO ──▶ Postgres (RLS por tenant_id)
                                        Painel /plataforma (dona do SaaS) ── cria/ativa restaurantes ──▶ Postgres
```

- **Leitura (mesa):** só toca CDN. Milhares de visualizações não tocam o banco.
- **Escrita (dono):** edita rascunho no Postgres pelo `/admin`.
- **Publicação:** só o botão "Publicar" gera snapshot (ver seção 6).
- **Plataforma:** onboarding e ativação de restaurantes pelo `/plataforma`.

---

## 5. Multi-tenancy e isolamento (crítico)

**Estratégia:** row-level — `tenant_id` em cada tabela + **RLS do Postgres**.
Duas camadas COMPLEMENTARES:

1. **Filament tenancy (nativo v3)** — scopa o painel `/admin`: seletor de tenant,
   queries do painel já filtradas. É UX + primeira barreira.
2. **RLS (Postgres)** — última linha de defesa no banco. Pega qualquer coisa que
   escape da lógica de aplicação.

**Costura obrigatória:** com RLS ligada, **toda requisição precisa dizer ao Postgres
qual é o tenant atual** (ex.: `SET app.current_tenant = <id>` na sessão, via
middleware por request). O Filament fornece o tenant; ainda é preciso empurrá-lo
para a sessão do Postgres, senão a RLS bloqueia tudo.

**Painel da plataforma FURA o scoping:** a dona do SaaS vê TODOS os tenants. O
mecanismo decidido é um **papel de banco Postgres com `BYPASSRLS`**, usado **só**
pelo painel `/plataforma`. Essa exceção NUNCA pode vazar para o painel `/admin` do
dono (o dono continua 100% preso à RLS). Ou seja: `/plataforma` roda numa conexão
com role BYPASSRLS + bypass do scoping do Filament; `/admin` roda na conexão normal,
restrita. Desenhar isso de propósito — senão ou o painel da plataforma vem vazio, ou
(pior) o bypass vaza e quebra o isolamento.

**Regras firmes:**
- `tenant_id` desde a primeira migration (já feito).
- Testar isolamento de verdade: criar 2 tenants e confirmar que um não vê o outro,
  **inclusive no nível de RLS** (não só no Filament).
- Não usar schema-por-tenant nem banco-por-tenant (overkill nesta escala).

---

## 6. Publicação e snapshot (fluxo de escrita → leitura)

**Rascunho vs. publicado:** o **banco é sempre o rascunho**. O dono edita à vontade
em `/admin` — isso NÃO afeta o cliente. O **snapshot** é o publicado.

**Gatilho = botão "Publicar":** o snapshot NÃO regenera a cada save. Só o botão
"Publicar" dispara o **job** que gera o snapshot. Isso evita cardápio pela metade no
cliente e junta várias edições num job só.

**Snapshot versionado (2 arquivos):**
- **`v{n}.json`** — versão **imutável** do cardápio publicado. Servido com
  `Cache-Control: immutable` + max-age longo. O service worker pode cachear eterno.
- **`current.json`** — **ponteiro** para a versão atual (ex.: `{ "version": n }`).
  Servido **sempre da rede**, com `no-cache`/`no-store`. É como o cliente sabe que
  há versão nova.

**Ponto único de falha:** todo o esquema depende dos **cache headers corretos**.
`current.json` nunca pode ser cacheado; `v{n}.json` deve ser imutável. Testar isso
explicitamente.

**Retenção — manter no máximo as 3 últimas `v{n}.json` por restaurante.** O próprio
job do "Publicar" faz a faxina: ao gerar a nova `v{n}` e mover o ponteiro, apaga as
versões que passarem das 3. As 3 existem só para **cache seguro + rollback rápido**,
não como histórico. Isso mantém o storage praticamente constante, sem limpeza manual.
Não acumular versões antigas.

**Offline (service worker):** carrega `current.json` da rede quando há sinal →
descobre a versão → busca/serve o `v{n}.json` correspondente (do cache se já tiver).
Sem rede: abre o último `v{n}.json` cacheado. Cardápio real continua abrindo offline.

---

## 7. Pipeline de imagem

Upload no Filament → **job** (fila) converte para **WebP em 2–3 tamanhos** → grava
em **R2/S3** → o snapshot referencia a **URL final** (não o arquivo original).

- Imagem é o que mais pesa em "internet ruim"; WebP + CDN é o que protege o
  requisito de offline/lentidão.
- Isto torna **fila + worker uma dependência real agora** (dev: driver `database`).

---

## 8. Guardrails — o que NÃO fazer nesta escala (~10 clientes)

**Não fazer** (sem ganho agora): microserviços · Kubernetes · sync engine dedicado
(ElectricSQL/RxDB/PowerSync) · cache distribuído · réplica de leitura · sharding ·
schema/banco por tenant · billing automático · tempo real (Reverb) · auth de API
(Sanctum/Passport, enquanto o cardápio for público).

**Princípio:** construir a arquitetura certa **na versão simples dela**. Plugar
peças novas só quando a dor específica aparecer.

---

## 9. Modelagem (todas as tabelas de negócio com `tenant_id`)

- **tenants** — restaurantes (id, nome, slug, **status/ativo**, plano,
  **current_version** do snapshot, …)
- **categorias** — (id, tenant_id, nome, ordem)
- **itens** — (id, tenant_id, categoria_id, nome, descricao, preco, imagem_url,
  em_destaque, disponivel, ordem)
- **links** — (id, tenant_id, titulo, url, ordem)
- **users** — donos/gerentes (id, tenant_id, …) + flag/role de **super-admin**
  (dona do SaaS) para o painel `/plataforma`.

Esqueleto para destravar; ajuste conforme as regras aparecerem. O estado de
rascunho vive nessas tabelas; o publicado vive nos snapshots.

---

## 10. Fase atual e roteiro

> **ESTADO ATUAL:** PWA do cardápio no ar (Vercel, offline OK no celular).
> Laravel criado em `painel/`. Modelagem com `tenant_id` e seed feitos.
> **PRÓXIMO:** migrar dev local para Postgres, depois os painéis Filament.

**Roteiro (na ordem):**
1. **Postgres local** — trocar o SQLite de dev por Postgres, para RLS ser testável
   localmente. Ajustar `.env` e rodar as migrations no Postgres.
2. **RLS + costura de tenant** — policies de RLS por `tenant_id` e middleware que
   faz `SET app.current_tenant` por request.
3. **Painel `/admin` (Filament + tenancy nativo)** — login + CRUD de Categoria e
   Item (Item com upload de imagem). Scoping por tenant.
4. **Painel `/plataforma` (multi-panel)** — criar restaurante + dono (onboarding),
   listar, ativar/desativar. Super-admin bypassa scoping e RLS.
5. **Pipeline de imagem** — job upload → WebP (2–3 tamanhos) → R2/S3 → URL no snapshot.
6. **Publicação** — botão "Publicar" → job que gera `v{n}.json` (imutável) e
   atualiza `current.json` (ponteiro). Cache headers corretos.
7. **Plugar a PWA** — cardápio consome `current.json` + `v{n}.json`; service worker
   cacheia o imutável e revalida pelo ponteiro. Offline não pode quebrar.

**Depois do MVP (não agora):**
- **Página de links** ("link na bio": WhatsApp, localização, cardápio) — tabela
  `links` já modelada; feature entra após o ciclo principal.
- **Edição offline do painel** — *nice-to-have*, NÃO requisito. Se um dia precisar:
  fila de mutations no IndexedDB + last-write-wins por timestamp (~200 linhas). Nada
  de sync engine dedicado. O dono normalmente edita com internet decente.
- **Billing automático** (Cashier + Stripe). Começa manual, coluna `plano`/status.

**Pontos de "espere meu ok":** passo 2 (RLS) e passo 6 (cache headers) — são os que
custam retrabalho se errados.

---

## 11. Convenções de código

- **TypeScript no front**, tipos como contrato (Tenant, Categoria, Item). O formato
  dos dados da PWA deve bater com o JSON dos snapshots.
- **Imutabilidade e funções puras** onde possível; erro explícito.
- **Componentes pequenos**; dados separados da apresentação.
- **Nomes claros e consistentes** (pt ou en, mas não misture).
- Commits pequenos; cada passo do roteiro é um "pronto" que funciona sozinho.
- Antes de adicionar biblioteca, justifique por que o que já existe não basta.