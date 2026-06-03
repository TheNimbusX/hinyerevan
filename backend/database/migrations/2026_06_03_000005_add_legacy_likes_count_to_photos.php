<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos') || Schema::hasColumn('photos', 'legacy_likes_count')) {
            return;
        }

        // Legacy rows may contain 0000-00-00 datetimes; relax mode for this DDL only.
        DB::statement('SET @hy_old_sql_mode = @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        DB::statement('ALTER TABLE `photos` ADD `legacy_likes_count` INT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('SET SESSION sql_mode = @hy_old_sql_mode');
    }

    public function down(): void
    {
        if (! Schema::hasTable('photos') || ! Schema::hasColumn('photos', 'legacy_likes_count')) {
            return;
        }

        DB::statement('SET @hy_old_sql_mode = @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        DB::statement('ALTER TABLE `photos` DROP COLUMN `legacy_likes_count`');
        DB::statement('SET SESSION sql_mode = @hy_old_sql_mode');
    }
};
