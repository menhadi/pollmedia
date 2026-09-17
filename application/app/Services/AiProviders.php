<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiProviders
{
    public const LABELS = ['openai' => 'OpenAI', 'deepseek' => 'DeepSeek', 'gemini' => 'Gemini', 'claude' => 'Claude'];

    public function settings(string $provider): array
    {
        if (! isset(self::LABELS[$provider])) {
            throw new RuntimeException('Choose a supported AI provider.');
        }
        $saved = DB::table('ai_provider_settings')->where('provider', $provider)->first();
        $environment = $provider === 'openai' ? ['key' => config('seo-ai.key'), 'model' => config('seo-ai.model')] : config('seo-ai.providers.'.$provider, []);

        return ['key' => $saved?->encrypted_key ? Crypt::decryptString($saved->encrypted_key) : ($environment['key'] ?? null),
            'model' => $saved?->model ?? ($environment['model'] ?? null)];
    }

    public function options(): array
    {
        $options = [];
        foreach (self::LABELS as $id => $label) {
            $settings = $this->settings($id);
            $options[$id] = ['label' => $label, 'model' => $settings['model'], 'has_key' => filled($settings['key']),
                'configured' => filled($settings['key']) && filled($settings['model'])];
        }

        return $options;
    }

    public function request(string $provider, array $input, array $schema, string $instructions): array
    {
        $settings = $this->settings($provider);
        $label = self::LABELS[$provider];
        if (blank($settings['key']) || blank($settings['model'])) {
            throw new RuntimeException($label.' needs an API key and model in AI provider settings. Template drafts are still available.');
        }
        $model = $settings['model'];
        $json = json_encode($input, JSON_THROW_ON_ERROR);
        $bundle = config('seo-ai.ca_bundle');
        $http = Http::acceptJson()->connectTimeout(10)->timeout(45)
            ->withOptions(['allow_redirects' => false, 'verify' => $bundle && is_file($bundle) ? $bundle : true]);
        [$url, $headers, $body] = match ($provider) {
            'openai' => ['https://api.openai.com/v1/responses', ['Authorization' => 'Bearer '.$settings['key']], [
                'model' => $model, 'store' => false, 'max_output_tokens' => 4096, 'instructions' => $instructions, 'input' => $json,
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'seo_drafts', 'strict' => true, 'schema' => $schema]],
            ]],
            'deepseek' => ['https://api.deepseek.com/chat/completions', ['Authorization' => 'Bearer '.$settings['key']], [
                'model' => $model, 'max_tokens' => 4096, 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $instructions.' Return JSON matching this schema: '.json_encode($schema).'. Example shape: {"pages":[{"id":0,"title":"Place | Pollmedia","description":"Public data and official sources."}]}'], ['role' => 'user', 'content' => $json]],
            ]],
            'gemini' => ['https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', ['x-goog-api-key' => $settings['key']], [
                'systemInstruction' => ['parts' => [['text' => $instructions]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $json]]]],
                'generationConfig' => ['maxOutputTokens' => 4096, 'responseFormat' => ['text' => ['mimeType' => 'application/json', 'schema' => $schema]]],
            ]],
            'claude' => ['https://api.anthropic.com/v1/messages', ['x-api-key' => $settings['key'], 'anthropic-version' => '2023-06-01'], [
                'model' => $model, 'max_tokens' => 4096, 'system' => $instructions, 'messages' => [['role' => 'user', 'content' => $json]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            ]],
        };
        try {
            $response = $http->withHeaders($headers)->post($url, $body);
        } catch (ConnectionException) {
            throw new RuntimeException($label.' could not be reached in time. No draft was saved and no automatic retry was made.');
        }
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => $label.' access was rejected. Check the configured API key and model permissions.',
                429 => $label.' quota or rate limit reached. Check your API billing and usage limits.',
                default => $label.' could not complete this request. Check model support or try a template draft.',
            });
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException($label.' returned an unreadable response. Nothing was saved.');
        }
        if ($provider === 'openai') {
            return $data + ['model' => $model];
        }
        $complete = match ($provider) {
            'deepseek' => data_get($data, 'choices.0.finish_reason') === 'stop',
            'gemini' => data_get($data, 'candidates.0.finishReason') === 'STOP' && ! data_get($data, 'promptFeedback.blockReason'),
            'claude' => data_get($data, 'stop_reason') === 'end_turn',
        };
        $text = match ($provider) {
            'deepseek' => data_get($data, 'choices.0.message.content', ''),
            'gemini' => collect(data_get($data, 'candidates.0.content.parts', []))->reject(fn ($part) => $part['thought'] ?? false)->pluck('text')->implode(''),
            'claude' => collect(data_get($data, 'content', []))->where('type', 'text')->pluck('text')->implode(''),
        };

        return ['status' => $complete ? 'completed' : 'incomplete', 'model' => $data['model'] ?? $data['modelVersion'] ?? $model,
            'output' => [['content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage' => ['input_tokens' => data_get($data, match ($provider) {
                'deepseek' => 'usage.prompt_tokens', 'gemini' => 'usageMetadata.promptTokenCount', 'claude' => 'usage.input_tokens'
            }),
                'output_tokens' => data_get($data, match ($provider) {
                    'deepseek' => 'usage.completion_tokens', 'gemini' => 'usageMetadata.candidatesTokenCount', 'claude' => 'usage.output_tokens'
                })]];
    }
}
