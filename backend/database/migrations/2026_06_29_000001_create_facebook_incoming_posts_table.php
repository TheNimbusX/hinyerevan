<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facebook_incoming_posts')) {
            return;
        }

        Schema::create('facebook_incoming_posts', function (Blueprint $table) {
            $table->id();
            $table->string('facebook_post_id', 64)->unique();
            $table->text('message')->nullable();
            $table->text('image_url')->nullable();
            $table->text('permalink_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('photo_id')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_incoming_posts');
    }
};
