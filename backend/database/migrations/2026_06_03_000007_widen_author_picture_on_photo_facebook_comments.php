<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photo_facebook_comments') || ! Schema::hasColumn('photo_facebook_comments', 'author_picture')) {
            return;
        }

        DB::statement('SET @hy_old_sql_mode = @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        DB::statement('ALTER TABLE `photo_facebook_comments` MODIFY `author_picture` TEXT NULL');
        DB::statement('SET SESSION sql_mode = @hy_old_sql_mode');
    }

    public function down(): void
    {
        if (! Schema::hasTable('photo_facebook_comments') || ! Schema::hasColumn('photo_facebook_comments', 'author_picture')) {
            return;
        }

        DB::statement('SET @hy_old_sql_mode = @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        DB::statement('ALTER TABLE `photo_facebook_comments` MODIFY `author_picture` VARCHAR(512) NULL');
        DB::statement('SET SESSION sql_mode = @hy_old_sql_mode');
    }
};
