<?php

declare(strict_types=1);

namespace App;

/**
 * Immutable "about me" page value object — single markdown file under
 * content/about.md, mapped to typed fields. Distinct from Post so it never
 * leaks into feeds, listings, llms.txt, or the post index.
 */
final class AboutPage
{
    /**
     * @param list<array{label:string,value:string,url:?string}> $contacts
     * @param list<string> $stack    Tech / tools the operator works with
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $callsign,
        public readonly ?string $location,
        public readonly ?string $status,
        public readonly ?string $avatar,
        public readonly array $contacts,
        public readonly array $stack,
        public readonly ?string $currently,
        public readonly string $bodyMarkdown,
    ) {
    }

    /**
     * Short hex device-id derived from the name — purely decorative, lets
     * the HUD show a stable "0X…" tag without forcing the admin to type one.
     */
    public function deviceId(): string
    {
        return '0X' . strtoupper(substr(md5($this->name), 0, 8));
    }
}
