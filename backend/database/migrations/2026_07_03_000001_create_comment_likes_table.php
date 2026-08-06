<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comment_likes')) {
            Schema::create('comment_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('photo_id')->index();
                $table->unsignedInteger('comment_id')->nullable();
                $table->string('facebook_comment_id', 64)->nullable();
                $table->string('user_unique', 64);
                $table->timestamps();
                $table->unique(['comment_id', 'user_unique']);
                $table->unique(['facebook_comment_id', 'user_unique']);
            });
        }

        if (Schema::hasTable('photo_facebook_comments') && ! Schema::hasColumn('photo_facebook_comments', 'like_count')) {
            Schema::table('photo_facebook_comments', function (Blueprint $table) {
                $table->unsignedInteger('like_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_likes');

        if (Schema::hasTable('photo_facebook_comments') && Schema::hasColumn('photo_facebook_comments', 'like_count')) {
            Schema::table('photo_facebook_comments', function (Blueprint $table) {
                $table->dropColumn('like_count');
            });
        }
    }
};
