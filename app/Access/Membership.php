<?php

namespace App\Access;

/**
 * One user's standing in one portal. There is deliberately no user-level
 * role or clearance anywhere in this prototype (I2): everything a user may
 * do hangs off a membership, and a membership belongs to exactly one portal.
 */
final readonly class Membership
{
    public function __construct(
        public string $userId,
        public string $portalId,
        public string $companyId,
        public Role $role,
        public InfoType $infoType,
        public bool $acceptedStandardTerms = true,
        public bool $acceptedBespokeTerms = false,
    ) {}
}
