<?php

namespace App\Access;

/**
 * Live permission state. In the application this is backed by the existing
 * Eloquent models on the primary database; in the tests it is a hand-written
 * in-memory universe. Implementations must not cache.
 */
interface PermissionSource
{
    public function portal(string $portalId): ?Portal;

    public function membership(string $userId, string $portalId): ?Membership;

    public function document(string $documentId): ?Document;

    /** @return list<Document> */
    public function documentsIn(string $portalId): array;
}
