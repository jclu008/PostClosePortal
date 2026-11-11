<?php

namespace App\Retrieval;

/**
 * Short-lived cache of the first check (Redis, 60 seconds, in the design).
 * It may narrow what is searched. It is never grounds to release anything.
 */
interface CandidateCache
{
    /** @return list<string>|null */
    public function get(string $userId, string $portalId): ?array;

    /** @param list<string> $documentIds */
    public function put(string $userId, string $portalId, array $documentIds): void;
}
