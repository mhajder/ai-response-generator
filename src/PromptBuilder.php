<?php

/*********************************************************************
 * Prompt & Formatting Builder
 *
 * Pure PHP helper responsible for constructing LLM chat messages from
 * normalized ticket context, applying response templates, and
 * converting outputs between plain text and rich-text HTML.
 *********************************************************************/

declare(strict_types=1);

class PromptBuilder
{
    public const ACTION_REPLY = 'reply';
    public const ACTION_SUMMARIZE = 'summarize';
    public const ACTION_REWRITE = 'rewrite';
    public const DEFAULT_MAX_ENTRY_CHARS = 6000;

    public const DEFAULT_SYSTEM_PROMPT =
        'You are a helpful support agent. Draft a concise, professional, accurate reply '
        . 'based on the ticket history and knowledge base context. '
        . 'Do not invent facts or policies that are not in the context.';

    /**
     * Build an array of chat messages for the AI provider.
     *
     * @param array{
     *   metadata?: array<string, string>,
     *   custom_fields?: array<int, array{label: string, value: string}>,
     *   entries?: array<int, array{
     *     type: string,
     *     poster: string,
     *     body: string,
     *     created?: string,
     *     attachments?: array<int, array{name: string, size?: int, type?: string}>
     *   }>,
     *   faqs?: array<int, array{question: string, answer: string}>,
     *   canned?: array<int, array{title: string, response: string}>
     * } $context
     * @param array{
     *   action?: string,
     *   system_prompt?: string,
     *   summary_prompt?: string,
     *   rag_content?: string,
     *   max_thread_entries?: int,
     *   include_internal_notes?: bool,
     *   include_ticket_metadata?: bool,
     *   redact_pii?: bool,
     *   output_html?: bool,
     *   tone?: string,
     *   custom_instruction?: string,
     *   draft_text?: string,
     *   rewrite_mode?: string,
     *   target_language?: string,
     *   max_rag_chars?: int,
     *   max_entry_chars?: int
     * } $options
     *
     * @return array<int, array{role: string, content: string}>
     */
    public static function buildMessages(array $context, array $options = []): array
    {
        $action = strtolower(trim((string) ($options['action'] ?? self::ACTION_REPLY)));
        $outputHtml = (bool) ($options['output_html'] ?? false);
        $redactPii = (bool) ($options['redact_pii'] ?? false);
        $systemPrompt = trim((string) ($options['system_prompt'] ?? ''));
        $summaryPrompt = trim((string) ($options['summary_prompt'] ?? ''));
        $customInstruction = trim((string) ($options['custom_instruction'] ?? ''));
        if ($redactPii && $customInstruction !== '') {
            $customInstruction = self::redactPii($customInstruction);
        }

        if ($redactPii) {
            $context = self::redactContext($context);
        }

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => self::buildSystemInstruction(
                $action,
                $action === self::ACTION_SUMMARIZE && $summaryPrompt !== '' ? $summaryPrompt : $systemPrompt,
                $outputHtml,
                $options
            ),
        ];

        $knowledgeParts = [];
        if (($options['include_ticket_metadata'] ?? true) && !empty($context['metadata']) && is_array($context['metadata'])) {
            $metaBlock = self::formatMetadataBlock($context['metadata']);
            if ($metaBlock !== '') {
                $knowledgeParts[] = $metaBlock;
            }
        }

        if (!empty($context['custom_fields']) && is_array($context['custom_fields'])) {
            $customBlock = self::formatCustomFieldsBlock($context['custom_fields']);
            if ($customBlock !== '') {
                $knowledgeParts[] = $customBlock;
            }
        }

        if (!empty($context['faqs']) && is_array($context['faqs'])) {
            $faqBlock = self::formatFaqBlock($context['faqs']);
            if ($faqBlock !== '') {
                $knowledgeParts[] = $faqBlock;
            }
        }

        if (!empty($context['canned']) && is_array($context['canned'])) {
            $cannedBlock = self::formatCannedBlock($context['canned']);
            if ($cannedBlock !== '') {
                $knowledgeParts[] = $cannedBlock;
            }
        }

