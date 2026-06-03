<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookGraphClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Keeps the long-lived Facebook Page token healthy.
 *
 * The page token never "expires", but Meta enforces a ~90-day data-access
 * window that lapses if the app stays idle. This command:
 *   - inspects the current token (debug_token) and reports its windows;
 *   - re-exchanges it for a fresh long-lived token (which also counts as
 *     activity and resets the data-access window), writing it back to .env;
 *   - raises an alert (log + optional email) when the token is invalid or the
 *     data-access window is about to close and could not be refreshed.
 *
 * Scheduled weekly in Console/Kernel.php.
 */
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

        // 1) Inspect the current token (best-effort; failures are not fatal yet).
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
                // Still try to exchange below — but remember the problem.
                $this->warn('debug_token reports invalid: ' . $reason);
            }
        } else {
            $this->warn('debug_token call failed: HTTP ' . $debug->status() . ' ' . $debug->body());
        }

        // 2) Re-exchange to mint a fresh long-lived token (also keeps data access alive).
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

        if ($this->option('no-write')) {
            $this->info('Got a fresh token (not written, --no-write set).');

            return self::SUCCESS;
        }

        try {
            $this->writeEnvToken($fresh);
        } catch (\Throwable $e) {
            return $this->fail('Got a fresh Facebook token but could not write .env: ' . $e->getMessage() . ' (ensure .env is writable by the scheduler user, e.g. chown www-data .env).');
        }

        // Rebuild the cached config so the new token is used on the next request.
        Artisan::call('config:cache');

        $this->info('Facebook Page token refreshed and saved to .env.');
        Log::info('Facebook Page token refreshed successfully.', [
            'data_access_days_before' => $dataAccessDays,
        ]);

        return self::SUCCESS;
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
