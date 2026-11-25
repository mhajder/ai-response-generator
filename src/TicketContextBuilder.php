<?php

/*********************************************************************
 * osTicket Context Builder
 *
 * Extracts normalized metadata, custom ticket form fields, chronological
 * thread entries, and optional matching FAQs / Canned Responses.
 *********************************************************************/

declare(strict_types=1);

class TicketContextBuilder
{
    private const MAX_KB_CANDIDATES = 250;
    private const MAX_ENTRY_ATTACHMENTS = 10;

    /**
     * Build a normalized context array consumable by PromptBuilder.
     *
     * @return array{
     *   metadata: array<string, string>,
     *   custom_fields: array<int, array{label: string, value: string}>,
     *   entries: array<int, array{
     *     type: string,
     *     poster: string,
     *     body: string,
     *     created: string,
     *     attachments: array<int, array{name: string, size: int, type: string}>
     *   }>,
     *   faqs: array<int, array{question: string, answer: string}>,
     *   canned: array<int, array{title: string, response: string}>
     * }
     */
    public static function build(
        Ticket $ticket,
        bool $includeFaqs = false,
        bool $includeCustomFields = true,
        bool $includeCanned = false
    ): array {
        $metadata = self::extractMetadata($ticket);
        $customFields = $includeCustomFields ? self::extractCustomFields($ticket) : [];
        $entries = self::extractThreadEntries($ticket);
        $keywords = ($includeFaqs || $includeCanned) ? self::buildQueryKeywords($metadata, $entries) : [];

        return [
            'metadata' => $metadata,
            'custom_fields' => $customFields,
            'entries' => $entries,
            'faqs' => ($includeFaqs && $keywords !== []) ? self::searchMatchingFaqs($keywords) : [],
            'canned' => ($includeCanned && $keywords !== []) ? self::searchMatchingCanned($ticket, $keywords) : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function extractMetadata(Ticket $ticket): array
    {
        $user = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;
        $dept = method_exists($ticket, 'getDept') ? $ticket->getDept() : null;
        $topic = method_exists($ticket, 'getHelpTopic') ? $ticket->getHelpTopic() : null;
        $priority = method_exists($ticket, 'getPriority') ? $ticket->getPriority() : null;
        $status = method_exists($ticket, 'getStatus') ? $ticket->getStatus() : null;
        $org = ($user && method_exists($user, 'getOrganization')) ? $user->getOrganization() : null;

        return [
            'ticket_number' => (string) $ticket->getNumber(),
            'ticket_subject' => (string) $ticket->getSubject(),
            'ticket_department' => self::stringifyObject($dept),
            'ticket_topic' => self::stringifyObject($topic),
            'ticket_priority' => self::stringifyObject($priority),
            'ticket_status' => self::stringifyObject($status),
            'user_name' => $user && method_exists($user, 'getName') ? (string) $user->getName() : '',
            'user_email' => $user && method_exists($user, 'getEmail') ? (string) $user->getEmail() : '',
            'user_org' => self::stringifyObject($org),
        ];
    }

    /**
     * Extract non-empty custom dynamic form fields attached to the ticket.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function extractCustomFields(Ticket $ticket): array
    {
        if (!class_exists('DynamicFormEntry')) {
            $dfFile = defined('INCLUDE_DIR') ? INCLUDE_DIR . 'class.dynamic_forms.php' : '';
            if ($dfFile !== '' && is_file($dfFile)) {
                require_once $dfFile;
            }
        }
        if (!class_exists('DynamicFormEntry') || !method_exists('DynamicFormEntry', 'forTicket')) {
            return [];
        }

        $skipNames = ['subject', 'message', 'priority'];
        $fields = [];

        try {
            $entries = DynamicFormEntry::forTicket((int) $ticket->getId());
            if (!$entries) {
                return [];
            }

            foreach ($entries as $entry) {
                if (!is_object($entry) || !method_exists($entry, 'getAnswers')) {
                    continue;
                }
                foreach ($entry->getAnswers() as $answer) {
                    if (!is_object($answer)) {
                        continue;
                    }
                    $field = method_exists($answer, 'getField') ? $answer->getField() : null;
                    if (!is_object($field)) {
                        continue;
                    }
                    $name = method_exists($field, 'get') ? strtolower((string) $field->get('name')) : '';
                    if (in_array($name, $skipNames, true)) {
                        continue;
                    }
                    $label = method_exists($field, 'get') ? trim((string) $field->get('label')) : '';
                    $rawVal = method_exists($answer, 'display')
                        ? (string) $answer->display()
                        : (method_exists($answer, 'getValue') ? self::stringifyMixed($answer->getValue()) : '');
                    $value = self::htmlToPlainText($rawVal);
                    if ($label === '' || $value === '') {
                        continue;
                    }
                    $fields[] = [
                        'label' => $label,
                        'value' => self::truncateText($value, 400),
                    ];
                    if (count($fields) >= 25) {
                        return $fields;
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $fields;
    }

    /**
     * @return array<int, array{
     *   type: string,
     *   poster: string,
     *   body: string,
     *   created: string,
     *   attachments: array<int, array{name: string, size: int, type: string}>
     * }>
     */
    public static function extractThreadEntries(Ticket $ticket): array
    {
        $thread = method_exists($ticket, 'getThread') ? $ticket->getThread() : null;
        if (!$thread || !method_exists($thread, 'getEntries')) {
            return [];
        }

        $rawEntries = $thread->getEntries();
        if (!$rawEntries) {
            return [];
        }

        $result = [];
        foreach ($rawEntries as $entry) {
            if (!is_object($entry) || !method_exists($entry, 'getType')) {
                continue;
            }
            $type = strtoupper((string) $entry->getType());
            if (!in_array($type, ['M', 'R', 'N'], true)) {
                continue;
            }

            $body = self::extractEntryText($entry);
            $attachments = self::extractEntryAttachments($entry);
            if ($body === '' && $attachments === []) {
                continue;
            }

            $posterObj = method_exists($entry, 'getPoster') ? $entry->getPoster() : null;
            $poster = self::stringifyObject($posterObj);
            if ($poster === '') {
                $poster = $type === 'M' ? 'Customer' : 'Staff';
            }

            $created = method_exists($entry, 'getCreateDate') ? (string) $entry->getCreateDate() : '';

            $result[] = [
                'type' => $type,
                'poster' => $poster,
                'body' => $body,
                'created' => $created,
                'attachments' => $attachments,
            ];
        }

        return $result;
    }

    /**
     * Extract non-inline attachment metadata from a thread entry.
     *
     * @return array<int, array{name: string, size: int, type: string}>
     */
    private static function extractEntryAttachments(object $entry): array
    {
        if (!method_exists($entry, 'getAttachments')) {
            return [];
        }

        $raw = $entry->getAttachments();
        if (!is_iterable($raw)) {
            return [];
        }

        $attachments = [];
        foreach ($raw as $att) {
            if (!is_object($att)) {
                continue;
            }
            if (!empty($att->inline)) {
                continue;
            }

            $name = method_exists($att, 'getFilename')
                ? trim((string) $att->getFilename())
                : (isset($att->name) ? trim((string) $att->name) : '');
            if ($name === '') {
                continue;
            }

            $file = $att->file ?? null;
            $size = 0;
            $type = '';
            if (is_object($file)) {
                if (method_exists($file, 'getSize')) {
                    $size = (int) $file->getSize();
                } elseif (isset($file->size)) {
                    $size = (int) $file->size;
                }
                if (method_exists($file, 'getType')) {
                    $type = trim((string) $file->getType());
                } elseif (isset($file->type)) {
                    $type = trim((string) $file->type);
                }
            }

            $attachments[] = [
                'name' => self::truncateText($name, 140),
                'size' => max(0, $size),
                'type' => $type,
            ];
            if (count($attachments) >= self::MAX_ENTRY_ATTACHMENTS) {
                break;
            }
        }

        return $attachments;
    }

    /**
     * @param array<string, string>                                                                     $metadata
     * @param array<int, array{type: string, poster: string, body: string, created?: string}>          $entries
     *
     * @return string[]
     */
    private static function buildQueryKeywords(array $metadata, array $entries): array
    {
        $querySeed = trim((string) ($metadata['ticket_subject'] ?? ''));
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            if ($entries[$i]['type'] === 'M') {
                $querySeed .= ' ' . $entries[$i]['body'];
                break;
            }
        }

        return self::extractKeywords($querySeed);
    }

    /**
     * @param string[] $keywords
     *
     * @return array<int, array{question: string, answer: string}>
     */
    private static function searchMatchingFaqs(array $keywords): array
    {
        if (!class_exists('FAQ')) {
            $faqFile = defined('INCLUDE_DIR') ? INCLUDE_DIR . 'class.faq.php' : '';
            if ($faqFile !== '' && is_file($faqFile)) {
                require_once $faqFile;
            }
        }
        if (!class_exists('FAQ') || !method_exists('FAQ', 'objects')) {
            return [];
        }

        try {
            $candidates = [];
            $qs = FAQ::objects()->filter(['ispublished__gt' => 0])->limit(self::MAX_KB_CANDIDATES);
            $scanned = 0;
            foreach ($qs as $faq) {
                if (++$scanned > self::MAX_KB_CANDIDATES) {
                    break;
                }
                if (method_exists($faq, 'isPublished') && !$faq->isPublished()) {
                    continue;
                }
                $question = method_exists($faq, 'getQuestion') ? (string) $faq->getQuestion() : '';
                $answer = self::htmlToPlainText(method_exists($faq, 'getAnswer') ? (string) $faq->getAnswer() : '');
                $candidates[] = ['title' => $question, 'body' => $answer];
            }

            $top = self::rankKeywordMatches($candidates, $keywords, 3, 1200);

            return array_map(
                static fn (array $item): array => [
                    'question' => $item['title'],
                    'answer' => $item['body'],
                ],
                $top
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param string[] $keywords
     *
     * @return array<int, array{title: string, response: string}>
     */
    private static function searchMatchingCanned(Ticket $ticket, array $keywords): array
    {
        if (!class_exists('Canned')) {
            $cannedFile = defined('INCLUDE_DIR') ? INCLUDE_DIR . 'class.canned.php' : '';
            if ($cannedFile !== '' && is_file($cannedFile)) {
                require_once $cannedFile;
            }
        }
        if (!class_exists('Canned') || !method_exists('Canned', 'objects')) {
            return [];
        }

        $ticketDeptId = method_exists($ticket, 'getDeptId') ? (int) $ticket->getDeptId() : 0;

        try {
            $candidates = [];
            $qs = Canned::objects()->filter(['isenabled' => 1])->limit(self::MAX_KB_CANDIDATES);
            $scanned = 0;
            foreach ($qs as $canned) {
                if (++$scanned > self::MAX_KB_CANDIDATES) {
                    break;
                }
                if (method_exists($canned, 'isEnabled') && !$canned->isEnabled()) {
                    continue;
                }
                if (method_exists($canned, 'getDeptId')) {
                    $cannedDept = (int) $canned->getDeptId();
                    if ($cannedDept > 0 && $ticketDeptId > 0 && $cannedDept !== $ticketDeptId) {
                        continue;
                    }
                }
                $title = method_exists($canned, 'getTitle') ? (string) $canned->getTitle() : '';
                $body = self::htmlToPlainText(method_exists($canned, 'getResponse') ? (string) $canned->getResponse() : '');
                $candidates[] = ['title' => $title, 'body' => $body];
            }

            $top = self::rankKeywordMatches($candidates, $keywords, 3, 1200);

            return array_map(
                static fn (array $item): array => [
                    'title' => $item['title'],
                    'response' => $item['body'],
                ],
                $top
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array{title: string, body: string}> $items
     * @param string[]                                       $keywords
     *
     * @return array<int, array{title: string, body: string}>
     */
    private static function rankKeywordMatches(array $items, array $keywords, int $limit, int $maxBodyChars): array
    {
        $scored = [];
        foreach ($items as $item) {
            $title = trim($item['title']);
            $body = trim($item['body']);
            if ($title === '' && $body === '') {
                continue;
            }
            $haystack = strtolower($title . ' ' . $body);
            $titleLower = strtolower($title);
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $score += str_contains($titleLower, $kw) ? 3 : 1;
                }
            }
            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'title' => $title,
                    'body' => self::truncateText($body, $maxBodyChars),
                ];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            static fn (array $item): array => [
                'title' => $item['title'],
                'body' => $item['body'],
            ],
            array_slice($scored, 0, $limit)
        );
    }

    /**
     * @return string[]
     */
    private static function extractKeywords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', strtolower($text)) ?: [];
        $stop = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'your',
            'not', 'are', 'was', 'were', 'can', 'you', 'our', 'please', 'hello',
            'thanks', 'thank', 'issue', 'problem', 'ticket', 'help',
        ];
        $stopSet = array_flip($stop);
        $keywords = [];
        foreach ($words as $word) {
            if (strlen($word) < 4 || isset($stopSet[$word])) {
                continue;
            }
            $keywords[$word] = true;
            if (count($keywords) >= 12) {
                break;
            }
        }

        return array_keys($keywords);
    }

    private static function extractEntryText(object $entry): string
    {
        if (!method_exists($entry, 'getBody')) {
            return '';
        }
        $bodyObj = $entry->getBody();
        if (is_object($bodyObj)) {
            if (method_exists($bodyObj, 'getClean')) {
                $raw = (string) $bodyObj->getClean();
            } elseif (method_exists($bodyObj, 'display')) {
                $raw = (string) $bodyObj->display('text');
            } else {
                $raw = (string) $bodyObj;
            }
        } else {
            $raw = (string) $bodyObj;
        }

        return self::htmlToPlainText($raw);
    }

    private static function htmlToPlainText(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (class_exists('Format') && method_exists('Format', 'html2text')) {
            $converted = (string) Format::html2text($raw, 0, false);

            return trim($converted);
        }

        $withBreaks = (string) preg_replace('#<\s*(?:br|/p|/div|/li|/tr)\s*/?>#i', "\n", $raw);
        $plain = strip_tags($withBreaks);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $plain));
    }

    private static function truncateText(string $text, int $limit): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return function_exists('mb_substr')
            ? mb_substr($text, 0, $limit, 'UTF-8')
            : substr($text, 0, $limit);
    }

    private static function stringifyMixed(mixed $value): string
    {
        if (is_array($value)) {
            $parts = array_filter(array_map([self::class, 'stringifyMixed'], $value));

            return implode(', ', $parts);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return self::stringifyObject($value);
    }

    private static function stringifyObject(mixed $obj): string
    {
        if ($obj === null) {
            return '';
        }
        if (is_string($obj)) {
            return trim($obj);
        }
        if (is_object($obj)) {
            if (method_exists($obj, 'getName')) {
                return trim((string) $obj->getName());
            }
            if (method_exists($obj, '__toString')) {
                return trim((string) $obj);
            }
        }

        return '';
    }
}
