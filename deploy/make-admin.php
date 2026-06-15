<?php

/**
 * One-off: promote a user to admin by Google/social identity.
 * Usage: php deploy/make-admin.php [identity]
 */

$identity = $argv[1] ?? '112744568695820426854';

require __DIR__ . '/../backend/vendor/autoload.php';
$app = require __DIR__ . '/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()
    ->where('identity', $identity)
    ->orWhere('unique', 'like', '%' . $identity . '%')
    ->orWhere('uid', 'like', '%denis%')
    ->orWhere(function ($query) {
        $query->whereRaw('LOWER(first_name) LIKE ?', ['%denis%'])
            ->whereRaw('LOWER(last_name) LIKE ?', ['%grab%']);
    })
    ->orderByDesc('id')
    ->first();

if (! $user) {
    fwrite(STDERR, "User not found for identity: {$identity}\n");
    $candidates = App\Models\User::query()
        ->whereRaw('LOWER(first_name) LIKE ?', ['%denis%'])
        ->orWhereRaw('LOWER(uid) LIKE ?', ['%denis%'])
        ->orWhere('identity', 'like', '%112744%')
        ->limit(10)
        ->get(['id', 'uid', 'first_name', 'last_name', 'identity', 'type']);
    foreach ($candidates as $candidate) {
        fwrite(STDERR, "Candidate id={$candidate->id} uid={$candidate->uid} name={$candidate->first_name} {$candidate->last_name} identity={$candidate->identity} type={$candidate->type}\n");
    }
    exit(1);
}

$user->forceFill(['type' => App\Models\User::TYPE_ADMIN])->save();

echo "Admin granted: id={$user->id} uid={$user->uid} name={$user->name} type={$user->type}\n";
