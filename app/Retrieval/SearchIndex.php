<?php

namespace App\Retrieval;

/**
 * Content only: what documents say, never who may see them.
 *
 * PermittedSearch treats every implementation as untrusted. An index that is
 * stale, or that ignores $allowedDocumentIds entirely, must still be unable
 * to cause a disclosure.
 */
interface SearchIndex
{
    /**
     * @param list<string> $allowedDocumentIds
     * @return list<Hit>
     */
    public function search(string $portalId, string $query, array $allowedDocumentIds, int $limit): array;

    /** The plain-text rendition the brief says exists, keyed by document id. */
    public function text(string $documentId): ?string;
}
