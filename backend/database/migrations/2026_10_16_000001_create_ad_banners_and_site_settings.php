<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_banners')) {
            Schema::create('ad_banners', function (Blueprint $table) {
                $table->id();
                $table->string('title', 160)->nullable();
                $table->string('link_url', 512)->nullable();
                $table->string('image_path', 255);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('site_settings')) {
            Schema::create('site_settings', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_banners');
        Schema::dropIfExists('site_settings');
    }
};
