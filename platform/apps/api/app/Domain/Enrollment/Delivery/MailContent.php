<?php

namespace App\Domain\Enrollment\Delivery;

/**
 * Everything one email says, already worded; the HTML and text templates only lay it out. Parent emails
 * carry the organization's colour and logo; team emails the platform's (docs/product/enrollment-contacts-and-emails.md, т. 6).
 */
final readonly class MailContent
{
    /**
     * @param  list<string>  $paragraphs
     * @param  list<array{label: string, value: string, hint?: string|null}>  $facts
     * @param  array{title: string, text: string}|null  $callout
     * @param  array{label: string, text: string}|null  $quote
     * @param  array{label: string, url: string}|null  $button
     * @param  list<string>  $after
     */
    public function __construct(
        public bool $team,
        public string $fromName,
        public ?string $replyTo,
        public string $subject,
        public Branding $brand,
        public ?string $greeting,
        public string $heading,
        public array $paragraphs,
        public array $facts,
        public ?array $callout,
        public ?array $quote,
        public ?array $button,
        public array $after,
        public string $footer,
    ) {}
}
