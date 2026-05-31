<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos') || Schema::hasColumn('photos', 'needs_location_review')) {
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->boolean('needs_location_review')->default(false)->after('published');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('photos') && Schema::hasColumn('photos', 'needs_location_review')) {
            Schema::table('photos', function (Blueprint $table) {
                $table->dropColumn('needs_location_review');
            });
        }
    }
};
