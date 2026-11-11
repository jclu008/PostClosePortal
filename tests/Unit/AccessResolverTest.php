<?php

namespace Tests\Unit;

use App\Access\AccessResolver;
use App\Access\Grant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Universe;

final class AccessResolverTest extends TestCase
{
    /**
     * Every user, against every document, asked through every portal id,
     * compared with the hand-written judge in Universe::VISIBLE.
     */
    public function test_every_triple_matches_the_hand_written_judge(): void
    {
        $universe = Universe::standard();
        $resolver = new AccessResolver($universe);
        $checked = 0;

        foreach (array_keys(Universe::VISIBLE) as $user) {
            foreach (array_keys($universe->portals) as $portal) {
                foreach (array_keys($universe->documents) as $document) {
                    $expected = in_array($document, Universe::VISIBLE[$user][$portal] ?? [], true);

                    $this->assertSame(
                        $expected,
                        $resolver->decide($user, $portal, $document)->allowed,
                        "$user asking for $document through $portal",
                    );
                    $checked++;
                }
            }
        }

        $this->assertSame(7 * 3 * 8, $checked);
    }

    /** Each condition, failing alone, is what denies. Reasons are internal and never leave the server. */
    #[DataProvider('singleFailures')]
    public function test_each_condition_denies_for_its_own_reason(string $user, string $portal, string $document, string $reason): void
    {
        $decision = (new AccessResolver(Universe::standard()))->decide($user, $portal, $document);

        $this->assertFalse($decision->allowed);
        $this->assertSame($reason, $decision->reason);
    }

    public static function singleFailures(): array
    {
        return [
            'portal suspended' => ['lender-a-private', 'cobalt', 'c-public', 'portal_not_live'],
            'portal unknown' => ['lender-a-private', 'nowhere', 'a-public-read', 'portal_not_live'],
            'P1 never a member' => ['former-member', 'atlas', 'a-public-read', 'p1_no_membership'],
            'P2 standard terms' => ['admin-no-terms', 'atlas', 'a-public-read', 'p2_terms_not_accepted'],
            'P2 bespoke terms' => ['counsel', 'borealis', 'b-public', 'p2_terms_not_accepted'],
            'P3/P4 public user, private doc' => ['lender-a-public', 'atlas', 'a-private-download', 'p3_p4_information_type'],
            'P5 explicit no access' => ['lender-a-private', 'atlas', 'a-no-access', 'p5_no_grant'],
            'P5 no grant row' => ['lender-a-private', 'atlas', 'a-lender-b-only', 'p5_no_grant'],
            'P6 staged' => ['lender-a-private', 'atlas', 'a-staged', 'p6_unpublished'],
            'document from another portal' => ['lender-a-private', 'atlas', 'b-public', 'no_such_document'],
            'document does not exist' => ['lender-a-private', 'atlas', 'nope', 'no_such_document'],
        ];
    }

    public function test_read_and_download_are_distinct(): void
    {
        $resolver = new AccessResolver(Universe::standard());

        $readOnly = $resolver->decide('lender-a-private', 'atlas', 'a-public-read');
        $download = $resolver->decide('lender-a-private', 'atlas', 'a-private-download');

        $this->assertTrue($readOnly->allowed);
        $this->assertFalse($readOnly->canDownload);
        $this->assertSame(Grant::ReadOnly, $readOnly->grant);
        $this->assertTrue($download->canDownload);
    }

    public function test_administrator_bypass_is_explicit_and_stays_inside_its_portal(): void
    {
        $resolver = new AccessResolver(Universe::standard());

        // In Atlas: sees a staged document with no grant, and the decision says why.
        $staged = $resolver->decide('admin', 'atlas', 'a-staged');
        $this->assertTrue($staged->allowed);
        $this->assertTrue($staged->adminBypass);

        // An ordinary allow is never flagged as a bypass.
        $this->assertFalse($resolver->decide('lender-a-private', 'atlas', 'a-public-read')->adminBypass);

        // In Borealis the same person is a Public-side viewer: no bypass, no Private documents.
        $this->assertFalse($resolver->decide('admin', 'borealis', 'b-public')->adminBypass);
        $this->assertFalse($resolver->decide('admin', 'borealis', 'b-private')->allowed);
    }
}
