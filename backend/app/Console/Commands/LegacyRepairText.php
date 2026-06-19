<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\PhotoController;
use App\Models\Photo;
use App\Support\LegacyText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyRepairText extends Command
{
    protected $signature = 'legacy:repair-text {--dry-run : Show how many rows would change without writing}';

    protected $description = 'Decode HTML entities in legacy photo titles stored in the database.';

    public function handle(): int
    {
        if (! Schema::hasTable('photos') || ! Schema::hasColumn('photos', 'title')) {
            $this->warn('photos.title column not found — nothing to repair.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        Photo::query()
            ->select(['id', 'title'])
            ->orderBy('id')
            ->chunkById(500, function ($photos) use ($dryRun, &$changed) {
                foreach ($photos as $photo) {
                    $decoded = LegacyText::decode($photo->title);
                    if ($decoded === $photo->title) {
                        continue;
                    }

                    $changed++;
                    if ($dryRun) {
                        continue;
                    }

                    DB::table('photos')->where('id', $photo->id)->update(['title' => $decoded]);
                }
            });

        if ($changed > 0 && ! $dryRun) {
            PhotoController::flushMarkersCache();
        }

        if ($dryRun) {
            $this->info("Would update {$changed} photo title(s).");
        } else {
            $this->info("Updated {$changed} photo title(s).");
        }

        return self::SUCCESS;
    }
}
