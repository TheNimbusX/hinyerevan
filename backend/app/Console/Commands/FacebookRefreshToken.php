<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookGraphClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FacebookRefreshToken extends Command
{
    protected $signature = 'facebook:refresh-token
                            {--threshold=20 : Alert if data access expires within this many days}
                            {--no-write : Inspect/refresh but do not write the new token to .env}';

    protected $description = 'Refresh the long-lived Facebook Page token ahead of expiry and alert on problems';

    public function handle(FacebookGraphClient $graph): int
    {
        $appId = trim((string) (config('services.facebook.app_id') ?: config('services.facebook.client_id', '')));
        $appSecret = trim((string) (config('services.facebook.app_secret') ?: config('services.facebook.client_secret', '')));
        $current = trim((string) config('services.facebook.page_access_token', ''));
        $threshold = max(1, (int) $this->option('threshold'));

        if ($appId === '' || $appSecret === '' || $current === '') {
            return $this->fail('Facebook token refresh skipped: FACEBOOK_APP_ID / FACEBOOK_APP_SECRET / FACEBOOK_PAGE_ACCESS_TOKEN are not all configured.');
        }

        $appToken = $appId . '|' . $appSecret;
        $dataAccessDays = null;

        $debug = $graph->get('debug_token', [
            'input_token' => $current,
            'access_token' => $appToken,
        ]);

        if ($debug->ok()) {
            $data = (array) ($debug->json('data') ?? []);
            $isValid = (bool) ($data['is_valid'] ?? false);
            $dataAccessExpires = (int) ($data['data_access_expires_at'] ?? 0);
            $expires = (int) ($data['expires_at'] ?? 0);

            $this->line('Token valid: ' . ($isValid ? 'yes' : 'no'));
            $this->line('Expires: ' . ($expires === 0 ? 'never' : date('Y-m-d H:i', $expires)));

            if ($dataAccessExpires > 0) {
                $dataAccessDays = (int) floor(($dataAccessExpires - time()) / 86400);
                $this->line('Data access expires: ' . date('Y-m-d', $dataAccessExpires) . " (in {$dataAccessDays} days)");
            }

            if (! $isValid) {
                $err = (array) ($data['error'] ?? []);
                $reason = (string) ($err['message'] ?? 'token reported invalid by debug_token');
                $this->warn('debug_token reports invalid: ' . $reason);
            }
        } else {
            $this->warn('debug_token call failed: HTTP ' . $debug->status() . ' ' . $debug->body());
        }

        $response = $graph->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $current,
        ]);

        if (! $response->ok()) {
            return $this->fail(
                'Facebook token re-exchange FAILED (HTTP ' . $response->status() . '). The Page token likely needs to be regenerated manually via Graph API Explorer + facebook:exchange-token. Response: ' . $response->body()
                . ($dataAccessDays !== null ? " Data access expires in {$dataAccessDays} days." : '')
            );
        }

        $fresh = (string) ($response->json('access_token') ?? '');
        if ($fresh === '') {
            return $this->fail('Facebook token re-exchange returned no access_token. Response: ' . $response->body());
        }

        if ($fresh === $current) {
            $this->info('Token re-exchanged; value unchanged (data-access window refreshed).');

            return self::SUCCESS;
        }

        // A usable Page token never expires (expires_at = 0). Exchanging a Page
        // token can hand back a ~1 day token, which would silently kill the
        // integration a day later — refuse to store that and keep the old one.
        $freshExpires = $this->tokenExpiry($graph, $fresh, $appToken);
        if ($freshExpires !== null && $freshExpires > 0) {
            $hours = (int) floor(($freshExpires - time()) / 3600);
            if ($hours < 24 * 30) {
                return $this->fail(
                    "Re-exchange returned a short-lived token (expires in {$hours}h, on "
                    . date('Y-m-d H:i', $freshExpires) . '). Keeping the current token. '
                    . 'Issue a permanent Page token: Graph API Explorer -> User token with '
                    . 'pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_posts,pages_manage_engagement '
                    . '-> php artisan facebook:exchange-token <USER_TOKEN> --write-env'
                );
            }
        }

        if ($this->option('no-write')) {
            $this->info('Got a fresh token (not written, --no-write set).');

            return self::SUCCESS;
        }

        try {
            $this->writeEnvToken($fresh);
        } catch (\Throwable $e) {
            return $this->fail('Got a fresh Facebook token but could not write .env: ' . $e->getMessage() . ' (ensure .env is writable by the scheduler user, e.g. chown www-data .env).');
        }

        Artisan::call('config:cache');

        $this->info('Facebook Page token refreshed and saved to .env.');
        Log::info('Facebook Page token refreshed successfully.', [
            'data_access_days_before' => $dataAccessDays,
        ]);

        return self::SUCCESS;
    }

    /** expires_at of a token, 0 = never, null = could not read. */
    private function tokenExpiry(FacebookGraphClient $graph, string $token, string $appToken): ?int
    {
        try {
            $response = $graph->get('debug_token', [
                'input_token' => $token,
                'access_token' => $appToken,
            ]);

            if (! $response->ok()) {
                return null;
            }

            return (int) ($response->json('data.expires_at') ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fail(string $message): int
    {
        $this->error($message);
        Log::error('[facebook:refresh-token] ' . $message);

        $alertEmail = trim((string) config('services.facebook.alert_email', ''));
        if ($alertEmail !== '') {
            try {
                Mail::raw($message, function ($mail) use ($alertEmail) {
                    $mail->to($alertEmail)->subject('[HinYerevan] Facebook token problem');
                });
            } catch (\Throwable $e) {
                Log::error('[facebook:refresh-token] alert email failed: ' . $e->getMessage());
            }
        }

        return self::FAILURE;
    }

    private function writeEnvToken(string $token): void
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            throw new \RuntimeException('.env not found at ' . $path);
        }
        if (! is_writable($path)) {
            throw new \RuntimeException('.env is not writable by ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'current user') : 'current user'));
        }

        $contents = (string) file_get_contents($path);
        $key = 'FACEBOOK_PAGE_ACCESS_TOKEN';
        $line = $key . '=' . $token;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        $contents = preg_match($pattern, $contents)
            ? (string) preg_replace($pattern, $line, $contents, 1)
            : rtrim($contents) . PHP_EOL . $line . PHP_EOL;

        file_put_contents($path, $contents);
    }
}
