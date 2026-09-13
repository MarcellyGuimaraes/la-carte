-- Papéis do Postgres de dev. Roda UMA vez, só com o volume vazio
-- (docker compose down -v && docker compose up -d).
--
-- la_carte      superuser criado pela imagem. Dono das tabelas: migrations e seed.
-- la_carte_app  conexão do Laravel em runtime. NÃO é superuser, NÃO tem
--               BYPASSRLS e NÃO é dono de nada: é o único papel preso à RLS.
--               Superuser e dono de tabela ignoram RLS, por isso a separação.

CREATE ROLE la_carte_app LOGIN PASSWORD 'la_carte_app_dev'
    NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;

-- Banco dos testes automatizados de isolamento.
CREATE DATABASE la_carte_test OWNER la_carte;

-- Privilégios padrão valem por banco: repete nos dois. Toda tabela e sequência
-- que la_carte criar daqui em diante (via migration) já nasce acessível ao app.
\connect la_carte
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO la_carte_app;
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO la_carte_app;

\connect la_carte_test
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO la_carte_app;
ALTER DEFAULT PRIVILEGES FOR ROLE la_carte IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO la_carte_app;
