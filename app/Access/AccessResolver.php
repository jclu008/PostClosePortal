<?php

namespace App\Access;

/**
 * Answers the only authorisation question there is: may this user see this
 * document in this portal, right now?
 *
 * In production this class is a thin wrapper around the web application's
 * existing authorisation logic, which the brief states is correct. The chain
 * is written out here so the prototype's tests have something real to bite
 * on, not as a proposal to re-derive it.
 *
 * Every path that does not end in an explicit allow() ends in deny().
 */
class AccessResolver
{
    public function __construct(private readonly PermissionSource $source) {}

    public function decide(string $userId, string $portalId, string $documentId): Decision
    {
        $portal = $this->source->portal($portalId);
        if ($portal === null || $portal->state !== PortalState::Published) {
            return Decision::deny('portal_not_live');
        }

        // P1: live working-group membership.
        $membership = $this->source->membership($userId, $portalId);
        if ($membership === null) {
            return Decision::deny('p1_no_membership');
        }

        // P2: accepted access terms; both agreements where a bespoke one exists.
        if (! $membership->acceptedStandardTerms
            || ($portal->requiresBespokeTerms && ! $membership->acceptedBespokeTerms)) {
            return Decision::deny('p2_terms_not_accepted');
        }

        // The portal id came from the client. A real document in another
        // portal is treated exactly like a document that does not exist.
        $document = $this->source->document($documentId);
        if ($document === null || $document->portalId !== $portalId) {
            return Decision::deny('no_such_document');
        }

        // I5: administrator bypass of P3-P6 is a deliberate branch, placed
        // after P1 and P2 (which administrators do not bypass) and flagged
        // on the decision so it reaches the audit record.
        if ($membership->role === Role::Administrator) {
            return Decision::allow($membership->role, null, canDownload: true, adminBypass: true);
        }

        // P6: staged documents are visible only to administrators.
        if (! $document->published) {
            return Decision::deny('p6_unpublished');
        }

        // P3 + P4: a Public-side membership never reaches a Private document.
        if ($membership->infoType === InfoType::PublicSide && $document->infoType === InfoType::PrivateSide) {
            return Decision::deny('p3_p4_information_type');
        }

        // P5: explicit per-company grant. No row and No access are the same
        // outcome. A new Grant case added later makes this match throw until
        // someone decides what it means, and callers treat a throw as no data.
        $grant = $document->grants[$membership->companyId] ?? null;

        return match ($grant) {
            Grant::ReadOnly => Decision::allow($membership->role, $grant, canDownload: false),
            Grant::ReadAndDownload => Decision::allow($membership->role, $grant, canDownload: true),
            Grant::NoAccess, null => Decision::deny('p5_no_grant'),
        };
    }

    /**
     * The first check: which documents in this portal may be searched at all.
     * A plain loop is enough at portal scale (median 150, p99 about 1,000).
     *
     * @return list<string>
     */
    public function visibleDocumentIds(string $userId, string $portalId): array
    {
        $ids = [];
        foreach ($this->source->documentsIn($portalId) as $document) {
            if ($this->decide($userId, $portalId, $document->id)->allowed) {
                $ids[] = $document->id;
            }
        }

        return $ids;
    }
}
