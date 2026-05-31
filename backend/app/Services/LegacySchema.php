<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LegacySchema
{
    public static function photosReady(): bool
    {
        return self::hasColumns('photos', ['id', 'title', 'lat', 'lng', 'datetime', 'user', 'direction', 'year', 'published', 'file_id']);
    }

    public static function usersReady(): bool
    {
        return self::hasColumns('users', ['id', 'uid', 'network', 'unique', 'first_name', 'last_name', 'email', 'type', 'password']);
    }

    public static function commentsReady(): bool
    {
        return self::hasColumns('comments', ['id', 'post_id', 'body', 'user_unique', 'datetime', 'to']);
    }

    public static function newsReady(): bool
    {
        return self::hasColumns('news', ['id', 'title', 'content', 'date', 'published']);
    }

    public static function pagesReady(): bool
    {
        return self::hasColumns('pages', ['id', 'title', 'alias', 'content']);
    }

    public static function feedbackReady(): bool
    {
        return self::hasColumns('feedback_messages', ['id', 'name', 'email', 'content', 'read_at', 'created_at']);
    }

    public static function viewsReady(): bool
    {
        return self::hasColumns('views', ['photo_id', 'count']);
    }

    public static function emptyPaginator(Request $request, int $perPage = 20): array
    {
        return [
            'data' => [],
            'current_page' => max(1, (int) $request->integer('page', 1)),
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
        ];
    }

    private static function hasColumns(string $table, array $columns): bool
    {
        try {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
