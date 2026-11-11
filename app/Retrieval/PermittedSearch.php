<?php

namespace App\Retrieval;

use App\Access\AccessResolver;
use Throwable;

/**
 * The filter between the search index and the outside world. This is what an
 * MCP tool handler would call; the handler itself, token validation, excerpt
 * limits and response-time padding are not built here.
 *
 * $userId is always taken from a validated token by the caller. $portalId and
 * $documentId come from the client and are requests, not claims.
 */
final class PermittedSearch
{
    /** Stands in for a metric. Non-zero means the first check or the cache was stale. */
    public int $secondCheckRejections = 0;

    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly SearchIndex $index,
        private readonly AuditLog $audit,
        private readonly CandidateCache $cache,
    ) {}

    public function search(string $userId, string $portalId, string $query, int $limit = 10): SearchResult
    {
        try {
            // First check: decide what may be searched, so hidden documents
            // never take part in ranking, counts or timing. May be cached.
            $candidates = $this->cache->get($userId, $portalId);
            if ($candidates === null) {
                $candidates = $this->resolver->visibleDocumentIds($userId, $portalId);
                $this->cache->put($userId, $portalId, $candidates);
            }

            $released = [];
            foreach ($this->index->search($portalId, $query, $candidates, $limit) as $hit) {
                // Second check: the decision of record, made against live
                // state for every hit, whatever the cache or the index said.
                $decision = $this->resolver->decide($userId, $portalId, $hit->documentId);
                if (! $decision->allowed) {
                    $this->secondCheckRejections++;

                    continue;
                }
                $released[] = [$hit, $decision];
            }

            // Hand every view to audit before anything is returned. If the
            // hand-off throws, the catch below releases nothing.
            foreach ($released as [$hit, $decision]) {
                $this->audit->recordView($userId, $portalId, $hit->documentId, $decision);
            }

            return SearchResult::of(array_column($released, 0));
        } catch (Throwable) {
            // I6: an error, a timeout or an unrecognised state is no data.
            return SearchResult::unavailable();
        }
    }

    /**
     * Returns the document text, or null. Null is the single answer for a
     * document that does not exist, sits in another portal, or is hidden
     * from this user (I1), and also for any failure (I6).
     */
    public function read(string $userId, string $portalId, string $documentId): ?string
    {
        try {
            $decision = $this->resolver->decide($userId, $portalId, $documentId);
            if (! $decision->allowed) {
                return null;
            }

            $text = $this->index->text($documentId);
            if ($text === null) {
                return null;
            }

            $this->audit->recordView($userId, $portalId, $documentId, $decision);

            return $text;
        } catch (Throwable) {
            return null;
        }
    }
}
