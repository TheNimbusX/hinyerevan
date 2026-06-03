<?php

namespace App\Console\Commands;

use App\Services\LegacyLikeImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportLegacyLikes extends Command
{
    protected $signature = 'legacy:import-likes
                            {--from-db : Import aggregated counts from LEGACY_LIKES_DB_CONNECTION}
                            {--into-favorites : Import individual legacy like rows into favorites table}
                            {--from-csv= : CSV path with photo_id,likes_count columns}
                            {--reset : Zero legacy_likes_count before import}';

    protected $description = 'Import legacy photo like counts (from optional legacy DB or CSV)';

    public function handle(LegacyLikeImportService $import): int
    {
        if (! Schema::hasColumn('photos', 'legacy_likes_count')) {
            $this->error('Run migrations first (legacy_likes_count column is missing).');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            DB::table('photos')->where('id', '>', 0)->update(['legacy_likes_count' => 0]);
            $this->info('Reset legacy_likes_count on all photos.');
        }

        if ($csv = $this->option('from-csv')) {
            return $this->importCsv($import, (string) $csv);
        }

        if ($this->option('from-db')) {
            $result = $import->importFromLegacyConnection();
            $this->line($result['message']);

            if ($this->option('into-favorites')) {
                $connection = config('hinyerevan.legacy_likes_connection');
                foreach (['photo_likes', 'likes', 'favorites'] as $table) {
                    if ($connection && Schema::connection($connection)->hasTable($table)) {
                        $fav = $import->importRowsIntoFavorites($connection, $table);
                        $this->line($fav['message']);
                        break;
                    }
                }
            }

            return $result['updated'] > 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Legacy HinYerevan MySQL dump had no likes table (only photos, views, comments, users, news, pages).');
        $this->line('Options:');
        $this->line('  php artisan legacy:import-likes --from-db   (requires LEGACY_LIKES_DB_* in .env)');
        $this->line('  php artisan legacy:import-likes --from-csv=/path/to/photo_likes.csv');

        return self::SUCCESS;
    }

    private function importCsv(LegacyLikeImportService $import, string $path): int
    {
        if (! is_file($path)) {
            $this->error("CSV not found: {$path}");

            return self::FAILURE;
        }

        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error('Could not open CSV.');

            return self::FAILURE;
        }

        $header = null;
        while (($line = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map('strtolower', $line);
                continue;
            }

            $data = array_combine($header, $line);
            if (! $data) {
                continue;
            }

            $photoId = (int) ($data['photo_id'] ?? $data['id'] ?? 0);
            $count = (int) ($data['likes_count'] ?? $data['likes'] ?? $data['count'] ?? 0);
            if ($photoId > 0) {
                $rows[] = ['photo_id' => $photoId, 'likes_count' => $count];
            }
        }
        fclose($handle);

        $result = $import->applyCounts($rows, 'csv');
        $this->info($result['message']);

        return $result['updated'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
