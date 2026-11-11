<?php

namespace App\Retrieval;

use App\Access\Decision;

/**
 * Hand-off of a view record, carrying the role and access level in force at
 * the moment of access. Implementations must throw if the hand-off is not
 * acknowledged; the caller then releases nothing.
 */
interface AuditLog
{
    public function recordView(string $userId, string $portalId, string $documentId, Decision $decision): void;
}
