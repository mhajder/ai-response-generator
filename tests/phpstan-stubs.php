<?php

/**
 * Minimal osTicket core stubs for PHPStan static analysis only.
 */

declare(strict_types=1);

define('INCLUDE_DIR', '/tmp/osticket/include/');
define('ROOT_PATH', '/');
define('GIT_VERSION', 'v1.18.4');
define('SECRET_SALT', 'test-salt');

function __(string $text): string
{
    return $text;
}

function url_post(string $pattern, callable|array $callback): array
{
    return [$pattern, $callback];
}

function url_get(string $pattern, callable|array $callback): array
{
    return [$pattern, $callback];
}

class Format
{
    public static function htmlchars(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public static function html2text(string $html, int $width = 0, bool $preserve = false): string
    {
        return strip_tags($html);
    }
}

class Signal
{
    /** @param callable|string|null $object */
    public static function connect(string $signal, callable $callable, mixed $object = null, mixed $check = null): void
    {
    }
}

class Http
{
    /** @var array{code: int, content: string, contentType: string|bool}|null */
    public static ?array $lastResponse = null;

    public static function response(int $code, string $content = '', string|bool $contentType = false): void
    {
        self::$lastResponse = [
            'code' => $code,
            'content' => $content,
            'contentType' => $contentType,
        ];
    }
}

class PluginConfig
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function getInstance(): ?PluginInstance
    {
        return null;
    }

    public function getNamespace(): string
    {
        return 'plugin.1';
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $errors
     */
    public function pre_save(&$config, &$errors): bool
    {
        unset($config, $errors);

        return true;
    }
}

class PluginInstance
{
    public function getId(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'Default';
    }
}

class Plugin
{
    public function getConfig(?PluginInstance $instance = null): ?PluginConfig
    {
        return null;
    }
}

class Crypto
{
    public static function encrypt(string $data, string $key, string $namespace = ''): string|false
    {
        unset($key, $namespace);

        return $data;
    }

    public static function decrypt(string $data, string $key, string $namespace = ''): string|false
    {
        unset($key, $namespace);

        return $data;
    }
}

class FormField
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        unset($options);
    }
}

class TextboxField extends FormField {}
class TextareaField extends FormField {}
class ChoiceField extends FormField {}
class BooleanField extends FormField {}
class SectionBreakField extends FormField {}

class ApiController
{
    /** @param mixed $what */
    public function encode($what): string
    {
        return (string) json_encode($what);
    }
}

class AjaxController extends ApiController
{
    public function staffOnly(): void
    {
    }
}

class Ticket
{
    public const PERM_REPLY = 'ticket.reply';

    public static ?self $stubTicket = null;

    public static function lookup(int $id): ?self
    {
        if (self::$stubTicket !== null && self::$stubTicket->getId() === $id) {
            return self::$stubTicket;
        }

        return null;
    }

    public function getId(): int
    {
        return 1;
    }

    public function getNumber(): string
    {
        return '100001';
    }

    public function getSubject(): string
    {
        return '';
    }

    public ?Role $stubRole = null;
    public int $stubDeptId = 1;

    public function getDeptId(): int
    {
        return $this->stubDeptId;
    }

    public function getDept(): ?object
    {
        return null;
    }

    public function getHelpTopic(): ?object
    {
        return null;
    }

    public function getPriority(): ?object
    {
        return null;
    }

    public function getStatus(): ?object
    {
        return null;
    }

    public function getOwner(): ?object
    {
        return null;
    }

    public function getThread(): ?object
    {
        return null;
    }

    public function getRole(?object $staff): ?Role
    {
        unset($staff);

        return $this->stubRole;
    }

    public function checkStaffPerm(?object $staff, ?string $perm = null): bool
    {
        unset($staff, $perm);

        return true;
    }
}

class Role
{
    public bool $allowReply = true;

    public function hasPerm(string $perm): bool
    {
        unset($perm);

        return $this->allowReply;
    }
}

class FAQ
{
    public static function objects(): FAQQuerySet
    {
        return new FAQQuerySet();
    }
}

/** @implements IteratorAggregate<int, object> */
class FAQQuerySet implements IteratorAggregate
{
    /** @param array<string, mixed> $criteria */
    public function filter(array $criteria): self
    {
        unset($criteria);

        return $this;
    }

    public function limit(int $limit): self
    {
        unset($limit);

        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }
}

class Canned
{
    public static function objects(): FAQQuerySet
    {
        return new FAQQuerySet();
    }
}

class DynamicFormEntry
{
    /** @return array<int, object> */
    public static function forTicket(int $ticketId): array
    {
        unset($ticketId);

        return [];
    }
}
