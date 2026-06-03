<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comments') || Schema::hasColumn('comments', 'facebook_comment_id')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            // FB comment id of a site comment that was also cross-posted to Facebook.
            $table->string('facebook_comment_id', 64)->nullable()->after('reply_to_facebook_comment_id');
            $table->index('facebook_comment_id', 'comments_fb_comment_id_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('comments') || ! Schema::hasColumn('comments', 'facebook_comment_id')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_fb_comment_id_idx');
            $table->dropColumn('facebook_comment_id');
        });
    }
};
