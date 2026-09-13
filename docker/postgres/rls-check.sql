-- Verificação manual da RLS, direto no banco, sem Laravel no meio.
--
-- Rodar da raiz do repositório.
--   PowerShell:
--     Get-Content -Encoding UTF8 -Raw docker/postgres/rls-check.sql | docker exec -i la-carte-postgres psql -U la_carte -d la_carte
--   Bash / WSL:
--     docker exec -i la-carte-postgres psql -U la_carte -d la_carte < docker/postgres/rls-check.sql
--
-- Os ERROR saem pelo stderr e podem aparecer uma linha fora de ordem no terminal.
--
-- Pré-requisito: php artisan migrate:fresh --seed --database=pgsql_owner
-- (tenant 1 = Bar do Tonho, 18 itens; tenant 2 = Pizzaria da Nona, 5 itens).
-- Compare cada resultado com o "ESPERADO" impresso logo acima dele.

\set ON_ERROR_STOP off
\pset footer off

-- 1) Como DONO (superuser, ignora RLS).
\echo 'ESPERADO: superuser vê os 2 restaurantes (prova de que os dados existem)'
SELECT id, slug, (SELECT count(*) FROM items i WHERE i.tenant_id = t.id) AS itens
FROM tenants t ORDER BY id;

-- 2) Troca para o papel da APLICAÇÃO, o mesmo que o Laravel usa.
\connect - la_carte_app
\echo ''
\echo '== [app] conectado como:' :USER '=='

\echo ''
\echo 'ESPERADO: sem tenant definido -> 0 em tudo (falha fechada)'
SELECT (SELECT count(*) FROM tenants) AS tenants,
       (SELECT count(*) FROM categories) AS categorias,
       (SELECT count(*) FROM items) AS itens;

\echo ''
\echo 'ESPERADO: tenant = Bar do Tonho -> só bar-do-tonho, 18 itens, 0 da Nona'
SELECT set_config('app.current_tenant', '1', false) AS tenant_atual;
SELECT id, slug FROM tenants;
SELECT count(*) AS itens_visiveis,
       count(*) FILTER (WHERE name = 'Margherita') AS itens_da_nona
FROM items;

\echo ''
\echo 'ESPERADO: mesmo filtrando explicitamente pelo outro tenant -> 0 (RLS ignora o WHERE)'
SELECT count(*) AS itens_da_nona_via_where
FROM items WHERE tenant_id = 2;

\echo ''
\echo 'ESPERADO: UPDATE/DELETE em linha do outro tenant -> UPDATE 0 / DELETE 0'
UPDATE items SET price_cents = 1 WHERE name = 'Margherita';
DELETE FROM items WHERE name = 'Margherita';

\echo ''
\echo 'ESPERADO: ERRO "new row violates row-level security policy"'
INSERT INTO categories (tenant_id, name, sort_order, created_at, updated_at)
VALUES (2, 'Invasão', 1, now(), now());

\echo ''
\echo 'ESPERADO: ERRO também ao tentar MOVER uma linha própria para o outro tenant'
UPDATE categories SET tenant_id = 2 WHERE tenant_id = 1;

\echo ''
\echo 'ESPERADO: ERRO "violates foreign key constraint" ao pôr item próprio em categoria do outro tenant'
\echo '(a RLS não pega isto; quem barra é a FK composta items(category_id, tenant_id))'
-- Categoria 4 = Pizzas, da Nona (o Tonho tem as categorias 1 a 3 no seed).
INSERT INTO items (tenant_id, category_id, name, price_cents, created_at, updated_at)
VALUES (1, 4, 'Intruso', 1, now(), now());

\echo ''
\echo 'ESPERADO: ERRO ao tentar desligar a RLS (papel sem BYPASSRLS)'
SET row_security = off;
SELECT count(*) FROM items;
RESET row_security;

\echo ''
\echo 'ESPERADO: tenant = Pizzaria da Nona -> só pizzaria-da-nona, 5 itens (Margherita intacta, 5900)'
SELECT set_config('app.current_tenant', '2', false) AS tenant_atual;
SELECT id, slug FROM tenants;
SELECT name, price_cents FROM items;
