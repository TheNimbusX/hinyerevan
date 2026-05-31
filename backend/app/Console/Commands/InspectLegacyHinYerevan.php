<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class InspectLegacyHinYerevan extends Command
{
    protected $signature = 'legacy:inspect';

    protected $description = 'Check the legacy HinYerevan database tables and photo folders expected by the new API.';

    public function handle(): int
    {
        $tables = [
            'users' => ['id', 'uid', 'network', 'unique', 'first_name', 'last_name', 'email', 'type', 'password'],
            'photos' => ['id', 'title', 'lat', 'lng', 'datetime', 'user', 'direction', 'year', 'published', 'file_id'],
            'comments' => ['id', 'post_id', 'body', 'user_unique', 'datetime', 'to'],
            'news' => ['id', 'title', 'content', 'date', 'published'],
            'pages' => ['id', 'title', 'alias', 'content'],
            'views' => ['photo_id', 'count'],
        ];

        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table: {$table}");
                continue;
            }

            $missing = array_values(array_filter($columns, fn ($column) => ! Schema::hasColumn($table, $column)));
            $count = DB::table($table)->count();
            $message = $missing
                ? "Table {$table}: {$count} rows, missing columns: " . implode(', ', $missing)
                : "Table {$table}: {$count} rows, OK";

            $missing ? $this->warn($message) : $this->info($message);
        }

        foreach (config('hinyerevan.photo_paths') as $name => $relative) {
            $path = rtrim(config('hinyerevan.legacy_root'), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

            $this->line(($this->folderExists($path) ? 'OK' : 'MISSING') . " {$name}: {$path}");
        }

        return self::SUCCESS;
    }

    private function folderExists(string $path): bool
    {
        return File::isDirectory($path);
    }
}
