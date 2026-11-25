<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OpenAIClientTest extends TestCase
{
    public function testDetectsReasoningModels(): void
    {
        $this->assertTrue(OpenAIClient::isReasoningModel('gpt-5-nano-2025-08-07'));
        $this->assertTrue(OpenAIClient::isReasoningModel('gpt-5'));
        $this->assertTrue(OpenAIClient::isReasoningModel('o1-preview'));
        $this->assertTrue(OpenAIClient::isReasoningModel('o3-mini'));
        $this->assertTrue(OpenAIClient::isReasoningModel('o4-mini'));
        $this->assertTrue(OpenAIClient::isReasoningModel('openai/gpt-5-mini'));

        $this->assertFalse(OpenAIClient::isReasoningModel('gpt-4o-mini'));
        $this->assertFalse(OpenAIClient::isReasoningModel('claude-sonnet-4-5'));
        $this->assertFalse(OpenAIClient::isReasoningModel('llama3.3'));
    }

    public function testChatCompletionsStandardModelPayloadAndUrl(): void
    {
        $captured = [];
        $client = new OpenAIClient('https://api.openai.com/v1/', 'sk-test', [
            'http_client' => static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
                return [
                    'status' => 200,
                    'body' => json_encode(['choices' => [['message' => ['content' => 'Hello customer']]]]),
                ];
            },
        ]);

        $reply = $client->generateResponse('gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']], 0.3, 800);

        $this->assertSame('Hello customer', $reply);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $captured['url']);
        $this->assertContains('Authorization: Bearer sk-test', $captured['headers']);
        $this->assertSame(800, $captured['body']['max_tokens']);
        $this->assertSame(0.3, $captured['body']['temperature']);
        $this->assertArrayNotHasKey('max_completion_tokens', $captured['body']);
    }

    public function testChatCompletionsReasoningModelUsesMaxCompletionTokensAndOmitsTemperature(): void
    {
        $captured = [];
        $client = new OpenAIClient('https://api.openai.com/v1/chat/completions', 'sk-test', [
            'reasoning_effort' => 'low',
            'http_client' => static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = ['url' => $url, 'body' => json_decode($body, true)];
                return [
                    'status' => 200,
                    'body' => json_encode(['choices' => [['message' => ['content' => 'Reasoning ok']]]]),
                ];
            },
        ]);

        $reply = $client->generateResponse('gpt-5-nano-2025-08-07', [['role' => 'user', 'content' => 'Hi']], 0.2, 1500);

        $this->assertSame('Reasoning ok', $reply);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $captured['url']);
        $this->assertSame(1500, $captured['body']['max_completion_tokens']);
        $this->assertSame('low', $captured['body']['reasoning_effort']);
        $this->assertArrayNotHasKey('max_tokens', $captured['body']);
        $this->assertArrayNotHasKey('temperature', $captured['body']);
    }

    public function testAdaptiveRetryWhenServerRejectsMaxTokensAndTemperature(): void
    {
        $attempts = 0;
        $client = new OpenAIClient('https://custom.llm.local/v1', 'sk-local', [
            'http_client' => static function (string $url, array $headers, string $body) use (&$attempts): array {
                $attempts++;
                $decoded = json_decode($body, true);
                if ($attempts === 1) {
                    return [
                        'status' => 400,
                        'body' => json_encode(['error' => ['message' => "Unsupported parameter: 'max_tokens'"]]),
                    ];
                }
                if ($attempts === 2) {
                    return [
                        'status' => 400,
                        'body' => json_encode(['error' => ['message' => "Unsupported value: 'temperature'"]]),
                    ];
                }
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'choices' => [['message' => ['content' => 'Adapted: ' . $decoded['max_completion_tokens']]]],
                    ]),
                ];
            },
        ]);

        $reply = $client->generateResponse('future-model-x', [['role' => 'user', 'content' => 'Test']], 0.5, 900);

        $this->assertSame('Adapted: 900', $reply);
        $this->assertSame(3, $attempts);
    }

    public function testOpenAIResponsesApi(): void
    {
        $captured = [];
        $client = new OpenAIClient('https://api.openai.com/v1/responses', 'sk-resp', [
            'http_client' => static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = ['url' => $url, 'body' => json_decode($body, true)];
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'output' => [
                            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Responses reply']]],
                        ],
                    ]),
                ];
            },
        ]);

        $reply = $client->generateResponse('gpt-5-nano', [
            ['role' => 'system', 'content' => 'Be concise'],
            ['role' => 'user', 'content' => 'Help me'],
        ], 0.2, 1000);

        $this->assertSame('Responses reply', $reply);
        $this->assertSame('Be concise', $captured['body']['instructions']);
        $this->assertSame(1000, $captured['body']['max_output_tokens']);
    }

    public function testAnthropicMessagesApi(): void
    {
        $captured = [];
        $client = new OpenAIClient('https://api.anthropic.com', 'sk-ant', [
            'provider' => OpenAIClient::PROVIDER_ANTHROPIC,
            'http_client' => static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
                return [
                    'status' => 200,
                    'body' => json_encode(['content' => [['type' => 'text', 'text' => 'Claude reply']]]),
                ];
            },
        ]);

        $reply = $client->generateResponse('claude-sonnet-4-5', [
            ['role' => 'system', 'content' => 'Policy A'],
            ['role' => 'user', 'content' => 'Question 1'],
            ['role' => 'user', 'content' => 'Question 2'],
        ]);

        $this->assertSame('Claude reply', $reply);
        $this->assertSame('https://api.anthropic.com/v1/messages', $captured['url']);
        $this->assertContains('x-api-key: sk-ant', $captured['headers']);
        $this->assertSame('Policy A', $captured['body']['system']);
        $this->assertCount(1, $captured['body']['messages']);
        $this->assertSame("Question 1\n\nQuestion 2", $captured['body']['messages'][0]['content']);
    }

    public function testThrowsHelpfulErrorWhenReasoningHitsTokenLimit(): void
    {
        $client = new OpenAIClient('https://api.openai.com/v1', 'sk-test', [
            'http_client' => static fn (): array => [
                'status' => 200,
                'body' => json_encode([
                    'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
                ]),
            ],
        ]);

        $this->expectException(AIClientException::class);
        $this->expectExceptionMessage('Max Output Tokens');
        $client->generateResponse('gpt-5-nano', [['role' => 'user', 'content' => 'Hi']]);
    }

    public function testOllamaNativeApi(): void
    {
        $captured = [];
        $client = new OpenAIClient('http://localhost:11434', null, [
            'provider' => OpenAIClient::PROVIDER_OLLAMA,
            'http_client' => static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = ['url' => $url, 'body' => json_decode($body, true)];
                return [
                    'status' => 200,
                    'body' => json_encode(['message' => ['content' => 'Ollama reply']]),
                ];
            },
        ]);

        $reply = $client->generateResponse('llama3.3', [['role' => 'user', 'content' => 'Hi']], 0.4, 600);

        $this->assertSame('Ollama reply', $reply);
        $this->assertSame('http://localhost:11434/api/chat', $captured['url']);
        $this->assertFalse($captured['body']['stream']);
        $this->assertSame(600, $captured['body']['options']['num_predict']);
    }

    public function testThrowsAIClientExceptionOnHttpError(): void
    {
        $client = new OpenAIClient('https://api.openai.com/v1', 'bad-key', [
            'http_client' => static fn (): array => [
                'status' => 401,
                'body' => json_encode(['error' => ['message' => 'Incorrect API key provided']]),
            ],
        ]);

        $this->expectException(AIClientException::class);
        $this->expectExceptionMessage('Incorrect API key provided');
        $client->generateResponse('gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']]);
    }

    public function testConnectionUsesAdequateTokenBudgetForReasoningModels(): void
    {
        $captured = [];
        $client = new OpenAIClient('https://api.openai.com/v1', 'sk-test', [
            'http_client' => static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = json_decode($body, true);
                return [
                    'status' => 200,
                    'body' => json_encode(['choices' => [['message' => ['content' => 'OK']]]]),
                ];
            },
        ]);

        $res = $client->testConnection('gpt-5-nano');
        $this->assertTrue($res['ok']);
        $this->assertGreaterThanOrEqual(512, $captured['max_completion_tokens']);
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $client = new OpenAIClient('file:///etc/passwd', null, [
            'http_client' => static fn (): array => ['status' => 200, 'body' => '{}'],
        ]);

        $this->expectException(AIClientException::class);
        $this->expectExceptionMessage('http or https');
        $client->generateResponse('gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']]);
    }

    public function testGenerateResponseWithMetaExtractsUsageAndProvider(): void
    {
        $client = new OpenAIClient('https://api.openai.com/v1', 'sk-test', [
            'http_client' => static fn (): array => [
                'status' => 200,
                'body' => json_encode([
                    'choices' => [['message' => ['content' => 'Done']]],
                    'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 45, 'total_tokens' => 165],
                ]),
            ],
        ]);

        $res = $client->generateResponseWithMeta('gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']]);
        $this->assertSame('Done', $res['text']);
        $this->assertSame('openai_chat', $res['provider']);
        $this->assertSame(120, $res['usage']['input_tokens']);
        $this->assertSame(45, $res['usage']['output_tokens']);
        $this->assertSame(165, $res['usage']['total_tokens']);
    }

    public function testParseStreamLineSupportsAllProviders(): void
    {
        $this->assertSame(
            'Hi',
            OpenAIClient::parseStreamLine('data: {"choices":[{"delta":{"content":"Hi"}}]}', OpenAIClient::PROVIDER_OPENAI_CHAT)
        );
        $this->assertSame(
            ' there',
            OpenAIClient::parseStreamLine(
                'data: {"type":"response.output_text.delta","delta":" there"}',
                OpenAIClient::PROVIDER_OPENAI_RESPONSES
            )
        );
        $this->assertSame(
            '!',
            OpenAIClient::parseStreamLine(
                'data: {"type":"content_block_delta","delta":{"text":"!"}}',
                OpenAIClient::PROVIDER_ANTHROPIC
            )
        );
        $this->assertSame(
            'Local',
            OpenAIClient::parseStreamLine('{"message":{"content":"Local"}}', OpenAIClient::PROVIDER_OLLAMA)
        );
        $this->assertSame('', OpenAIClient::parseStreamLine('data: [DONE]'));
    }

    public function testParseStreamEventExtractsUsageAcrossProviders(): void
    {
        $chatUsage = OpenAIClient::parseStreamEvent(
            'data: {"choices":[],"usage":{"prompt_tokens":210,"completion_tokens":48,"total_tokens":258}}',
            OpenAIClient::PROVIDER_OPENAI_CHAT
        );
        $this->assertSame('', $chatUsage['delta']);
        $this->assertSame(210, $chatUsage['usage']['input_tokens']);
        $this->assertSame(48, $chatUsage['usage']['output_tokens']);
        $this->assertSame(258, $chatUsage['usage']['total_tokens']);

        $respUsage = OpenAIClient::parseStreamEvent(
            'data: {"type":"response.completed","response":{"usage":{"input_tokens":80,"output_tokens":22,"total_tokens":102}}}',
            OpenAIClient::PROVIDER_OPENAI_RESPONSES
        );
        $this->assertSame(80, $respUsage['usage']['input_tokens']);
        $this->assertSame(22, $respUsage['usage']['output_tokens']);
        $this->assertSame(102, $respUsage['usage']['total_tokens']);

        $anthStart = OpenAIClient::parseStreamEvent(
            'data: {"type":"message_start","message":{"usage":{"input_tokens":95}}}',
            OpenAIClient::PROVIDER_ANTHROPIC
        );
        $this->assertSame(95, $anthStart['usage']['input_tokens']);

        $anthDelta = OpenAIClient::parseStreamEvent(
            'data: {"type":"message_delta","usage":{"output_tokens":31}}',
            OpenAIClient::PROVIDER_ANTHROPIC
        );
        $this->assertSame(31, $anthDelta['usage']['output_tokens']);

        $ollamaDone = OpenAIClient::parseStreamEvent(
            '{"message":{"content":""},"done":true,"prompt_eval_count":64,"eval_count":19}',
            OpenAIClient::PROVIDER_OLLAMA
        );
        $this->assertSame(64, $ollamaDone['usage']['input_tokens']);
        $this->assertSame(19, $ollamaDone['usage']['output_tokens']);
        $this->assertSame(83, $ollamaDone['usage']['total_tokens']);
    }

    public function testParseStreamEventExtractsProviderErrors(): void
    {
        $anthErr = OpenAIClient::parseStreamEvent(
            'data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}',
            OpenAIClient::PROVIDER_ANTHROPIC
        );
        $this->assertSame('Overloaded', $anthErr['error']);

        $respErr = OpenAIClient::parseStreamEvent(
            'data: {"type":"response.failed","response":{"error":{"message":"Quota exceeded"}}}',
            OpenAIClient::PROVIDER_OPENAI_RESPONSES
        );
        $this->assertSame('Quota exceeded', $respErr['error']);

        $ollamaErr = OpenAIClient::parseStreamEvent(
            '{"error":"model runner has unexpectedly stopped"}',
            OpenAIClient::PROVIDER_OLLAMA
        );
        $this->assertSame('model runner has unexpectedly stopped', $ollamaErr['error']);
    }

    public function testBoundedRetryOnTransient429And503(): void
    {
        $attempts = 0;
        $client = new OpenAIClient('https://api.openai.com/v1', 'sk-test', [
            'retry_delay_ms' => 0,
            'http_client' => static function () use (&$attempts): array {
                $attempts++;
                if ($attempts === 1) {
                    return [
                        'status' => 429,
                        'retry_after' => 1,
                        'body' => json_encode(['error' => ['message' => 'Rate limited']]),
                    ];
                }

                return [
                    'status' => 200,
                    'body' => json_encode(['choices' => [['message' => ['content' => 'Recovered after 429']]]]),
                ];
            },
        ]);

        $reply = $client->generateResponse('gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']]);
        $this->assertSame(2, $attempts);
        $this->assertSame('Recovered after 429', $reply);
    }
}
