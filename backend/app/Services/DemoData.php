<?php

namespace App\Services;

use Illuminate\Http\Request;

class DemoData
{
    public static function users(): array
    {
        return [
            [
                'id' => 1,
                'uid' => 'aram',
                'unique' => 'demo-user-aram',
                'first_name' => 'Արամ',
                'last_name' => 'Մկրտչյան',
                'name' => 'Արամ Մկրտչյան',
                'photo' => '/demo/user-1.svg',
            ],
            [
                'id' => 2,
                'uid' => 'mariam',
                'unique' => 'demo-user-mariam',
                'first_name' => 'Մարիամ',
                'last_name' => 'Սարգսյան',
                'name' => 'Մարիամ Սարգսյան',
                'photo' => '/demo/user-2.svg',
            ],
        ];
    }

    public static function photos(): array
    {
        $users = self::users();

        return [
            self::photo(1001, 'Աբովյան փողոց', 1932, 40.18438, 44.51658, 3, $users[0], '/demo/photo-1.svg', 128, 7),
            self::photo(1002, 'Հին շուկայի մոտ', 1948, 40.1772, 44.5129, 5, $users[1], '/demo/photo-2.svg', 94, 4),
            self::photo(1003, 'Կոնդի բարձունքից', 1961, 40.18172, 44.50381, 0, $users[0], '/demo/photo-3.svg', 211, 12),
        ];
    }

    public static function paginatedPhotos(Request $request, int $perPage = 20): array
    {
        return [
            'data' => self::photos(),
            'current_page' => max(1, (int) $request->integer('page', 1)),
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => count(self::photos()),
        ];
    }

    public static function markers(): array
    {
        return array_map(fn (array $photo) => [
            'id' => $photo['id'],
            'title' => $photo['title'],
            'lat' => $photo['lat'],
            'lng' => $photo['lng'],
            'year' => $photo['year'],
            'direction' => $photo['direction'],
            'direction_label' => $photo['direction_label'],
            'thumb_url' => $photo['images']['thumb'],
        ], self::photos());
    }

    public static function findPhoto(int $id): ?array
    {
        foreach (self::photos() as $photo) {
            if ($photo['id'] === $id) {
                return $photo + [
                    'comments' => [
                        [
                            'id' => 1,
                            'body' => 'Դեմո մեկնաբանություն մինչ իրական բազայի միացումը։',
                            'author' => self::users()[1],
                        ],
                    ],
                ];
            }
        }

        return null;
    }

    public static function ratings(): array
    {
        $photos = self::photos();

        return [
            'photos_by_views' => array_map(fn (array $photo) => [
                'id' => $photo['id'],
                'title' => $photo['title'],
                'file_id' => 'demo',
                'year' => $photo['year'],
                'views' => $photo['views'],
            ], $photos),
            'photos_by_comments' => array_map(fn (array $photo) => [
                'id' => $photo['id'],
                'title' => $photo['title'],
                'file_id' => 'demo',
                'year' => $photo['year'],
                'comments_count' => $photo['comments_count'],
            ], $photos),
            'users_by_photos' => [
                self::users()[0] + ['photos_count' => 2],
                self::users()[1] + ['photos_count' => 1],
            ],
            'users_by_comments' => [
                self::users()[1] + ['comments_count' => 8],
                self::users()[0] + ['comments_count' => 5],
            ],
        ];
    }

    public static function news(): array
    {
        return [
            [
                'id' => 9001,
                'title' => 'HinYerevan-ի նոր տարբերակը',
                'content' => 'Սկսել ենք հին կայքի վերակառուցումը Laravel API և Vue ինտերֆեյսով։',
                'date' => now()->subDays(2)->toISOString(),
                'published' => true,
            ],
            [
                'id' => 9002,
                'title' => 'Քարտեզի և լուսանկարների փորձնական տվյալներ',
                'content' => 'Մինչ հին բազայի միացումը ցուցադրվում են երեք փորձնական լուսանկար և երկու հեղինակ։',
                'date' => now()->subDay()->toISOString(),
                'published' => true,
            ],
        ];
    }

    public static function paginatedNews(Request $request, int $perPage = 10): array
    {
        return [
            'data' => self::news(),
            'current_page' => max(1, (int) $request->integer('page', 1)),
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => count(self::news()),
        ];
    }

    private static function photo(
        int $id,
        string $title,
        int $year,
        float $lat,
        float $lng,
        int $direction,
        array $author,
        string $image,
        int $views,
        int $comments
    ): array {
        $directions = [
            0 => 'Նկար բարձրությունից',
            1 => 'Հյուսիս',
            2 => 'Հյուսիս-Արևելք',
            3 => 'Արևելք',
            4 => 'Հարավ-Արևելք',
            5 => 'Հարավ',
            6 => 'Հարավ-Արևմուտք',
            7 => 'Արևմուտք',
            8 => 'Հյուսիս-Արևմուտք',
        ];

        return [
            'id' => $id,
            'title' => $title,
            'lat' => $lat,
            'lng' => $lng,
            'year' => $year,
            'direction' => $direction,
            'direction_label' => $directions[$direction],
            'published' => true,
            'datetime' => now()->toISOString(),
            'views' => $views,
            'comments_count' => $comments,
            'author' => $author,
            'images' => [
                'original' => $image,
                'large' => $image,
                'thumb' => $image,
            ],
        ];
    }
}
