<?php

namespace App\Console\Commands;

use App\Services\LegacyPhotoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EnhanceLegacyUserAvatars extends Command
{
    protected $signature = 'avatars:enhance-legacy
                            {--dry-run : Only report files that would be updated}
                            {--limit=0 : Max files to process (0 = all)}';

    protected $description = 'Upscale and re-encode small legacy user avatars under photos/users (permanent on-disk fix).';

    public function handle(LegacyPhotoStorage $storage): int
    {
        $dir = $storage->absolutePath('users', 'probe');
        $dir = dirname($dir);

        if (! is_dir($dir)) {
            $this->error("Directory not found: {$dir}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $processed = 0;
        $updated = 0;
        $skipped = 0;

        $files = File::files($dir);
        $this->info('Scanning ' . count($files) . ' files in ' . $dir);

        foreach ($files as $file) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $path = $file->getPathname();
            $processed++;

            if (! $storage->shouldEnhanceUserAvatar($path)) {
                $skipped++;

                continue;
            }

            $info = @getimagesize($path);
            $dim = $info ? $info[0] . 'x' . $info[1] : '?';

            if ($dryRun) {
                $this->line("[dry-run] {$file->getFilename()} {$dim}");
                $updated++;

                continue;
            }

            if ($storage->persistEnhancedUserAvatar($path)) {
                $this->line("enhanced {$file->getFilename()} (was {$dim})");
                $updated++;
            } else {
                $this->warn("failed {$file->getFilename()}");
            }
        }

        if (! $dryRun) {
            $storage->purgeUserAvatarCache();
        }

        $this->info("Done. processed={$processed} updated={$updated} skipped={$skipped}");

        return self::SUCCESS;
    }
}
