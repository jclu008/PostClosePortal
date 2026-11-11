<?php

namespace App\Retrieval;

final readonly class SearchResult
{
    /** @param list<Hit> $hits */
    private function __construct(
        public bool $available,
        public array $hits,
    ) {}

    /** @param list<Hit> $hits */
    public static function of(array $hits): self
    {
        return new self(true, $hits);
    }

    /** A check could not complete. No data, and no detail about why. */
    public static function unavailable(): self
    {
        return new self(false, []);
    }
}
