<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_social_identities')) {
            return;
        }

        Schema::create('user_social_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('network', 32);
            $table->string('uid', 128);
            $table->timestamps();
            $table->unique(['network', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_identities');
    }
};
