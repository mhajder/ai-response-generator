<?php

/*********************************************************************
 * Multi-provider AI API Client
 *
 * Supports OpenAI Chat Completions (and compatible servers such as
 * OpenRouter, vLLM, LM Studio), OpenAI Responses API, Azure OpenAI,
 * Anthropic Messages API, and Ollama native /api/chat.
 *********************************************************************/

declare(strict_types=1);

require_once __DIR__ . '/../src/AIClientException.php';

class OpenAIClient
{
    public const PROVIDER_OPENAI_CHAT = 'openai_chat';
    public const PROVIDER_OPENAI_RESPONSES = 'openai_responses';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OLLAMA = 'ollama';
    public const PROVIDER_AZURE_OPENAI = 'azure_openai';

    private string $baseUrl;
    private ?string $apiKey;
    private string $provider;
    private int $timeout;
    private ?string $reasoningEffort;
    private int $retryDelayMs;

    /** @var callable|null */
    private $httpClient;

    /**
     * @param array{
     *   provider?: string,
     *   timeout?: int,
     *   reasoning_effort?: string|null,
     *   retry_delay_ms?: int,
     *   http_client?: callable|null
     * } $options
     */
    public function __construct(string $baseUrl, ?string $apiKey = null, array $options = [])
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->apiKey = $apiKey !== null && trim($apiKey) !== '' ? trim($apiKey) : null;
        $this->provider = $this->resolveProvider($options['provider'] ?? 'auto', $this->baseUrl);
        $timeout = (int) ($options['timeout'] ?? 45);
        $this->timeout = max(5, min(300, $timeout));
        $effort = isset($options['reasoning_effort']) ? trim((string) $options['reasoning_effort']) : '';
        $this->reasoningEffort = $effort !== '' ? $effort : null;
        $retryDelayMs = (int) ($options['retry_delay_ms'] ?? 250);
        $this->retryDelayMs = max(0, min(3000, $retryDelayMs));
        $this->httpClient = $options['http_client'] ?? null;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Detect whether a model name belongs to a reasoning / GPT-5 series that
     * expects max_completion_tokens and rejects non-default temperature.
     */
    public static function isReasoningModel(string $model): bool
    {
        $normalized = strtolower(trim($model));
        if ($normalized === '') {
            return false;
        }
        // Handle both direct model IDs (gpt-5-nano, o3-mini) and vendor-prefixed IDs (openai/gpt-5)
        if (str_contains($normalized, '/')) {
            $parts = explode('/', $normalized);
            $normalized = end($parts);
        }

        return (bool) preg_match('/^(?:gpt-5|o1|o3|o4)(?:[-_.:]|$)/', $normalized);
    }

    /**
     * Send chat messages to the configured AI provider and return the text response.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws AIClientException
     */
    public function generateResponse(
        string $model,
        array $messages,
        float|int|null $temperature = 0.2,
        int $maxTokens = 1500
    ): string {
        $result = $this->generateResponseWithMeta($model, $messages, $temperature, $maxTokens);

        return $result['text'];
    }

    /**
     * Send chat messages and return text plus provider, latency, and normalized token usage.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array{
     *   text: string,
     *   provider: string,
     *   latency_ms: int,
     *   usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     * }
     *
     * @throws AIClientException
     */
    public function generateResponseWithMeta(
        string $model,
        array $messages,
        float|int|null $temperature = 0.2,
        int $maxTokens = 1500
    ): array {
        $model = trim($model);
        $this->assertValidTarget($model);

        $maxTokens = max(1, $maxTokens);
        $url = $this->buildEndpointUrl();
        $headers = $this->buildHeaders();
        $payload = $this->buildPayload($model, $messages, $temperature, $maxTokens);

        $start = microtime(true);
        $response = $this->sendWithAdaptiveFallback($url, $headers, $payload);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $text = $this->extractText($response);

        if (trim($text) === '' && (($response['choices'][0]['finish_reason'] ?? '') === 'length')) {
            throw new AIClientException(
                'Model reached max output tokens before producing text. Increase Max Output Tokens in plugin settings.'
            );
        }

        return [
            'text' => $text,
            'provider' => $this->provider,
            'latency_ms' => $latencyMs,
            'usage' => $this->extractUsage($response),
        ];
    }

