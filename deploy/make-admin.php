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
    ->first();

if (! $user) {
    fwrite(STDERR, "User not found for identity: {$identity}\n");
    exit(1);
}

$user->forceFill(['type' => App\Models\User::TYPE_ADMIN])->save();

echo "Admin granted: id={$user->id} uid={$user->uid} name={$user->name} type={$user->type}\n";
