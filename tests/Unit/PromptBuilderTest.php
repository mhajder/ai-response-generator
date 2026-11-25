<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PromptBuilderTest extends TestCase
{
    public function testSelectRecentEntriesKeepsLatestChronologicalAndFiltersNotes(): void
    {
        $entries = [
            ['type' => 'M', 'poster' => 'Alice', 'body' => 'First message'],
            ['type' => 'N', 'poster' => 'Bob', 'body' => 'Internal secret'],
            ['type' => 'R', 'poster' => 'Bob', 'body' => 'First reply'],
            ['type' => 'M', 'poster' => 'Alice', 'body' => 'Latest question'],
        ];

        $withoutNotes = PromptBuilder::selectRecentEntries($entries, 2, false);
        $this->assertCount(2, $withoutNotes);
        $this->assertSame('First reply', $withoutNotes[0]['body']);
        $this->assertSame('Latest question', $withoutNotes[1]['body']);

        $withNotes = PromptBuilder::selectRecentEntries($entries, 10, true);
        $this->assertCount(4, $withNotes);
        $this->assertSame('N', $withNotes[1]['type']);
    }

    public function testBuildMessagesIncludesMetadataFaqsAndMarksInternalNotes(): void
    {
        $messages = PromptBuilder::buildMessages(
            [
                'metadata' => [
                    'ticket_number' => '123456',
                    'ticket_subject' => 'VPN Issue',
                    'ticket_department' => 'IT',
                ],
                'faqs' => [
                    ['question' => 'How to reset VPN?', 'answer' => 'Reinstall profile.'],
                ],
                'entries' => [
                    ['type' => 'M', 'poster' => 'Jane', 'body' => 'Cannot connect'],
                    ['type' => 'N', 'poster' => 'Admin', 'body' => 'Check radius log'],
                ],
            ],
            [
                'action' => PromptBuilder::ACTION_REPLY,
                'include_internal_notes' => true,
                'include_ticket_metadata' => true,
                'tone' => 'concise',
                'output_html' => true,
            ]
        );

        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('Tone: Direct, brief', $messages[0]['content']);
        $this->assertStringContainsString('Ticket Number: 123456', $messages[1]['content']);
        $this->assertStringContainsString('How to reset VPN?', $messages[1]['content']);
        $this->assertStringContainsString('[Customer: Jane] Cannot connect', $messages[2]['content']);
        $this->assertStringContainsString('Internal Note by Admin', $messages[3]['content']);
    }

    public function testExpandTemplateAndHtmlFormatting(): void
    {
        $formatted = PromptBuilder::formatOutputForEditor("Line one\n\n- Item A\n- Item B", true);
        $this->assertStringContainsString('<p>Line one</p>', $formatted);
        $this->assertStringContainsString('<ul><li>Item A</li><li>Item B</li></ul>', $formatted);

        $expanded = PromptBuilder::expandTemplate(
            "Hello {user_name},\n\n{ai_text}\n\nRegards,\n{agent_name}",
            [
                'user_name' => 'John <Doe>',
                'ai_text' => '<p>Resolved.</p>',
                'agent_name' => 'Support Team',
            ],
            true
        );

        $this->assertStringContainsString('Hello John &lt;Doe&gt;,<br>', $expanded);
        $this->assertStringContainsString('<p>Resolved.</p>', $expanded);
    }

    public function testFormatOutputForPlainTextStripsHtml(): void
    {
        $plain = PromptBuilder::formatOutputForEditor('<p>Hello <strong>world</strong></p><p>Next line</p>', false);
        $this->assertSame("Hello world\n\nNext line", $plain);
    }

    public function testTruncatesOversizedThreadEntries(): void
    {
        $hugeBody = str_repeat('A', 2000);
        $messages = PromptBuilder::buildMessages(
            [
                'entries' => [
                    ['type' => 'M', 'poster' => 'Jane', 'body' => $hugeBody],
                ],
            ],
            ['max_entry_chars' => 500]
        );

        $this->assertStringContainsString('... (truncated)', $messages[1]['content']);
        $this->assertLessThan(600, strlen($messages[1]['content']));
    }

    public function testIncludesCustomFieldsCannedResponsesAndSummaryPrompt(): void
    {
        $messages = PromptBuilder::buildMessages(
            [
                'custom_fields' => [
                    ['label' => 'Order ID', 'value' => 'ORD-9912'],
                ],
                'canned' => [
                    ['title' => 'Password Reset', 'response' => 'Visit /reset to reset password.'],
                ],
                'entries' => [
                    ['type' => 'M', 'poster' => 'Jane', 'body' => 'Need reset for ORD-9912'],
                ],
            ],
            [
                'action' => PromptBuilder::ACTION_SUMMARIZE,
                'system_prompt' => 'Customer reply prompt',
                'summary_prompt' => 'Custom internal summary format',
            ]
        );

        $this->assertStringContainsString('Custom internal summary format', $messages[0]['content']);
        $this->assertStringNotContainsString('Customer reply prompt', $messages[0]['content']);
        $this->assertStringContainsString('Order ID: ORD-9912', $messages[1]['content']);
        $this->assertStringContainsString('Template (Password Reset):', $messages[1]['content']);
    }

    public function testRedactPiiMasksSensitiveTokensAndPreservesDatesAndTicketIds(): void
    {
        $raw = 'Contact alice.smith@example.org or +1-555-234-5678 from 192.168.10.42. '
            . 'Card 4532-0151-1283-0366 failed on 2026-11-25 for ticket #186221 running v1.18.4.';

        $redacted = PromptBuilder::redactPii($raw);

        $this->assertStringContainsString('[REDACTED_EMAIL]', $redacted);
        $this->assertStringContainsString('[REDACTED_PHONE]', $redacted);
        $this->assertStringContainsString('[REDACTED_IP]', $redacted);
        $this->assertStringContainsString('[REDACTED_CARD]', $redacted);
        $this->assertStringNotContainsString('alice.smith@example.org', $redacted);
        $this->assertStringNotContainsString('4532-0151-1283-0366', $redacted);
        $this->assertStringContainsString('2026-11-25', $redacted);
        $this->assertStringContainsString('#186221', $redacted);
        $this->assertStringContainsString('v1.18.4', $redacted);
    }

    public function testBuildMessagesFormatsAttachmentsAndAppliesPiiRedaction(): void
    {
        $messages = PromptBuilder::buildMessages(
            [
                'metadata' => [
                    'ticket_number' => '186221',
                    'ticket_subject' => 'Login issue for bob@acme.test',
                    'user_name' => 'Bob',
                    'user_email' => 'bob@acme.test',
                ],
                'entries' => [
                    [
                        'type' => 'M',
                        'poster' => 'bob@acme.test',
                        'body' => 'Please call +48 601 234 567 or check 10.20.30.40.',
                        'attachments' => [
                            ['name' => 'trace-bob@acme.test.har', 'size' => 20480, 'type' => 'application/json'],
                        ],
                    ],
                ],
            ],
            [
                'action' => PromptBuilder::ACTION_REPLY,
                'redact_pii' => true,
            ]
        );

        $this->assertStringContainsString('[REDACTED_EMAIL]', $messages[1]['content']);
        $this->assertStringNotContainsString('bob@acme.test', $messages[1]['content']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $messages[2]['content']);
        $this->assertStringContainsString('[REDACTED_IP]', $messages[2]['content']);
        $this->assertStringContainsString('[Attachments: [REDACTED_EMAIL].har (20 KB, application/json)]', $messages[2]['content']);
    }

    public function testRewriteActionRedactsPiiInDraftTextWhenEnabled(): void
    {
        $messages = PromptBuilder::buildMessages(
            ['entries' => []],
            [
                'action' => PromptBuilder::ACTION_REWRITE,
                'rewrite_mode' => 'improve',
                'draft_text' => 'Hi vip.user@acme.test, call +1-555-867-5309 regarding server 198.51.100.7.',
                'redact_pii' => true,
            ]
        );

        $this->assertStringContainsString('[REDACTED_EMAIL]', $messages[1]['content']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $messages[1]['content']);
        $this->assertStringContainsString('[REDACTED_IP]', $messages[1]['content']);
        $this->assertStringNotContainsString('vip.user@acme.test', $messages[1]['content']);
        $this->assertStringNotContainsString('198.51.100.7', $messages[1]['content']);
    }

    public function testReplyTargetLanguageAndCustomInstructionRedaction(): void
    {
        $messages = PromptBuilder::buildMessages(
            ['entries' => [['type' => 'M', 'poster' => 'Client', 'body' => 'Hello']]],
            [
                'action' => PromptBuilder::ACTION_REPLY,
                'target_language' => 'German',
                'redact_pii' => true,
                'custom_instruction' => 'Escalate to ops@acme.test about 10.0.0.5',
            ]
        );

        $this->assertStringContainsString('Write the reply in German.', $messages[0]['content']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $messages[2]['content']);
        $this->assertStringContainsString('[REDACTED_IP]', $messages[2]['content']);
        $this->assertStringNotContainsString('ops@acme.test', $messages[2]['content']);
    }
}