    /**
     * Stream response deltas via $onDelta(string $delta) when supported, returning
     * full metadata and complete text. Falls back cleanly to non-streaming when
     * a custom http_client is injected or provider is Azure/unsupported.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string): void                           $onDelta
     *
     * @return array{
     *   text: string,
     *   provider: string,
     *   latency_ms: int,
     *   usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     * }
     *
     * @throws AIClientException
     */
    public function streamResponse(
        string $model,
        array $messages,
        callable $onDelta,
        float|int|null $temperature = 0.2,
        int $maxTokens = 1500
    ): array {
        $model = trim($model);
        $this->assertValidTarget($model);

        if ($this->httpClient !== null || !function_exists('curl_init')) {
            $result = $this->generateResponseWithMeta($model, $messages, $temperature, $maxTokens);
            if ($result['text'] !== '') {
                $onDelta($result['text']);
            }

            return $result;
        }

        $maxTokens = max(1, $maxTokens);
        $url = $this->buildEndpointUrl();
        $headers = $this->buildHeaders();
        $payload = $this->buildPayload($model, $messages, $temperature, $maxTokens);
        $payload['stream'] = true;
        if ($this->provider === self::PROVIDER_OPENAI_CHAT) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $start = microtime(true);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->executeCurlStream($url, $headers, $payload, $onDelta, $start);
            } catch (AIClientException $e) {
                $status = $e->getStatusCode();
                $msg = $e->getMessage();

                if ($status === 400 && isset($payload['stream_options']) && stripos($msg, 'stream_options') !== false) {
                    unset($payload['stream_options']);
                    continue;
                }

                if ($status === 400 && (stripos($msg, 'max_tokens') !== false || stripos($msg, 'temperature') !== false)) {
                    $fallback = $this->generateResponseWithMeta($model, $messages, $temperature, $maxTokens);
                    if ($fallback['text'] !== '') {
                        $onDelta($fallback['text']);
                    }

                    return $fallback;
                }

                throw $e;
            }
        }

        throw new AIClientException('Streaming failed after fallback retry.');
    }

    /**
     * @param string[]                                          $headers
     * @param array<string, mixed>                              $payload
     * @param callable(string): void                            $onDelta
     *
     * @return array{
     *   text: string,
     *   provider: string,
     *   latency_ms: int,
     *   usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     * }
     *
     * @throws AIClientException
     */
    private function executeCurlStream(
        string $url,
        array $headers,
        array $payload,
        callable $onDelta,
        float $start
    ): array {
        $buffer = '';
        $fullText = '';
        $rawError = '';
        $streamError = null;
        $provider = $this->provider;
        $usage = ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new AIClientException('Failed to initialize cURL handle.');
        }

        $mergeUsage = static function (array $partial) use (&$usage): void {
            if ($partial['input_tokens'] !== null) {
                $usage['input_tokens'] = $partial['input_tokens'];
            }
            if ($partial['output_tokens'] !== null) {
                $usage['output_tokens'] = $partial['output_tokens'];
            }
            if ($partial['total_tokens'] !== null) {
                $usage['total_tokens'] = $partial['total_tokens'];
            } elseif ($usage['input_tokens'] !== null && $usage['output_tokens'] !== null) {
                $usage['total_tokens'] = $usage['input_tokens'] + $usage['output_tokens'];
            }
        };

        $writeCallback = static function (
            mixed $handle,
            string $chunk
        ) use (
            &$buffer,
            &$fullText,
            &$rawError,
            &$streamError,
            $onDelta,
            $provider,
            $mergeUsage
        ): int {
            if (PHP_SAPI !== 'cli' && function_exists('connection_aborted') && connection_aborted() !== 0) {
                return 0;
            }

            $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($statusCode >= 400) {
                $rawError .= $chunk;

                return strlen($chunk);
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = (string) array_pop($lines);

            foreach ($lines as $line) {
                $event = self::parseStreamEvent($line, $provider);
                if ($event['error'] !== null) {
                    $streamError = $event['error'];

                    return 0;
                }
                if ($event['delta'] !== '') {
                    $fullText .= $event['delta'];
                    $onDelta($event['delta']);
                }
                if ($event['usage'] !== null) {
                    $mergeUsage($event['usage']);
                }
            }

            return strlen($chunk);
        };

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POSTFIELDS => $this->encodeJson($payload),
            CURLOPT_WRITEFUNCTION => $writeCallback,
        ]);

        $ok = curl_exec($ch);
        if ($streamError !== null) {
            throw new AIClientException('API stream error: ' . $streamError);
        }
        if ($ok === false) {
            $err = curl_error($ch);
            throw new AIClientException('cURL streaming error: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status >= 400) {
            $decodedErr = $this->tryDecodeJson($rawError);
            $msg = $this->extractErrorMessage($decodedErr, $rawError);
            throw new AIClientException(sprintf('API error (HTTP %d): %s', $status, $msg), $status, $rawError);
        }

        if (trim($buffer) !== '') {
            $tail = self::parseStreamEvent($buffer, $provider);
            if ($tail['error'] !== null) {
                throw new AIClientException('API stream error: ' . $tail['error']);
            }
            if ($tail['delta'] !== '') {
                $fullText .= $tail['delta'];
                $onDelta($tail['delta']);
            }
            if ($tail['usage'] !== null) {
                $mergeUsage($tail['usage']);
            }
        }

        return [
            'text' => $fullText,
            'provider' => $this->provider,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'usage' => $usage,
        ];
    }

    public static function parseStreamLine(string $line, string $provider = self::PROVIDER_OPENAI_CHAT): string
    {
        return self::parseStreamEvent($line, $provider)['delta'];
    }

    /**
     * Parse a single SSE/NDJSON line into text delta, optional token usage, or error message.
     *
     * @return array{
     *   delta: string,
     *   usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}|null,
     *   error: string|null
     * }
     */
    public static function parseStreamEvent(string $line, string $provider = self::PROVIDER_OPENAI_CHAT): array
    {
        $empty = ['delta' => '', 'usage' => null, 'error' => null];
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, 'event:') || $trimmed === 'data: [DONE]') {
            return $empty;
        }

        if (str_starts_with($trimmed, 'data:')) {
            $trimmed = trim(substr($trimmed, 5));
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $empty;
        }
        if (!is_array($decoded)) {
            return $empty;
        }

        $streamError = self::extractStreamError($decoded);
        if ($streamError !== null) {
            return ['delta' => '', 'usage' => null, 'error' => $streamError];
        }

        $delta = '';

        // OpenAI Chat Completions / Azure
        if (isset($decoded['choices'][0]['delta']['content']) && is_string($decoded['choices'][0]['delta']['content'])) {
            $delta = $decoded['choices'][0]['delta']['content'];
        } elseif (($decoded['type'] ?? '') === 'response.output_text.delta' && isset($decoded['delta']) && is_string($decoded['delta'])) {
            $delta = $decoded['delta'];
        } elseif (
            ($decoded['type'] ?? '') === 'content_block_delta'
            && isset($decoded['delta']['text'])
            && is_string($decoded['delta']['text'])
        ) {
            $delta = $decoded['delta']['text'];
        } elseif (
            $provider === self::PROVIDER_OLLAMA
            && isset($decoded['message']['content'])
            && is_string($decoded['message']['content'])
        ) {
            $delta = $decoded['message']['content'];
        }

        $usage = self::extractStreamUsage($decoded);

        return [
            'delta' => $delta,
            'usage' => $usage,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractStreamError(array $decoded): ?string
    {
        $type = (string) ($decoded['type'] ?? '');
        if ($type === 'response.failed') {
            $msg = $decoded['response']['error']['message']
                ?? $decoded['error']['message']
                ?? 'OpenAI Responses stream failed.';

            return trim((string) $msg) !== '' ? trim((string) $msg) : 'OpenAI Responses stream failed.';
        }

        if ($type === 'error' || isset($decoded['error'])) {
            $err = $decoded['error'] ?? null;
            if (is_array($err) && isset($err['message']) && is_string($err['message']) && trim($err['message']) !== '') {
                return trim($err['message']);
            }
            if (is_string($err) && trim($err) !== '') {
                return trim($err);
            }
            if (isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '') {
                return trim($decoded['message']);
            }

            return 'Upstream streaming error.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}|null
     */
    private static function extractStreamUsage(array $decoded): ?array
    {
        $usageSource = null;
        if (isset($decoded['usage']) && is_array($decoded['usage'])) {
            $usageSource = $decoded['usage'];
        } elseif (isset($decoded['response']['usage']) && is_array($decoded['response']['usage'])) {
            $usageSource = $decoded['response']['usage'];
        } elseif (isset($decoded['message']['usage']) && is_array($decoded['message']['usage'])) {
            $usageSource = $decoded['message']['usage'];
        }

        if (is_array($usageSource)) {
            $input = isset($usageSource['prompt_tokens'])
                ? (int) $usageSource['prompt_tokens']
                : (isset($usageSource['input_tokens']) ? (int) $usageSource['input_tokens'] : null);
            $output = isset($usageSource['completion_tokens'])
                ? (int) $usageSource['completion_tokens']
                : (isset($usageSource['output_tokens']) ? (int) $usageSource['output_tokens'] : null);
            $total = isset($usageSource['total_tokens'])
                ? (int) $usageSource['total_tokens']
                : ($input !== null && $output !== null ? $input + $output : null);

            if ($input !== null || $output !== null || $total !== null) {
                return [
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                    'total_tokens' => $total,
                ];
            }
        }

        if (isset($decoded['prompt_eval_count']) || isset($decoded['eval_count'])) {
            $input = isset($decoded['prompt_eval_count']) ? (int) $decoded['prompt_eval_count'] : null;
            $output = isset($decoded['eval_count']) ? (int) $decoded['eval_count'] : null;

            return [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $input !== null && $output !== null ? $input + $output : null,
            ];
        }

        return null;
    }

    private function assertValidTarget(string $model): void
    {
        if ($model === '') {
            throw new AIClientException('Model name is required.');
        }
        if ($this->baseUrl === '') {
            throw new AIClientException('API URL is required.');
        }
        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new AIClientException('API URL must use the http or https scheme.');
        }
    }

    /**
     * Perform a minimal connection test against the configured endpoint.
     *
     * @return array{ok: bool, latency_ms: int, reply: string}
     *
     * @throws AIClientException
     */
    public function testConnection(string $model): array
    {
        $start = microtime(true);
        $maxTokens = self::isReasoningModel($model) ? 512 : 128;
        $reply = $this->generateResponse(
            $model,
            [
                ['role' => 'system', 'content' => 'Reply with exactly: OK'],
                ['role' => 'user', 'content' => 'Health check.'],
            ],
            0.0,
            $maxTokens
        );
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return [
            'ok' => true,
            'latency_ms' => $latencyMs,
            'reply' => trim($reply),
        ];
    }

    private function resolveProvider(string $configured, string $url): string
    {
        $configured = strtolower(trim($configured));
        $valid = [
            self::PROVIDER_OPENAI_CHAT,
            self::PROVIDER_OPENAI_RESPONSES,
            self::PROVIDER_ANTHROPIC,
            self::PROVIDER_OLLAMA,
            self::PROVIDER_AZURE_OPENAI,
        ];
        if (in_array($configured, $valid, true)) {
            return $configured;
        }

        $lowerUrl = strtolower($url);
        if (str_contains($lowerUrl, '/responses')) {
            return self::PROVIDER_OPENAI_RESPONSES;
        }
        if (str_contains($lowerUrl, 'anthropic.com') || preg_match('#/messages(?:\?.*)?$#', $lowerUrl)) {
            return self::PROVIDER_ANTHROPIC;
        }
        if (str_contains($lowerUrl, '.openai.azure.com')) {
            return self::PROVIDER_AZURE_OPENAI;
        }
        if (preg_match('#/api/chat(?:\?.*)?$#', $lowerUrl)) {
            return self::PROVIDER_OLLAMA;
        }

        return self::PROVIDER_OPENAI_CHAT;
    }

    private function buildEndpointUrl(): string
    {
        $url = $this->baseUrl;

        return match ($this->provider) {
            self::PROVIDER_OPENAI_RESPONSES => preg_match('#/responses(?:\?.*)?$#i', $url)
                ? $url
                : $url . '/responses',
            self::PROVIDER_ANTHROPIC => preg_match('#/messages(?:\?.*)?$#i', $url)
                ? $url
                : (preg_match('#/v\d+$#i', $url) ? $url . '/messages' : $url . '/v1/messages'),
            self::PROVIDER_OLLAMA => preg_match('#/api/chat(?:\?.*)?$#i', $url)
                ? $url
                : $url . '/api/chat',
            self::PROVIDER_AZURE_OPENAI => $this->buildAzureUrl($url),
            default => preg_match('#/chat/(?:completions|complete)(?:\?.*)?$#i', $url)
                ? $url
                : $url . '/chat/completions',
        };
    }

    private function buildAzureUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if (!preg_match('#/chat/completions$#i', $path)) {
            $baseOnly = preg_replace('#\?.*$#', '', $url);
            $url = rtrim((string) $baseOnly, '/') . '/chat/completions';
            if ($query !== '') {
                $url .= '?' . $query;
            }
        }
        if (!str_contains(strtolower($url), 'api-version=')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'api-version=2024-10-21';
        }

        return $url;
    }

    /**
     * @return string[]
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->apiKey === null) {
            return $headers;
        }

        return match ($this->provider) {
            self::PROVIDER_ANTHROPIC => array_merge($headers, [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ]),
            self::PROVIDER_AZURE_OPENAI => array_merge($headers, [
                'api-key: ' . $this->apiKey,
            ]),
            default => array_merge($headers, [
                'Authorization: Bearer ' . $this->apiKey,
            ]),
        };
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $model,
        array $messages,
        float|int|null $temperature,
        int $maxTokens
    ): array {
        return match ($this->provider) {
            self::PROVIDER_OPENAI_RESPONSES => $this->buildResponsesPayload($model, $messages, $temperature, $maxTokens),
            self::PROVIDER_ANTHROPIC => $this->buildAnthropicPayload($model, $messages, $temperature, $maxTokens),
            self::PROVIDER_OLLAMA => $this->buildOllamaPayload($model, $messages, $temperature, $maxTokens),
            default => $this->buildChatCompletionsPayload($model, $messages, $temperature, $maxTokens),
        };
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildChatCompletionsPayload(
        string $model,
        array $messages,
        float|int|null $temperature,
        int $maxTokens
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (self::isReasoningModel($model)) {
            $payload['max_completion_tokens'] = $maxTokens;
            if ($this->reasoningEffort !== null) {
                $payload['reasoning_effort'] = $this->reasoningEffort;
            }
        } else {
            $payload['max_tokens'] = $maxTokens;
            if ($temperature !== null) {
                $payload['temperature'] = (float) $temperature;
            }
        }

        return $payload;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildResponsesPayload(
        string $model,
        array $messages,
        float|int|null $temperature,
        int $maxTokens
    ): array {
        $instructions = [];
        $input = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = (string) ($msg['content'] ?? '');
            if ($role === 'system' || $role === 'developer') {
                $instructions[] = $content;
                continue;
            }
            $input[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        $payload = [
            'model' => $model,
            'input' => $input ?: [['role' => 'user', 'content' => '']],
            'max_output_tokens' => $maxTokens,
        ];

        if ($instructions !== []) {
            $payload['instructions'] = implode("\n\n", $instructions);
        }

        if (self::isReasoningModel($model)) {
            if ($this->reasoningEffort !== null) {
                $payload['reasoning'] = ['effort' => $this->reasoningEffort];
            }
        } elseif ($temperature !== null) {
            $payload['temperature'] = (float) $temperature;
        }

        return $payload;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildAnthropicPayload(
        string $model,
        array $messages,
        float|int|null $temperature,
        int $maxTokens
    ): array {
        $systemParts = [];
        $chat = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                $systemParts[] = $content;
                continue;
            }
            $mappedRole = $role === 'assistant' ? 'assistant' : 'user';
            $lastIndex = count($chat) - 1;
            if ($lastIndex >= 0 && $chat[$lastIndex]['role'] === $mappedRole) {
                $chat[$lastIndex]['content'] .= "\n\n" . $content;
            } else {
                $chat[] = ['role' => $mappedRole, 'content' => $content];
            }
        }

        if ($chat === [] || $chat[0]['role'] !== 'user') {
            array_unshift($chat, ['role' => 'user', 'content' => 'Please draft a response.']);
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $chat,
        ];
        if ($systemParts !== []) {
            $payload['system'] = implode("\n\n", $systemParts);
        }
        if ($temperature !== null) {
            $payload['temperature'] = (float) $temperature;
        }

        return $payload;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildOllamaPayload(
        string $model,
        array $messages,
        float|int|null $temperature,
        int $maxTokens
    ): array {
        $options = ['num_predict' => $maxTokens];
        if ($temperature !== null) {
            $options['temperature'] = (float) $temperature;
        }

        return [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => $options,
        ];
    }

    /**
     * Send HTTP request and automatically adapt if an OpenAI-compatible server
     * rejects max_tokens or temperature on a newer/reasoning model.
     *
     * @param string[]             $headers
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sendWithAdaptiveFallback(string $url, array $headers, array $payload): array
    {
        $transientRetries = 0;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $encoded = $this->encodeJson($payload);
            $result = $this->performRequest($url, $headers, $encoded);
            $status = $result['status'];
            $body = $result['body'];

            $decoded = $this->tryDecodeJson($body);
            if ($status < 400) {
                return $decoded ?? ['_raw' => $body];
            }

            $errorMessage = $this->extractErrorMessage($decoded, $body);

            if ($transientRetries < 1 && in_array($status, [429, 502, 503, 504], true)) {
                $transientRetries++;
                $delayMs = $this->resolveRetryDelayMs($result['retry_after'] ?? null);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                continue;
            }

            if ($status === 400 && $attempt < 2) {
                $retried = false;
                $supportsMaxCompletionTokens = in_array(
                    $this->provider,
                    [self::PROVIDER_OPENAI_CHAT, self::PROVIDER_AZURE_OPENAI],
                    true
                );
                if (
                    $supportsMaxCompletionTokens
                    && isset($payload['max_tokens'])
                    && stripos($errorMessage, 'max_tokens') !== false
                ) {
                    $payload['max_completion_tokens'] = $payload['max_tokens'];
                    unset($payload['max_tokens']);
                    $retried = true;
                }
                if (isset($payload['temperature']) && stripos($errorMessage, 'temperature') !== false) {
                    unset($payload['temperature']);
                    $retried = true;
                }
                if ($retried) {
                    continue;
                }
            }

            throw new AIClientException(
                sprintf('API error (HTTP %d): %s', $status, $errorMessage),
                $status,
                $body
            );
        }

        throw new AIClientException('API request failed after parameter adaptation.');
    }

    private function resolveRetryDelayMs(?int $retryAfterSeconds): int
    {
        if ($this->retryDelayMs === 0) {
            return 0;
        }
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return min(3000, $retryAfterSeconds * 1000);
        }

        return $this->retryDelayMs;
    }

    /**
     * @param string[] $headers
     *
     * @return array{status: int, body: string, retry_after?: int|null}
     */
    private function performRequest(string $url, array $headers, string $body): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($url, $headers, $body, $this->timeout);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new AIClientException('Failed to initialize cURL handle.');
            }
            $retryAfter = null;
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HEADERFUNCTION => static function (mixed $handle, string $headerLine) use (&$retryAfter): int {
                    unset($handle);
                    if (preg_match('/^Retry-After:\s*(\d+)/i', trim($headerLine), $m)) {
                        $retryAfter = (int) $m[1];
                    }

                    return strlen($headerLine);
                },
            ]);

            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                throw new AIClientException('cURL error: ' . $err);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return [
                'status' => $status,
                'body' => (string) $resp,
                'retry_after' => $retryAfter,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);
        $http_response_header = [];
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            $err = error_get_last()['message'] ?? 'HTTP stream request failed';
            throw new AIClientException('HTTP error: ' . $err);
        }
        $status = 200;
        $retryAfter = null;
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
                $status = (int) $m[1];
            } elseif (preg_match('/^Retry-After:\s*(\d+)/i', trim($headerLine), $m)) {
                $retryAfter = (int) $m[1];
            }
        }

        return [
            'status' => $status,
            'body' => $resp,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractText(array $json): string
    {
        // 1. OpenAI Chat Completions (choices[0].message.content)
        if (isset($json['choices'][0]['message']['content'])) {
            $content = $json['choices'][0]['message']['content'];
            if (is_string($content)) {
                return $content;
            }
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $parts[] = (string) $part['text'];
                    }
                }
                if ($parts !== []) {
                    return implode('', $parts);
                }
            }
        }

        // Legacy completions
        if (isset($json['choices'][0]['text'])) {
            return (string) $json['choices'][0]['text'];
        }

        // 2. OpenAI Responses API (output_text or output[].content[].text)
        if (isset($json['output_text']) && is_string($json['output_text'])) {
            return $json['output_text'];
        }
        if (isset($json['output']) && is_string($json['output'])) {
            return $json['output'];
        }
        if (isset($json['output']) && is_array($json['output'])) {
            $chunks = [];
            foreach ($json['output'] as $item) {
                if (!is_array($item) || !isset($item['content']) || !is_array($item['content'])) {
                    continue;
                }
                foreach ($item['content'] as $block) {
                    if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                        $chunks[] = $block['text'];
                    }
                }
            }
            if ($chunks !== []) {
                return implode("\n", $chunks);
            }
        }

        // 3. Anthropic Messages API (content[].text)
        if (isset($json['content']) && is_array($json['content'])) {
            $chunks = [];
            foreach ($json['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $chunks[] = (string) $block['text'];
                }
            }
            if ($chunks !== []) {
                return implode("\n", $chunks);
            }
        }

        // 4. Ollama native /api/chat (message.content)
        if (isset($json['message']['content']) && is_string($json['message']['content'])) {
            return $json['message']['content'];
        }

        // 5. Fallback raw string
        if (isset($json['_raw']) && is_string($json['_raw'])) {
            return $json['_raw'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    private function extractUsage(array $json): array
    {
        $usage = $json['usage'] ?? null;
        if (is_array($usage)) {
            $input = isset($usage['prompt_tokens'])
                ? (int) $usage['prompt_tokens']
                : (isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null);
            $output = isset($usage['completion_tokens'])
                ? (int) $usage['completion_tokens']
                : (isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null);
            $total = isset($usage['total_tokens'])
                ? (int) $usage['total_tokens']
                : ($input !== null && $output !== null ? $input + $output : null);

            return [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $total,
            ];
        }

        // Ollama native /api/chat usage fields
        if (isset($json['prompt_eval_count']) || isset($json['eval_count'])) {
            $input = isset($json['prompt_eval_count']) ? (int) $json['prompt_eval_count'] : null;
            $output = isset($json['eval_count']) ? (int) $json['eval_count'] : null;

            return [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $input !== null && $output !== null ? $input + $output : null,
            ];
        }

        return ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null];
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function extractErrorMessage(?array $decoded, string $rawBody): string
    {
        if (is_array($decoded)) {
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                return $decoded['error']['message'];
            }
            if (isset($decoded['error']) && is_string($decoded['error'])) {
                return $decoded['error'];
            }
            if (isset($decoded['message']) && is_string($decoded['message'])) {
                return $decoded['message'];
            }
        }

        $trimmed = trim($rawBody);

        return $trimmed !== '' ? substr($trimmed, 0, 500) : 'Unknown error';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new AIClientException('Failed to encode request JSON: ' . $e->getMessage(), 0, '', $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryDecodeJson(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }
}
