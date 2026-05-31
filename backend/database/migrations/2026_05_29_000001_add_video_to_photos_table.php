<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos') || Schema::hasColumn('photos', 'video')) {
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->string('video', 512)->nullable()->after('file_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('photos') && Schema::hasColumn('photos', 'video')) {
            Schema::table('photos', function (Blueprint $table) {
                $table->dropColumn('video');
            });
        }
    }
};
