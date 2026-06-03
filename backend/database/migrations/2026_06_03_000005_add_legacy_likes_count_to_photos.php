<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos')) {
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            if (! Schema::hasColumn('photos', 'legacy_likes_count')) {
                $table->unsignedInteger('legacy_likes_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('photos')) {
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            if (Schema::hasColumn('photos', 'legacy_likes_count')) {
                $table->dropColumn('legacy_likes_count');
            }
        });
    }
};
