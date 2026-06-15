<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos') || Schema::hasColumn('photos', 'is_winter')) {
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->boolean('is_winter')->default(false)->after('needs_location_review');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('photos') && Schema::hasColumn('photos', 'is_winter')) {
            Schema::table('photos', function (Blueprint $table) {
                $table->dropColumn('is_winter');
            });
        }
    }
};
