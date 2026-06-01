#!/usr/bin/env php
<?php
/**
 * Add dev A record via REG.RU API (run on prod or locally).
 * Usage: REG_RU_USER=login REG_RU_PASS=password php reg-add-dev-dns.php
 */
$user = getenv('REG_RU_USER') ?: ($argv[1] ?? '');
$pass = getenv('REG_RU_PASS') ?: ($argv[2] ?? '');
if ($user === '' || $pass === '') {
    fwrite(STDERR, "Usage: REG_RU_USER=... REG_RU_PASS=... php reg-add-dev-dns.php\n");
    exit(1);
}
$payload = [
    'username' => $user,
    'password' => $pass,
    'domains' => [['dname' => 'hinyerevan.com']],
    'domain_name' => 'hinyerevan.com',
    'subdomain' => 'dev',
    'records' => ['A' => '45.138.25.76'],
];
$ch = curl_init('https://api.reg.ru/api/regru2/zone/add_records');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP $code\n$body\n";
