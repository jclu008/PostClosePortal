<?php

namespace App\Access;

/**
 * The outcome of one (user, portal, document) question.
 *
 * $reason is for logs and audit only. It must never be returned to the
 * client: telling a user *why* a document is hidden tells them it exists (I1).
 */
final readonly class Decision
{
    private function __construct(
        public bool $allowed,
        public bool $canDownload,
        public bool $adminBypass,
        public string $reason,
        public ?Role $role = null,
        public ?Grant $grant = null,
    ) {}

    public static function deny(string $reason): self
    {
        return new self(false, false, false, $reason);
    }

    public static function allow(Role $role, ?Grant $grant, bool $canDownload, bool $adminBypass = false): self
    {
        return new self(true, $canDownload, $adminBypass, $adminBypass ? 'admin_bypass' : 'granted', $role, $grant);
    }
}
