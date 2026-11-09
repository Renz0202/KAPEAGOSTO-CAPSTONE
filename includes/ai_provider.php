<?php
function ai_model(array $settings): string
{
    $model = trim((string) ($settings['ai_model'] ?? ''));
    if ($model !== '') return $model;
    return ($settings['ai_provider'] ?? 'openai') === 'gemini' ? 'gemini-flash-lite-latest' : 'gpt-4o-mini';
}

function ai_request_config(array $settings, string $prompt, int $tokens): array
{
    $model = ai_model($settings);
    $key = trim($settings['ai_api_key'] ?? '');
    if (($settings['ai_provider'] ?? 'openai') === 'gemini') {
        return ['url' => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            'headers' => ['x-goog-api-key: ' . $key, 'Content-Type: application/json'],
            'payload' => ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['maxOutputTokens' => max(1024, $tokens)]]];
    }
    return ['url' => 'https://api.openai.com/v1/responses',
        'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        'payload' => ['model' => $model, 'input' => $prompt, 'max_output_tokens' => $tokens, 'store' => false]];
}

function ai_response_text(array $data, string $provider): string
{
    $text = '';
    if ($provider === 'gemini') {
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (empty($part['thought'])) $text .= (string) ($part['text'] ?? '');
        }
    } else {
        $text = (string) ($data['output_text'] ?? '');
        if ($text === '') foreach (($data['output'] ?? []) as $output) foreach (($output['content'] ?? []) as $content) if (($content['type'] ?? '') === 'output_text') $text .= (string) ($content['text'] ?? '');
    }
    return trim($text);
}

function ai_error_code(array $data): string
{
    foreach (($data['error']['details'] ?? []) as $detail) {
        if (in_array($detail['reason'] ?? '', ['API_KEY_INVALID', 'API_KEY_EXPIRED'], true)) return 'invalid_api_key';
    }
    return (string) ($data['error']['status'] ?? $data['error']['code'] ?? '');
}
