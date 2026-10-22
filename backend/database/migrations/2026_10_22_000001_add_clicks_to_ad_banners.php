<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_banners') && ! Schema::hasColumn('ad_banners', 'clicks')) {
            Schema::table('ad_banners', function (Blueprint $table) {
                $table->unsignedInteger('clicks')->default(0);
                $table->timestamp('last_clicked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ad_banners') && Schema::hasColumn('ad_banners', 'clicks')) {
            Schema::table('ad_banners', function (Blueprint $table) {
                $table->dropColumn(['clicks', 'last_clicked_at']);
            });
        }
    }
};
