<?php
if (!function_exists('kape_public_base_url')) {
    function kape_public_base_url(): string
    {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $publicPos = stripos($scriptName, '/public/');

        if ($publicPos !== false) {
            return substr($scriptName, 0, $publicPos + strlen('/public'));
        }

        return rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    }
}

if (!function_exists('kape_asset_url')) {
    function kape_asset_url(string $path): string
    {
        return kape_public_base_url() . '/' . ltrim($path, '/');
    }
}
