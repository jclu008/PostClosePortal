<?php

namespace App\Access;

final readonly class Portal
{
    public function __construct(
        public string $id,
        public PortalState $state,
        public bool $requiresBespokeTerms = false,
    ) {}
}
