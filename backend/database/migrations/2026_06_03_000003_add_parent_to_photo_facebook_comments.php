<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photo_facebook_comments')) {
            return;
        }

        Schema::table('photo_facebook_comments', function (Blueprint $table) {
            if (! Schema::hasColumn('photo_facebook_comments', 'parent_facebook_comment_id')) {
                $table->string('parent_facebook_comment_id', 64)->nullable()->after('facebook_comment_id');
                $table->index(['photo_id', 'parent_facebook_comment_id'], 'pfc_photo_parent_idx');
            }
            if (! Schema::hasColumn('photo_facebook_comments', 'author_picture')) {
                $table->string('author_picture', 512)->nullable()->after('author_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('photo_facebook_comments')) {
            return;
        }

        Schema::table('photo_facebook_comments', function (Blueprint $table) {
            if (Schema::hasColumn('photo_facebook_comments', 'parent_facebook_comment_id')) {
                $table->dropIndex('pfc_photo_parent_idx');
                $table->dropColumn('parent_facebook_comment_id');
            }
            if (Schema::hasColumn('photo_facebook_comments', 'author_picture')) {
                $table->dropColumn('author_picture');
            }
        });
    }
};