        $ragContent = self::truncateText(
            trim((string) ($options['rag_content'] ?? '')),
            max(500, (int) ($options['max_rag_chars'] ?? 20000))
        );
        if ($ragContent !== '') {
            $knowledgeParts[] = "Additional knowledge base context:\n" . $ragContent;
        }

        if ($knowledgeParts !== []) {
            $messages[] = [
                'role' => 'system',
                'content' => implode("\n\n", $knowledgeParts),
            ];
        }

        $maxEntryChars = max(500, (int) ($options['max_entry_chars'] ?? self::DEFAULT_MAX_ENTRY_CHARS));

        if ($action === self::ACTION_REWRITE) {
            $rawDraft = trim((string) ($options['draft_text'] ?? ''));
            $draftText = self::truncateText(
                $redactPii ? self::redactPii($rawDraft) : $rawDraft,
                $maxEntryChars
            );
            $ticketSummary = self::formatThreadTranscript(
                $context['entries'] ?? [],
                min(6, max(1, (int) ($options['max_thread_entries'] ?? 20))),
                (bool) ($options['include_internal_notes'] ?? false),
                $maxEntryChars
            );
            $userContent = '';
            if ($ticketSummary !== '') {
                $userContent .= "Ticket conversation context (for reference only):\n" . $ticketSummary . "\n\n";
            }
            $userContent .= "Draft reply to transform:\n" . $draftText;
            if ($customInstruction !== '') {
                $userContent .= "\n\nAdditional instruction: " . $customInstruction;
            }
            $messages[] = ['role' => 'user', 'content' => $userContent];

            return $messages;
        }

        $maxEntries = max(1, min(100, (int) ($options['max_thread_entries'] ?? 20)));
        $includeNotes = (bool) ($options['include_internal_notes'] ?? false);
        $entries = self::selectRecentEntries($context['entries'] ?? [], $maxEntries, $includeNotes);

        if ($action === self::ACTION_SUMMARIZE) {
            $transcript = self::formatSelectedEntries($entries, $maxEntryChars);
            $prompt = "Summarize the following support ticket thread for an internal agent note.\n"
                . "Include: 1) Issue summary, 2) Key technical/customer details, 3) Current status, 4) Recommended next steps.\n\n"
                . ($transcript !== '' ? $transcript : '(No thread entries available.)');
            if ($customInstruction !== '') {
                $prompt .= "\n\nFocus instruction: " . $customInstruction;
            }
            $messages[] = ['role' => 'user', 'content' => $prompt];

            return $messages;
        }

        // Default action: ACTION_REPLY
        foreach ($entries as $entry) {
            $type = strtoupper((string) ($entry['type'] ?? 'M'));
            $poster = trim((string) ($entry['poster'] ?? ''));
            $body = self::truncateText(trim((string) ($entry['body'] ?? '')), $maxEntryChars);
            $attSuffix = self::formatAttachmentsSuffix($entry['attachments'] ?? []);
            if ($body === '' && $attSuffix === '') {
                continue;
            }
            $combinedBody = trim($body . ($attSuffix !== '' ? ($body !== '' ? "\n" : '') . $attSuffix : ''));

            if ($type === 'R') {
                $label = $poster !== '' ? $poster : 'Agent';
                $messages[] = [
                    'role' => 'assistant',
                    'content' => sprintf('[Agent: %s] %s', $label, $combinedBody),
                ];
            } elseif ($type === 'N') {
                $label = $poster !== '' ? $poster : 'Staff';
                $messages[] = [
                    'role' => 'user',
                    'content' => sprintf('[Internal Note by %s — internal context only, do not quote verbatim] %s', $label, $combinedBody),
                ];
            } else {
                $label = $poster !== '' ? $poster : 'Customer';
                $messages[] = [
                    'role' => 'user',
                    'content' => sprintf('[Customer: %s] %s', $label, $combinedBody),
                ];
            }
        }

        $closingPrompt = 'Draft the next reply to the customer based on the conversation above.';
        if ($customInstruction !== '') {
            $closingPrompt .= ' Specific instruction: ' . $customInstruction;
        }
        $messages[] = ['role' => 'user', 'content' => $closingPrompt];

