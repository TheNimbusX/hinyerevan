<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyRepairSchema extends Command
{
    protected $signature = 'legacy:repair-schema';

    protected $description = 'Re-apply additive migrations after a legacy SQL dump overwrote table schemas.';

    private const PATCH_MIGRATIONS = [
        '2026_05_29_000001_add_video_to_photos_table',
        '2026_05_29_000002_add_needs_location_review_to_photos_table',
        '2026_06_02_000001_add_facebook_fields_to_photos_table',
        '2026_06_03_000002_create_photo_facebook_comments_table',
        '2026_06_03_000003_add_parent_to_photo_facebook_comments',
        '2026_06_03_000004_add_reply_to_facebook_on_comments',
        '2026_06_03_000005_add_legacy_likes_count_to_photos',
        '2026_06_03_000006_add_facebook_views_to_photos',
        '2026_06_03_000007_widen_author_picture_on_photo_facebook_comments',
        '2026_06_03_000008_add_facebook_comment_id_to_comments',
        '2026_06_05_000001_add_is_winter_to_photos_table',
    ];

    public function handle(): int
    {
        if (! $this->schemaNeedsRepair()) {
            $this->info('Legacy schema extensions are present.');

            return self::SUCCESS;
        }

        $this->warn('Legacy dump is missing app columns — re-applying additive migrations…');

        $this->relaxSqlMode();
        $this->sanitizeLegacyRows();

        DB::table('migrations')
            ->whereIn('migration', self::PATCH_MIGRATIONS)
            ->delete();

        Artisan::call('migrate', ['--force' => true]);
        $this->output->write(Artisan::output());

        if ($this->schemaNeedsRepair()) {
            $this->error('Schema repair incomplete. Run legacy:inspect for details.');

            return self::FAILURE;
        }

        $this->info('Legacy schema repaired.');

        return self::SUCCESS;
    }

    private function schemaNeedsRepair(): bool
    {
        if (! Schema::hasTable('photos')) {
            return false;
        }

        $photoColumns = [
            'video',
            'needs_location_review',
            'facebook_post_id',
            'facebook_post_url',
            'facebook_publish_pending',
            'facebook_comment',
            'facebook_likes',
            'facebook_comments_count',
            'facebook_synced_at',
            'legacy_likes_count',
            'facebook_views',
            'is_winter',
        ];

        foreach ($photoColumns as $column) {
            if (! Schema::hasColumn('photos', $column)) {
                return true;
            }
        }

        if (! Schema::hasTable('photo_facebook_comments')) {
            return true;
        }

        if (Schema::hasTable('comments')) {
            if (! Schema::hasColumn('comments', 'reply_to_facebook_comment_id')) {
                return true;
            }

            if (! Schema::hasColumn('comments', 'facebook_comment_id')) {
                return true;
            }
        }

        return false;
    }

    private function relaxSqlMode(): void
    {
        DB::statement(
            "SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', ''), 'STRICT_TRANS_TABLES', '')"
        );
    }

    private function sanitizeLegacyRows(): void
    {
        if (! Schema::hasTable('photos') || ! Schema::hasColumn('photos', 'datetime')) {
            return;
        }

        DB::statement("UPDATE `photos` SET `datetime` = '1970-01-01 00:00:00' WHERE `datetime` = '0000-00-00 00:00:00' OR `datetime` < '1971-01-01'");
    }
}
