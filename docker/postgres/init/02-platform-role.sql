-- Papel do painel /plataforma (dona do SaaS). Idempotente: roda sozinho num
-- volume novo e também à mão num banco que já existe, sem apagar dados:
--   PowerShell (raiz do repositório):
--     Get-Content -Encoding UTF8 -Raw docker/postgres/init/02-platform-role.sql | docker exec -i la-carte-postgres psql -U la_carte -d la_carte
--
-- la_carte_platform  BYPASSRLS: enxerga todos os restaurantes. Continua SEM
--                    superuser e SEM ser dono das tabelas: não apaga tabela, não
--                    mexe em policy, não cria papel. Só o /plataforma usa esta
--                    credencial; o /admin nunca a tem em mãos.

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'la_carte_platform') THEN
        CREATE ROLE la_carte_platform LOGIN PASSWORD 'la_carte_platform_dev'
            NOSUPERUSER BYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END
$$;

-- Tabelas que já existem (GRANT ON ALL) e as que as migrations ainda vão
-- criar (DEFAULT PRIVILEGES). Privilégios valem por banco: repete nos dois.
\connect la_carte
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO la_carte_platform;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO la_carte_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO la_carte_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO la_carte_platform;

\connect la_carte_test
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO la_carte_platform;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO la_carte_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO la_carte_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO la_carte_platform;
