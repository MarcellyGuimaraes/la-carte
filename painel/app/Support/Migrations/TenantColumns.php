<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * DDL idempotente para as colunas do whitelabel em tenants.
 *
 * Por quê: em produção algumas dessas colunas foram criadas à mão no Neon,
 * antes das migrations. Um ADD COLUMN puro falhava ("already exists") e, com
 * o set -e do start.sh, derrubava o deploy. Estas funções levam o banco ao
 * estado esperado a partir de qualquer ponto: coluna ausente é criada,
 * coluna existente tem o tipo e o padrão acertados, constraint ausente é
 * criada.
 *
 * O que elas NÃO fazem: consertar dados. Se um valor gravado à mão viola uma
 * constraint, o Postgres recusa o ADD CONSTRAINT com o nome dela no erro, e a
 * correção é explícita, não um UPDATE às cegas em produção.
 *
 * Nomes e tipos vêm só das migrations (constantes no código), nunca de input:
 * por isso a interpolação no SQL é segura aqui.
 */
final class TenantColumns
{
    /**
     * Garante a coluna com este tipo. Já existindo, ajusta o tipo (ex.: text
     * criado à mão vira varchar(n); se algum valor não couber, o Postgres
     * falha explicitamente). Mesmo tipo = nada muda.
     */
    public static function ensure(string $column, string $type): void
    {
        DB::statement("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS {$column} {$type}");
        DB::statement("ALTER TABLE tenants ALTER COLUMN {$column} TYPE {$type}");
    }

    /**
     * Coluna obrigatória com valor padrão. Criada agora, o padrão preenche as
     * linhas existentes; já existindo com nulos, o SET NOT NULL falha
     * explicitamente.
     */
    public static function ensureWithDefault(string $column, string $type, string $defaultSql): void
    {
        DB::statement("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS {$column} {$type} NOT NULL DEFAULT {$defaultSql}");
        DB::statement("ALTER TABLE tenants ALTER COLUMN {$column} TYPE {$type}");
        DB::statement("ALTER TABLE tenants ALTER COLUMN {$column} SET DEFAULT {$defaultSql}");
        DB::statement("ALTER TABLE tenants ALTER COLUMN {$column} SET NOT NULL");
    }

    /** Cria a CHECK só se ainda não existir uma com este nome em tenants. */
    public static function ensureCheck(string $name, string $expression): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS found FROM pg_constraint WHERE conname = ? AND conrelid = 'tenants'::regclass",
            [$name],
        );

        if ($exists === null) {
            DB::statement("ALTER TABLE tenants ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
}
