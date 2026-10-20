<?php

declare(strict_types=1);

require __DIR__ . '/../src/Helpers.php';

use App\Helpers;

$failed = 0;

function check(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        echo "ok   {$message}\n";
        return;
    }
    echo "FAIL {$message}\n";
    $failed++;
}

$at = Helpers::nextRotationAt(0, 'day', 1_700_000_000);
check($at === null, 'manual interval has no next run');

$at = Helpers::nextRotationAt(2, 'hour', 1_700_000_000);
check($at === gmdate('Y-m-d H:i:s', 1_700_000_000 + 7200), 'next run is one interval after now');

check(Helpers::intervalSeconds(1, 'minute') === 60, 'minute interval is 60 seconds');
check(Helpers::intervalSeconds(3, 'bogus') === 3 * 86400, 'unknown unit falls back to days');

putenv('TRUSTED_PROXY_IPS=');
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
check(Helpers::getClientIp() === '203.0.113.9', 'untrusted peer ignores X-Forwarded-For');

$_SERVER['REMOTE_ADDR'] = '10.0.0.8';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.7';
check(Helpers::getClientIp() === '10.0.0.8', 'private peer is not trusted unless listed');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
check(Helpers::getClientIp() === '203.0.113.9', 'loopback uses the address nginx observed');

putenv('TRUSTED_PROXY_IPS=203.0.113.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2, 203.0.113.9';
check(Helpers::getClientIp() === '198.51.100.2', 'walks X-Forwarded-For from the right');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 198.51.100.2';
check(Helpers::getClientIp() === '198.51.100.2', 'client-supplied left-hand address does not win');

putenv('TRUSTED_PROXY_IPS');

exit($failed === 0 ? 0 : 1);
