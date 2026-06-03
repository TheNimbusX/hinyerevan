<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comments')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            if (! Schema::hasColumn('comments', 'reply_to_facebook_comment_id')) {
                $table->string('reply_to_facebook_comment_id', 64)->nullable()->after('to');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('comments')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            if (Schema::hasColumn('comments', 'reply_to_facebook_comment_id')) {
                $table->dropColumn('reply_to_facebook_comment_id');
            }
        });
    }
};
