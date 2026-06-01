<?php

return [
    'legacy_root' => env('HINYEREVAN_LEGACY_ROOT', base_path('../hin-yerevan-backup-prod/hin-yerevan1')),
    'photo_paths' => [
        'original' => env('HINYEREVAN_PHOTOS_ORIGINAL', 'photos/o'),
        'large' => env('HINYEREVAN_PHOTOS_LARGE', 'photos/x'),
        'thumb' => env('HINYEREVAN_PHOTOS_THUMB', 'photos/192x192'),
        'users' => env('HINYEREVAN_PHOTOS_USERS', 'photos/users'),
    ],
    'watermark' => env('HINYEREVAN_WATERMARK', 'templates/white.png'),
    // Watermark burned into NEW uploads (legacy photos already carry their own).
    'watermark_upload' => env('HINYEREVAN_WATERMARK_UPLOAD', base_path('resources/watermark/logo.png')),
    'public_photo_requires_published' => env('HINYEREVAN_PUBLIC_PHOTO_REQUIRES_PUBLISHED', true),
];
