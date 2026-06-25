<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneNonAdminUsers extends Command
{
    protected $signature = 'users:prune-non-admins {--force : Run without confirmation}';

    protected $description = 'Delete all users except administrators. Site photos and comments are kept.';

    public function handle(): int
    {
        $adminCount = User::query()
            ->where('type', User::TYPE_ADMIN)
            ->where('id', '>', 0)
            ->count();

        if ($adminCount < 1) {
            $this->error('No administrators found — aborting.');

            return self::FAILURE;
        }

        $toDelete = User::query()
            ->where('id', '>', 0)
            ->where('type', '!=', User::TYPE_ADMIN)
            ->get(['id', 'uid', 'unique', 'email', 'type']);

        if ($toDelete->isEmpty()) {
            $this->info('No non-admin users to delete.');

            return self::SUCCESS;
        }

        $this->line("Administrators kept: {$adminCount}");
        $this->line('Users to delete: ' . $toDelete->count());

        if (! $this->option('force') && ! $this->confirm('Delete these users? Photos on the site will be kept.')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $tokens = 0;
        $favorites = 0;

        foreach ($toDelete as $user) {
            $tokens += (int) $user->tokens()->delete();

            if (Schema::hasTable('favorites')) {
                $favorites += DB::table('favorites')
                    ->where('user_unique', $user->unique)
                    ->delete();
            }

            $user->delete();
            $deleted++;
        }

        $this->info("Deleted users: {$deleted}");
        $this->info("Revoked tokens: {$tokens}");
        $this->info("Removed favorites: {$favorites}");
        $this->info('Photos and comments were not deleted.');

        return self::SUCCESS;
    }
}
