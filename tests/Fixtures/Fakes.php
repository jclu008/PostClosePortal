<?php

namespace Tests\Fixtures;

use App\Access\AccessResolver;
use App\Access\Decision;
use App\Access\Role;
use App\Retrieval\AuditLog;
use App\Retrieval\CandidateCache;
use App\Retrieval\Hit;
use App\Retrieval\SearchIndex;
use RuntimeException;

/**
 * Substring search over the fixture bodies. With $honoursFilter false it is
 * the worst index imaginable: it ignores the portal and the allow-list and
 * returns every matching document in the system.
 */
final class FakeIndex implements SearchIndex
{
    public function __construct(private readonly Universe $universe, private readonly bool $honoursFilter = true) {}

    public function search(string $portalId, string $query, array $allowedDocumentIds, int $limit): array
    {
        $hits = [];
        foreach ($this->universe->documents as $id => $document) {
            $permitted = $document->portalId === $portalId && in_array($id, $allowedDocumentIds, true);
            if ($this->honoursFilter && ! $permitted) {
                continue;
            }
            if (stripos($this->universe->bodies[$id], $query) !== false) {
                $hits[] = new Hit($id, $document->title, $this->universe->bodies[$id]);
            }
        }

        return array_slice($hits, 0, $limit);
    }

    public function text(string $documentId): ?string
    {
        return $this->universe->bodies[$documentId] ?? null;
    }
}

final class ArrayCache implements CandidateCache
{
    private array $entries = [];

    public function get(string $userId, string $portalId): ?array
    {
        return $this->entries["$userId|$portalId"] ?? null;
    }

    public function put(string $userId, string $portalId, array $documentIds): void
    {
        $this->entries["$userId|$portalId"] = $documentIds;
    }
}

final class RecordingAudit implements AuditLog
{
    /** @var list<array{user: string, portal: string, document: string, decision: Decision}> */
    public array $views = [];

    public bool $refusing = false;

    public function recordView(string $userId, string $portalId, string $documentId, Decision $decision): void
    {
        if ($this->refusing) {
            throw new RuntimeException('audit hand-off not acknowledged');
        }
        $this->views[] = ['user' => $userId, 'portal' => $portalId, 'document' => $documentId, 'decision' => $decision];
    }
}

/** A deliberately broken resolver, used only to prove the leak detector can fail. */
final class AllowEverythingResolver extends AccessResolver
{
    public function decide(string $userId, string $portalId, string $documentId): Decision
    {
        return Decision::allow(Role::Viewer, null, canDownload: true);
    }
}
