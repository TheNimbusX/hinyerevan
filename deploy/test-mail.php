#!/usr/bin/env php
<?php
/**
 * Quick SMTP check from the VPS (run on server):
 *   php /var/www/hinyerevan/deploy/test-mail.php you@example.com
 */
require __DIR__ . '/../backend/vendor/autoload.php';
$app = require __DIR__ . '/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$to = $argv[1] ?? null;
if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php deploy/test-mail.php recipient@example.com\n");
    exit(1);
}

try {
    Illuminate\Support\Facades\Mail::raw('HinYerevan SMTP test OK', function ($mail) use ($to) {
        $mail->to($to)->subject('HinYerevan mail test');
    });
    echo "Sent to {$to}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