        return $messages;
    }

    /**
     * Mask emails, phone numbers, Luhn-valid credit cards, and IPv4 addresses.
     */
    public static function redactPii(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Email addresses (stop before common attachment file extensions like .pdf/.har/.png)
        $masked = (string) preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.(?!png\b|jpe?g\b|gif\b|webp\b|pdf\b|har\b|log\b|txt\b|csv\b|json\b|xml\b|zip\b)[A-Z]{2,}/i',
            '[REDACTED_EMAIL]',
            $text
        );

        // Credit/debit card numbers (13-19 digits, Luhn-verified to avoid false positives on ticket IDs)
        $masked = (string) preg_replace_callback(
            '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/',
            static function (array $m): string {
                $digits = (string) preg_replace('/\D+/', '', $m[0]);
                $len = strlen($digits);
                if ($len < 13 || $len > 19 || !self::isValidLuhn($digits)) {
                    return $m[0];
                }

                return '[REDACTED_CARD]';
            },
            $masked
        );

        // IPv4 addresses (allow trailing sentence dot, avoid version prefixes like v1.18.4 and 5-octet strings)
        $masked = (string) preg_replace(
            '/(?<![vV\d.])(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)(?:\.(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)){3}(?!\.\d|\d)/',
            '[REDACTED_IP]',
            $masked
        );

        // Phone numbers (international or standard formatted; excludes plain ISO dates YYYY-MM-DD)
        $masked = (string) preg_replace_callback(
            '/(?<!\d)(?:\+\d{1,3}[\s.\-]?|\(\d{2,4}\)[\s.\-]?)\d[\d\s.\-]{5,14}\d(?!\d)|(?<!\d)\d{3}[\s.\-]\d{3}[\s.\-]\d{4}(?!\d)/',
            static function (array $m): string {
                $digits = (string) preg_replace('/\D+/', '', $m[0]);

                return strlen($digits) >= 7 ? '[REDACTED_PHONE]' : $m[0];
            },
            $masked
        );

        return $masked;
    }

    private static function isValidLuhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return $sum > 0 && ($sum % 10) === 0;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function redactContext(array $context): array
    {
        if (!empty($context['metadata']) && is_array($context['metadata'])) {
            foreach ($context['metadata'] as $key => $val) {
                if (is_string($val)) {
                    $context['metadata'][$key] = $key === 'user_email' && $val !== ''
                        ? '[REDACTED_EMAIL]'
                        : self::redactPii($val);
                }
            }
        }

        if (!empty($context['custom_fields']) && is_array($context['custom_fields'])) {
            foreach ($context['custom_fields'] as $idx => $field) {
                if (is_array($field) && isset($field['value']) && is_string($field['value'])) {
                    $context['custom_fields'][$idx]['value'] = self::redactPii($field['value']);
                }
            }
        }

        if (!empty($context['entries']) && is_array($context['entries'])) {
            foreach ($context['entries'] as $idx => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['poster']) && is_string($entry['poster'])) {
                    $context['entries'][$idx]['poster'] = self::redactPii($entry['poster']);
                }
                if (isset($entry['body']) && is_string($entry['body'])) {
                    $context['entries'][$idx]['body'] = self::redactPii($entry['body']);
                }
                if (!empty($entry['attachments']) && is_array($entry['attachments'])) {
                    foreach ($entry['attachments'] as $aIdx => $att) {
                        if (is_array($att) && isset($att['name']) && is_string($att['name'])) {
                            $context['entries'][$idx]['attachments'][$aIdx]['name'] = self::redactPii($att['name']);
                        }
                    }
                }
            }
        }

        return $context;
    }

    /**
     * Return the most recent $maxEntries entries in chronological order.
     *
     * @param array<int, array{
     *   type: string,
     *   poster: string,
     *   body: string,
     *   created?: string,
     *   attachments?: array<int, array{name: string, size?: int, type?: string}>
     * }> $entries
     *
     * @return array<int, array{
     *   type: string,
     *   poster: string,
     *   body: string,
     *   created?: string,
     *   attachments?: array<int, array{name: string, size?: int, type?: string}>
     * }>
     */
    public static function selectRecentEntries(array $entries, int $maxEntries, bool $includeInternalNotes): array
    {
        $filtered = [];
        foreach ($entries as $entry) {
            $type = strtoupper((string) ($entry['type'] ?? 'M'));
            if ($type === 'N' && !$includeInternalNotes) {
                continue;
            }
            if (trim((string) ($entry['body'] ?? '')) === '' && empty($entry['attachments'])) {
                continue;
            }
            $filtered[] = $entry;
        }

        if (count($filtered) > $maxEntries) {
            return array_slice($filtered, -$maxEntries);
        }

        return $filtered;
    }

    /**
     * Expand template placeholders such as {ai_text}, {ticket_number}, etc.
     *
     * @param array<string, string> $vars
     */
    public static function expandTemplate(string $template, array $vars, bool $asHtml = false): string
    {
        $escape = static function (string $value) use ($asHtml): string {
            return $asHtml ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
        };

        $replacements = [
            '{ai_text}' => (string) ($vars['ai_text'] ?? ''),
            '{ticket_number}' => $escape((string) ($vars['ticket_number'] ?? '')),
            '{ticket_subject}' => $escape((string) ($vars['ticket_subject'] ?? '')),
            '{ticket_department}' => $escape((string) ($vars['ticket_department'] ?? '')),
            '{ticket_priority}' => $escape((string) ($vars['ticket_priority'] ?? '')),
            '{ticket_status}' => $escape((string) ($vars['ticket_status'] ?? '')),
            '{user_name}' => $escape((string) ($vars['user_name'] ?? '')),
            '{user_email}' => $escape((string) ($vars['user_email'] ?? '')),
            '{user_org}' => $escape((string) ($vars['user_org'] ?? '')),
            '{agent_name}' => $escape((string) ($vars['agent_name'] ?? '')),
        ];

        $result = strtr($template, $replacements);
        if ($asHtml && !self::looksLikeHtml($template)) {
            // Template itself is plain text with newlines; wrap non-HTML portions cleanly
            $marker = '__AI_TEXT_PLACEHOLDER_' . md5($template) . '__';
            $withMarker = strtr($template, array_merge($replacements, ['{ai_text}' => $marker]));
            $htmlTemplate = nl2br($withMarker, false);
            $aiText = (string) ($vars['ai_text'] ?? '');

            return str_replace($marker, $aiText, $htmlTemplate);
        }

        return $result;
    }

    /**
     * Format AI output to match either rich-text HTML (Redactor) or plain text.
     */
    public static function formatOutputForEditor(string $text, bool $asHtml): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (!$asHtml) {
            if (self::looksLikeHtml($text)) {
                $plain = preg_replace('#<\s*(?:br|/li)\s*/?>#i', "\n", $text);
                $plain = preg_replace('#<\s*(?:/p|/div|/h[1-6])\s*>#i', "\n\n", (string) $plain);
                $plain = strip_tags((string) $plain);
                $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return trim((string) preg_replace("/\n{3,}/", "\n\n", $plain));
            }

            return $text;
        }

        if (self::looksLikeHtml($text)) {
            return $text;
        }

        return self::markdownTextToHtml($text);
    }

    public static function looksLikeHtml(string $text): bool
    {
        return (bool) preg_match('/<\s*(?:p|br|div|ul|ol|li|strong|em|b|i|a|h[1-6]|blockquote|pre|code)\b[^>]*>/i', $text);
    }

    /**
     * Lightweight Markdown/plain-text to HTML converter for Redactor editor insertion.
     */
    public static function markdownTextToHtml(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($normalized === '') {
            return '';
        }

        $blocks = preg_split("/\n{2,}/", $normalized) ?: [$normalized];
        $htmlBlocks = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            $isBulleted = true;
            $isNumbered = true;
            foreach ($lines as $line) {
                $trimmedLine = ltrim($line);
                if (!preg_match('/^[-*•]\s+/', $trimmedLine)) {
                    $isBulleted = false;
                }
                if (!preg_match('/^\d+[.)]\s+/', $trimmedLine)) {
                    $isNumbered = false;
                }
            }

            if ($isBulleted) {
                $items = [];
                foreach ($lines as $line) {
                    $clean = (string) preg_replace('/^\s*[-*•]\s+/', '', $line);
                    $items[] = '<li>' . self::formatInlineMarkdown($clean) . '</li>';
                }
                $htmlBlocks[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            if ($isNumbered) {
                $items = [];
                foreach ($lines as $line) {
                    $clean = (string) preg_replace('/^\s*\d+[.)]\s+/', '', $line);
                    $items[] = '<li>' . self::formatInlineMarkdown($clean) . '</li>';
                }
                $htmlBlocks[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }

            $formattedLines = array_map(
                static fn (string $l): string => self::formatInlineMarkdown($l),
                $lines
            );
            $htmlBlocks[] = '<p>' . implode('<br>', $formattedLines) . '</p>';
        }

        return implode("\n", $htmlBlocks);
    }

    private static function formatInlineMarkdown(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);

        return $escaped;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function buildSystemInstruction(
        string $action,
        string $configuredPrompt,
        bool $outputHtml,
        array $options
    ): string {
        $base = $configuredPrompt !== '' ? $configuredPrompt : self::DEFAULT_SYSTEM_PROMPT;

        $parts = [$base];

        if ($action === self::ACTION_SUMMARIZE) {
            $parts[] = 'Task: Summarize the ticket clearly and objectively for internal staff review.';
        } elseif ($action === self::ACTION_REWRITE) {
            $mode = strtolower(trim((string) ($options['rewrite_mode'] ?? 'improve')));
            $lang = trim((string) ($options['target_language'] ?? ''));
            $modeGuide = match ($mode) {
                'shorter' => 'Make the draft more concise while preserving all key facts and next steps.',
                'formal' => 'Rewrite the draft in a polished, formal business tone.',
                'friendly' => 'Rewrite the draft in a warm, empathetic, approachable support tone.',
                'translate' => 'Translate the draft accurately into '
                    . ($lang !== '' ? $lang : 'the customer\'s language')
                    . ' while keeping a natural support tone.',
                'custom' => 'Rewrite the draft following the agent\'s specific instruction.',
                default => 'Improve grammar, clarity, structure, and professionalism of the draft without changing its meaning.',
            };
            $parts[] = 'Task: ' . $modeGuide . ' Return only the revised message text without preamble.';
        } else {
            $tone = strtolower(trim((string) ($options['tone'] ?? '')));
            if ($tone !== '') {
                $parts[] = match ($tone) {
                    'friendly' => 'Tone: Warm, empathetic, and approachable.',
                    'concise' => 'Tone: Direct, brief, and to the point.',
                    'technical' => 'Tone: Precise, technical, and step-by-step.',
                    default => 'Tone: Clear, polite, and professional.',
                };
            }
            $targetLanguage = trim((string) ($options['target_language'] ?? ''));
            if ($targetLanguage !== '') {
                $parts[] = 'Write the reply in ' . $targetLanguage . '.';
            } else {
                $parts[] = 'Match the language of the customer\'s latest message unless instructed otherwise.';
            }
        }

        $parts[] = $outputHtml
            ? 'Format: Output clean, minimal HTML paragraphs or lists suitable for a rich-text email reply '
                . '(no <html>/<body> wrappers or markdown code fences).'
            : 'Format: Output clean plain text (no markdown code fences).';

        return implode("\n", $parts);
    }

    /**
     * @param array<string, string> $metadata
     */
    private static function formatMetadataBlock(array $metadata): string
    {
        $lines = [];
        $labels = [
            'ticket_number' => 'Ticket Number',
            'ticket_subject' => 'Subject',
            'ticket_department' => 'Department',
            'ticket_topic' => 'Help Topic',
            'ticket_priority' => 'Priority',
            'ticket_status' => 'Status',
            'user_name' => 'Customer',
            'user_org' => 'Organization',
        ];
        foreach ($labels as $key => $label) {
            $val = trim((string) ($metadata[$key] ?? ''));
            if ($val !== '') {
                $lines[] = sprintf('- %s: %s', $label, $val);
            }
        }
        if ($lines === []) {
            return '';
        }

        return "Ticket Metadata:\n" . implode("\n", $lines);
    }

    /**
     * @param array<int, array{question: string, answer: string}> $faqs
     */
    private static function formatFaqBlock(array $faqs): string
    {
        $items = [];
        foreach ($faqs as $faq) {
            $q = trim((string) ($faq['question'] ?? ''));
            $a = trim((string) ($faq['answer'] ?? ''));
            if ($q === '' && $a === '') {
                continue;
            }
            $items[] = sprintf("Q: %s\nA: %s", $q, $a);
        }
        if ($items === []) {
            return '';
        }

        return "Relevant Knowledge Base FAQs:\n" . implode("\n\n", $items);
    }

    /**
     * @param array<int, array{label: string, value: string}> $fields
     */
    private static function formatCustomFieldsBlock(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            $value = trim((string) ($field['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $lines[] = sprintf('- %s: %s', $label, $value);
        }
        if ($lines === []) {
            return '';
        }

        return "Ticket Custom Fields:\n" . implode("\n", $lines);
    }

    /**
     * @param array<int, array{title: string, response: string}> $canned
     */
    private static function formatCannedBlock(array $canned): string
    {
        $items = [];
        foreach ($canned as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $response = trim((string) ($item['response'] ?? ''));
            if ($title === '' && $response === '') {
                continue;
            }
            $items[] = sprintf("Template (%s):\n%s", $title !== '' ? $title : 'Canned Response', $response);
        }
        if ($items === []) {
            return '';
        }

        return "Relevant Canned Responses:\n" . implode("\n\n", $items);
    }

    /**
     * @param array<int, array{type: string, poster: string, body: string, created?: string}> $entries
     */
    private static function formatThreadTranscript(
        array $entries,
        int $maxEntries,
        bool $includeInternalNotes,
        int $maxEntryChars = self::DEFAULT_MAX_ENTRY_CHARS
    ): string {
        $recent = self::selectRecentEntries($entries, $maxEntries, $includeInternalNotes);

        return self::formatSelectedEntries($recent, $maxEntryChars);
    }

    /**
     * @param array<int, array{
     *   type: string,
     *   poster: string,
     *   body: string,
     *   created?: string,
     *   attachments?: array<int, array{name: string, size?: int, type?: string}>
     * }> $entries
     */
    private static function formatSelectedEntries(array $entries, int $maxEntryChars): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $type = strtoupper((string) ($entry['type'] ?? 'M'));
            $poster = trim((string) ($entry['poster'] ?? ''));
            $body = self::truncateText(trim((string) ($entry['body'] ?? '')), $maxEntryChars);
            $attSuffix = self::formatAttachmentsSuffix($entry['attachments'] ?? []);
            $combined = trim($body . ($attSuffix !== '' ? ($body !== '' ? "\n" : '') . $attSuffix : ''));
            $roleLabel = match ($type) {
                'R' => 'Agent',
                'N' => 'Internal Note',
                default => 'Customer',
            };
            $who = $poster !== '' ? "{$roleLabel} ({$poster})" : $roleLabel;
            $lines[] = sprintf('[%s] %s', $who, $combined);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param array<int, array{name: string, size?: int, type?: string}> $attachments
     */
    private static function formatAttachmentsSuffix(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $items = [];
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $name = trim((string) ($att['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $details = [];
            $size = isset($att['size']) ? (int) $att['size'] : 0;
            if ($size > 0) {
                $details[] = self::formatByteSize($size);
            }
            $type = trim((string) ($att['type'] ?? ''));
            if ($type !== '') {
                $details[] = $type;
            }
            $items[] = $details !== [] ? sprintf('%s (%s)', $name, implode(', ', $details)) : $name;
        }

        return $items !== [] ? '[Attachments: ' . implode('; ', $items) . ']' : '';
    }

    private static function formatByteSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return (int) max(1, round($bytes / 1024)) . ' KB';
        }

        return $bytes . ' B';
    }

    private static function truncateText(string $text, int $limitChars): string
    {
        if ($text === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length <= $limitChars) {
            return $text;
        }
        $sliced = function_exists('mb_substr')
            ? mb_substr($text, 0, $limitChars, 'UTF-8')
            : substr($text, 0, $limitChars);

        return $sliced . "\n... (truncated)";
    }
}
