<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos') || ! Schema::hasColumn('photos', 'title')) {
            return;
        }

        DB::statement('SET @hy_old_sql_mode = @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        DB::statement('ALTER TABLE `photos` MODIFY `title` VARCHAR(255) NOT NULL');
        DB::statement('SET SESSION sql_mode = @hy_old_sql_mode');
    }

    public function down(): void
    {
        if (! Schema::hasTable('photos') || ! Schema::hasColumn('photos', 'title')) {
            return;
        }

        DB::statement('SET @hy_old_sql_mode = @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        DB::statement('ALTER TABLE `photos` MODIFY `title` VARCHAR(80) NOT NULL');
        DB::statement('SET SESSION sql_mode = @hy_old_sql_mode');
    }
};
