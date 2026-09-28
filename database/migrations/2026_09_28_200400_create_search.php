<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search over messages, diacritics ignored (as the desktop's FTS5
 * with remove_diacritics): a text search configuration that runs every word
 * through unaccent, and a GIN index over it. Titles use an immutable
 * unaccent wrapper so they can be matched case- and accent-insensitively.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hub_unaccent(text) RETURNS text
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        SQL);
        // migrate:fresh drops tables only, so the configuration may outlive them.
        DB::statement('DROP TEXT SEARCH CONFIGURATION IF EXISTS hub_search CASCADE');
        DB::statement('CREATE TEXT SEARCH CONFIGURATION hub_search (COPY = simple)');
        DB::statement('ALTER TEXT SEARCH CONFIGURATION hub_search ALTER MAPPING FOR hword, hword_part, word WITH unaccent, simple');
        DB::statement("CREATE INDEX messages_search ON messages USING gin (to_tsvector('hub_search'::regconfig, coalesce(search_text, '')))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_search');
        DB::statement('DROP TEXT SEARCH CONFIGURATION IF EXISTS hub_search');
        DB::statement('DROP FUNCTION IF EXISTS hub_unaccent(text)');
    }
};
