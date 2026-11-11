<?php

namespace App\Access;

final readonly class Document
{
    /** @param array<string, Grant> $grants keyed by company id */
    public function __construct(
        public string $id,
        public string $portalId,
        public string $title,
        public InfoType $infoType,
        public bool $published,
        public array $grants = [],
    ) {}
}
