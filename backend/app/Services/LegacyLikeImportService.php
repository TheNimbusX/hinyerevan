<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyLikeImportService
{
    /**
     * @return array{updated: int, source: string, message: string}
     */
    public function importFromLegacyConnection(): array
    {
        $connection = config('hinyerevan.legacy_likes_connection');
        if (! is_string($connection) || $connection === '') {
            return ['updated' => 0, 'source' => 'legacy_db', 'message' => 'LEGACY_LIKES_DB_CONNECTION is not configured.'];
        }

        try {
            $tables = collect(DB::connection($connection)->select('SHOW TABLES'))
                ->map(fn ($row) => (string) array_values((array) $row)[0])
                ->all();
        } catch (\Throwable $e) {
            return ['updated' => 0, 'source' => 'legacy_db', 'message' => $e->getMessage()];
        }

        foreach (['photo_likes', 'likes', 'favorites', 'photo_favorites'] as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return $this->importFromTable($connection, $candidate);
            }
        }

        return [
            'updated' => 0,
            'source' => 'legacy_db',
            'message' => 'No likes table found on legacy connection (checked photo_likes, likes, favorites, photo_favorites).',
        ];
    }

    /**
     * @return array{updated: int, source: string, message: string}
     */
    private function importFromTable(string $connection, string $table): array
    {
        $columns = collect(Schema::connection($connection)->getColumnListing($table));

        $photoColumn = $columns->first(fn ($c) => in_array($c, ['photo_id', 'post_id', 'id_photo'], true));
        if (! $photoColumn) {
            return ['updated' => 0, 'source' => $table, 'message' => "Table {$table} has no photo_id/post_id column."];
        }

        $userColumn = $columns->contains('user_unique') ? 'user_unique' : null;

        if ($userColumn) {
            $rows = DB::connection($connection)
                ->table($table)
                ->select($photoColumn . ' as photo_id', DB::raw('COUNT(*) as likes_count'))
                ->where($photoColumn, '>', 0)
                ->groupBy($photoColumn)
                ->get();
        } elseif ($columns->contains('count')) {
            $rows = DB::connection($connection)
                ->table($table)
                ->select("{$photoColumn} as photo_id", 'count as likes_count')
                ->where($photoColumn, '>', 0)
                ->get();
        } else {
            return ['updated' => 0, 'source' => $table, 'message' => "Table {$table} is not supported for automatic import."];
        }

        return $this->applyCounts($rows->map(fn ($row) => [
            'photo_id' => (int) $row->photo_id,
            'likes_count' => (int) $row->likes_count,
        ])->all(), $table);
    }

    /**
     * @param  list<array{photo_id: int, likes_count: int}>  $rows
     * @return array{updated: int, source: string, message: string}
     */
    public function applyCounts(array $rows, string $source): array
    {
        $updated = 0;

        foreach ($rows as $row) {
            $photoId = (int) ($row['photo_id'] ?? 0);
            $count = max(0, (int) ($row['likes_count'] ?? 0));

            if ($photoId <= 0 || $count <= 0) {
                continue;
            }

            $affected = DB::table('photos')
                ->where('id', $photoId)
                ->where('id', '>', 0)
                ->update(['legacy_likes_count' => $count]);

            if ($affected) {
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'source' => $source,
            'message' => "Updated legacy_likes_count on {$updated} photos.",
        ];
    }

    /**
     * Import one row per like into the new favorites table (deduplicated).
     *
     * @return array{imported: int, skipped: int, message: string}
     */
    public function importRowsIntoFavorites(string $connection, string $table): array
    {
        if (! Schema::hasTable('favorites')) {
            return ['imported' => 0, 'skipped' => 0, 'message' => 'favorites table is missing.'];
        }

        $columns = collect(Schema::connection($connection)->getColumnListing($table));
        $photoColumn = $columns->first(fn ($c) => in_array($c, ['photo_id', 'post_id'], true));
        $userColumn = $columns->contains('user_unique') ? 'user_unique' : null;

        if (! $photoColumn || ! $userColumn) {
            return ['imported' => 0, 'skipped' => 0, 'message' => 'Legacy likes table must have photo_id and user_unique.'];
        }

        $imported = 0;
        $skipped = 0;

        DB::connection($connection)
            ->table($table)
            ->orderBy($photoColumn)
            ->chunk(500, function ($chunk) use ($photoColumn, $userColumn, &$imported, &$skipped) {
                foreach ($chunk as $row) {
                    $photoId = (int) ($row->{$photoColumn} ?? 0);
                    $userUnique = trim((string) ($row->{$userColumn} ?? ''));

                    if ($photoId <= 0 || $userUnique === '') {
                        $skipped++;

                        continue;
                    }

                    if (! DB::table('photos')->where('id', $photoId)->where('id', '>', 0)->exists()) {
                        $skipped++;

                        continue;
                    }

                    $exists = DB::table('favorites')
                        ->where('photo_id', $photoId)
                        ->where('user_unique', $userUnique)
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    DB::table('favorites')->insert([
                        'user_unique' => $userUnique,
                        'photo_id' => $photoId,
                        'created_at' => now(),
                    ]);
                    $imported++;
                }
            });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'message' => "Imported {$imported} legacy likes into favorites (skipped {$skipped}).",
        ];
    }
}
