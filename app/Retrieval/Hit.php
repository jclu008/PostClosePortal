<?php

namespace App\Retrieval;

final readonly class Hit
{
    public function __construct(
        public string $documentId,
        public string $title,
        public string $passage,
    ) {}
}
