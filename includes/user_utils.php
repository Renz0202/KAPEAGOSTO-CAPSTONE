<?php

/**
 * Normalize a person's name for display and storage.
 * Example: "jomar bayawa" becomes "Jomar Bayawa".
 */
function user_title_case(?string $value, string $fallback = ''): string {
    $value = preg_replace('/\s+/u', ' ', trim((string) $value));
    if ($value === null || $value === '') {
        return $fallback;
    }

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    return ucwords(strtolower($value));
}

/**
 * Format a username for display without changing its stored login value.
 */
function user_username_display(?string $username, string $fallback = 'User'): string {
    $username = trim((string) $username);
    if ($username === '') {
        return $fallback;
    }

    $formatted = preg_replace_callback(
        '/(^|[\s._-])([a-z])/i',
        static fn(array $match): string => $match[1] . strtoupper($match[2]),
        strtolower($username)
    );

    return $formatted !== null ? $formatted : ucfirst(strtolower($username));
}

/**
 * Split both the new "Last, First Middle" format and legacy "First Middle Last"
 * values into the three name fields used by account forms.
 */
function user_name_parts(?string $fullName): array {
    $fullName = preg_replace('/\s+/u', ' ', trim((string) $fullName));
    if ($fullName === null || $fullName === '') {
        return ['last_name' => '', 'first_name' => '', 'middle_name' => ''];
    }

    if (str_contains($fullName, ',')) {
        [$lastName, $givenNames] = array_pad(explode(',', $fullName, 2), 2, '');
        $givenParts = preg_split('/\s+/u', trim($givenNames)) ?: [];
        $firstName = array_shift($givenParts) ?? '';

        return [
            'last_name' => user_title_case($lastName),
            'first_name' => user_title_case($firstName),
            'middle_name' => user_title_case(implode(' ', $givenParts)),
        ];
    }

    $parts = preg_split('/\s+/u', $fullName) ?: [];
    if (count($parts) === 1) {
        return ['last_name' => '', 'first_name' => user_title_case($parts[0]), 'middle_name' => ''];
    }

    $firstName = array_shift($parts) ?? '';
    $lastName = array_pop($parts) ?? '';

    return [
        'last_name' => user_title_case($lastName),
        'first_name' => user_title_case($firstName),
        'middle_name' => user_title_case(implode(' ', $parts)),
    ];
}

/**
 * Build the canonical account name stored in users.full_name.
 */
function user_build_full_name(?string $lastName, ?string $firstName, ?string $middleName = null): string {
    $lastName = user_title_case($lastName);
    $firstName = user_title_case($firstName);
    $middleName = user_title_case($middleName);

    $givenName = trim($firstName . ($middleName !== '' ? ' ' . $middleName : ''));
    if ($lastName !== '' && $givenName !== '') {
        return $lastName . ', ' . $givenName;
    }

    return trim($lastName . ($lastName !== '' && $givenName !== '' ? ', ' : '') . $givenName);
}

/**
 * Format existing names consistently without requiring a database migration.
 */
function user_formatted_full_name(?string $fullName, string $fallback = ''): string {
    $parts = user_name_parts($fullName);
    $formatted = user_build_full_name($parts['last_name'], $parts['first_name'], $parts['middle_name']);
    return $formatted !== '' ? $formatted : $fallback;
}

/**
 * Prefer the structured database columns, with a legacy full_name fallback.
 */
function user_name_parts_from_record(array $user): array {
    $lastName = user_title_case($user['last_name'] ?? '');
    $firstName = user_title_case($user['first_name'] ?? '');
    $middleName = user_title_case($user['middle_name'] ?? '');

    if ($lastName !== '' || $firstName !== '' || $middleName !== '') {
        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
        ];
    }

    return user_name_parts($user['full_name'] ?? '');
}

function user_formatted_name_from_record(array $user, string $fallback = ''): string {
    $parts = user_name_parts_from_record($user);
    $formatted = user_build_full_name($parts['last_name'], $parts['first_name'], $parts['middle_name']);
    return $formatted !== '' ? $formatted : $fallback;
}
