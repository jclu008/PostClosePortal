<?php

namespace Tests\Fixtures;

use App\Access\Document;
use App\Access\Grant;
use App\Access\InfoType;
use App\Access\Membership;
use App\Access\PermissionSource;
use App\Access\Portal;
use App\Access\PortalState;
use App\Access\Role;
use RuntimeException;

/**
 * A hand-written world, small enough to hold in your head.
 *
 * Every document carries a unique marker in its title and another in its
 * body. A leak is therefore a string we can search for.
 *
 * VISIBLE is the independent judge: who may see what, written by hand from
 * the brief, sharing no code with AccessResolver.
 */
final class Universe implements PermissionSource
{
    /** user => portal => visible document ids. Anything not listed is forbidden. */
    public const VISIBLE = [
        'admin' => ['atlas' => ['a-public-read', 'a-private-download', 'a-no-access', 'a-staged', 'a-lender-b-only'], 'borealis' => ['b-public']],
        'admin-no-terms' => [],
        'lender-a-private' => ['atlas' => ['a-public-read', 'a-private-download'], 'borealis' => ['b-public']],
        'lender-a-public' => ['atlas' => ['a-public-read']],
        'lender-b-private' => ['atlas' => ['a-public-read', 'a-no-access', 'a-lender-b-only']],
        'counsel' => [],
        'former-member' => [],
    ];

    /** @var array<string, Portal> */
    public array $portals = [];

    /** @var array<string, Membership> keyed "user|portal" */
    public array $memberships = [];

    /** @var array<string, Document> */
    public array $documents = [];

    /** @var array<string, string> document id => plain-text rendition */
    public array $bodies = [];

    /** When true every lookup throws, simulating the database being down. */
    public bool $down = false;

    public static function standard(): self
    {
        $u = new self;

        $u->portals = [
            'atlas' => new Portal('atlas', PortalState::Published),
            'borealis' => new Portal('borealis', PortalState::Published, requiresBespokeTerms: true),
            'cobalt' => new Portal('cobalt', PortalState::Suspended),
        ];

        $public = InfoType::PublicSide;
        $private = InfoType::PrivateSide;

        // Atlas: the agent bank administers; two competing lenders.
        $u->join('admin', 'atlas', 'agent', Role::Administrator, $private);
        $u->join('admin-no-terms', 'atlas', 'agent', Role::Administrator, $private, standard: false);
        $u->join('lender-a-private', 'atlas', 'lender-a', Role::Manager, $private);
        $u->join('lender-a-public', 'atlas', 'lender-a', Role::Manager, $public);
        $u->join('lender-b-private', 'atlas', 'lender-b', Role::Viewer, $private);

        // Borealis needs both agreements. The Atlas administrator is an
        // ordinary Public-side viewer here, and lender-a-private is Public.
        $u->join('admin', 'borealis', 'agent', Role::Viewer, $public, bespoke: true);
        $u->join('lender-a-private', 'borealis', 'lender-a', Role::Manager, $public, bespoke: true);
        $u->join('counsel', 'borealis', 'counsel', Role::Viewer, $private, bespoke: false);

        // Cobalt is suspended: a valid membership that must yield nothing.
        $u->join('lender-a-private', 'cobalt', 'lender-a', Role::Manager, $private);

        $read = Grant::ReadOnly;
        $u->file('a-public-read', 'atlas', $public, true, ['lender-a' => $read, 'lender-b' => $read]);
        $u->file('a-private-download', 'atlas', $private, true, ['lender-a' => Grant::ReadAndDownload]);
        $u->file('a-no-access', 'atlas', $public, true, ['lender-a' => Grant::NoAccess, 'lender-b' => $read]);
        $u->file('a-staged', 'atlas', $public, false, ['lender-a' => $read, 'lender-b' => $read]);
        $u->file('a-lender-b-only', 'atlas', $private, true, ['lender-b' => $read]);
        $u->file('b-public', 'borealis', $public, true, ['agent' => $read, 'lender-a' => $read, 'counsel' => $read]);
        $u->file('b-private', 'borealis', $private, true, ['agent' => $read, 'lender-a' => $read, 'counsel' => $read]);
        $u->file('c-public', 'cobalt', $public, true, ['lender-a' => $read]);

        return $u;
    }

    public function join(string $user, string $portal, string $company, Role $role, InfoType $type, bool $standard = true, bool $bespoke = false): void
    {
        $this->memberships["$user|$portal"] = new Membership($user, $portal, $company, $role, $type, $standard, $bespoke);
    }

    /** @param array<string, Grant> $grants */
    public function file(string $id, string $portal, InfoType $type, bool $published, array $grants): void
    {
        $this->documents[$id] = new Document($id, $portal, 'Leverage covenant '.self::titleMarker($id), $type, $published, $grants);
        $this->bodies[$id] = 'The leverage covenant steps down. '.self::bodyMarker($id);
    }

    public static function titleMarker(string $id): string
    {
        return 'ZQ-TITLE-'.$id;
    }

    public static function bodyMarker(string $id): string
    {
        return 'ZQ-BODY-'.$id;
    }

    /** Markers of every document the judge says this user must not see in this portal. */
    public function forbiddenMarkers(string $user, string $portal): array
    {
        $markers = [];
        foreach (array_diff(array_keys($this->documents), self::VISIBLE[$user][$portal] ?? []) as $id) {
            array_push($markers, self::titleMarker($id), self::bodyMarker($id));
        }

        return $markers;
    }

    public function portal(string $portalId): ?Portal
    {
        $this->failIfDown();

        return $this->portals[$portalId] ?? null;
    }

    public function membership(string $userId, string $portalId): ?Membership
    {
        $this->failIfDown();

        return $this->memberships["$userId|$portalId"] ?? null;
    }

    public function document(string $documentId): ?Document
    {
        $this->failIfDown();

        return $this->documents[$documentId] ?? null;
    }

    public function documentsIn(string $portalId): array
    {
        $this->failIfDown();

        return array_values(array_filter($this->documents, fn (Document $d) => $d->portalId === $portalId));
    }

    private function failIfDown(): void
    {
        if ($this->down) {
            throw new RuntimeException('permission source unavailable');
        }
    }
}
