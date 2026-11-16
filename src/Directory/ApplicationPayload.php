<?php

namespace Consys\Ecosystem\Directory;

/**
 * An application of the catalog. The whole download lists only the active ones, without the
 * is_active and deleted keys, so they default to an available application.
 */
final readonly class ApplicationPayload
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $url,
        public ?string $logoutUrl,
        public bool $isActive,
        public bool $deleted,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new PayloadReader('application', $data);

        return new self(
            slug: $reader->required('slug'),
            name: $reader->required('name'),
            url: $reader->string('url'),
            logoutUrl: $reader->string('logout_url'),
            isActive: $reader->bool('is_active', true),
            deleted: $reader->bool('deleted'),
            raw: $data,
        );
    }

    public function isAvailable(): bool
    {
        return $this->isActive && ! $this->deleted;
    }
}
