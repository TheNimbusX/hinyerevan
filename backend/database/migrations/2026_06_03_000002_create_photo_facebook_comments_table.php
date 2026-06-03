<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('photo_facebook_comments')) {
            return;
        }

        Schema::create('photo_facebook_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('photo_id');
            $table->string('facebook_comment_id', 64);
            $table->string('author_name', 255)->nullable();
            $table->text('body');
            $table->timestamp('commented_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unique(['photo_id', 'facebook_comment_id']);
            $table->index('photo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_facebook_comments');
    }
};
