<?php
// Local sign-in must be decided by the server, never by browser connectivity flags.
function kape_login_is_private_address(string $address): bool
{
    $packed = @inet_pton($address);
    if ($packed === false) {
        return false;
    }

    // Treat IPv4-mapped IPv6 addresses the same as their IPv4 equivalent.
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $packed = substr($packed, 12);
    }
    if (strlen($packed) === 4) {
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        return $first === 127 || $first === 10
            || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168);
    }

    return $packed === str_repeat("\0", 15) . "\x01"
        || (ord($packed[0]) & 0xfe) === 0xfc
        || (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80);
}

function kape_login_is_local_request(array $server): bool
{
    // A proxy's private address is not proof that its visitor is on the shop LAN.
    foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST',
              'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP',
              'HTTP_TRUE_CLIENT_IP'] as $header) {
        if (!empty($server[$header])) {
            return false;
        }
    }
    if (!kape_login_is_private_address((string) ($server['REMOTE_ADDR'] ?? ''))
        || !kape_login_is_private_address((string) ($server['SERVER_ADDR'] ?? ''))) {
        return false;
    }

    $authority = parse_url('http://' . (string) ($server['HTTP_HOST'] ?? ''));
    if ($authority === false || isset($authority['pass'])
        || isset($authority['user']) || isset($authority['path'])
        || isset($authority['query']) || isset($authority['fragment'])) {
        return false;
    }
    $host = strtolower(trim((string) ($authority['host'] ?? ''), '[]'));
    return $host === 'localhost' || kape_login_is_private_address($host);
}

function kape_login_update_attempts(string $address, string $username, bool $clearAccount = false, ?int $now = null): int
{
    $now ??= time();
    // Outside the web root; shared across PHP sessions and protected by a file lock.
    $path = defined('KAPE_LOGIN_ATTEMPTS_FILE') ? KAPE_LOGIN_ATTEMPTS_FILE
        : sys_get_temp_dir() . '/kape-login-' . hash('sha256', __DIR__) . '.json';
    $file = @fopen($path, 'c+');
    if ($file === false) {
        throw new RuntimeException('Unable to open login attempt storage.');
    }

    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException('Unable to lock login attempt storage.');
        }
        $raw = stream_get_contents($file, 1048577);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('Unable to read login attempt storage.');
        }
        $entries = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) {
            throw new RuntimeException('Invalid login attempt storage.');
        }
        foreach ($entries as $key => $entry) {
            if (!is_array($entry) || !isset($entry['expires'], $entry['count'])
                || !is_int($entry['expires']) || !is_int($entry['count'])) {
                throw new RuntimeException('Invalid login attempt entry.');
            }
            if ($entry['expires'] <= $now) {
                unset($entries[$key]);
            }
        }

        $accountKey = hash('sha256', 'account|' . $address . '|' . strtolower(trim($username)));
        $addressKey = hash('sha256', 'address|' . $address);
        if ($clearAccount) {
            unset($entries[$accountKey]);
        } else {
            $retryAfter = 0;
            foreach ([$accountKey => 5, $addressKey => 30] as $key => $limit) {
                if (($entries[$key]['count'] ?? 0) >= $limit) {
                    $retryAfter = max($retryAfter, $entries[$key]['expires'] - $now);
                }
            }
            if ($retryAfter > 0) {
                return $retryAfter;
            }
            foreach ([$accountKey, $addressKey] as $key) {
                $entries[$key] ??= ['count' => 0, 'expires' => $now + 15 * 60];
                $entries[$key]['count']++;
            }
        }

        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        if (!rewind($file) || !ftruncate($file, 0)
            || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new RuntimeException('Unable to save login attempts.');
        }
        return 0;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}
