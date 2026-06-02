<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('photos')) {
            return;
        }

        $sqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode');
        $originalMode = is_object($sqlMode) ? (string) ($sqlMode->mode ?? '') : '';
        DB::statement("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '')");

        try {
            Schema::table('photos', function (Blueprint $table) {
                if (! Schema::hasColumn('photos', 'facebook_post_id')) {
                    $table->string('facebook_post_id', 64)->nullable()->after('video');
                }
                if (! Schema::hasColumn('photos', 'facebook_post_url')) {
                    $table->string('facebook_post_url', 512)->nullable()->after('facebook_post_id');
                }
                if (! Schema::hasColumn('photos', 'facebook_publish_pending')) {
                    $table->boolean('facebook_publish_pending')->default(false)->after('facebook_post_url');
                }
                if (! Schema::hasColumn('photos', 'facebook_comment')) {
                    $table->text('facebook_comment')->nullable()->after('facebook_publish_pending');
                }
                if (! Schema::hasColumn('photos', 'facebook_likes')) {
                    $table->unsignedInteger('facebook_likes')->nullable()->after('facebook_comment');
                }
                if (! Schema::hasColumn('photos', 'facebook_comments_count')) {
                    $table->unsignedInteger('facebook_comments_count')->nullable()->after('facebook_likes');
                }
                if (! Schema::hasColumn('photos', 'facebook_synced_at')) {
                    $table->timestamp('facebook_synced_at')->nullable()->after('facebook_comments_count');
                }
            });
        } finally {
            if ($originalMode !== '') {
                DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('photos')) {
            return;
        }

        $columns = [
            'facebook_post_id',
            'facebook_post_url',
            'facebook_publish_pending',
            'facebook_comment',
            'facebook_likes',
            'facebook_comments_count',
            'facebook_synced_at',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('photos', $column)) {
                Schema::table('photos', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
