<?php

namespace Tests\Unit;

use App\Access\AccessResolver;
use App\Access\Grant;
use App\Retrieval\PermittedSearch;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\AllowEverythingResolver;
use Tests\Fixtures\ArrayCache;
use Tests\Fixtures\FakeIndex;
use Tests\Fixtures\RecordingAudit;
use Tests\Fixtures\Universe;

final class PermittedSearchTest extends TestCase
{
    private const QUERY = 'covenant'; // matches every document in the universe

    private Universe $universe;

    private RecordingAudit $audit;

    protected function setUp(): void
    {
        $this->universe = Universe::standard();
        $this->audit = new RecordingAudit;
    }

    private function search(bool $honestIndex = true, ?AccessResolver $resolver = null): PermittedSearch
    {
        return new PermittedSearch(
            $resolver ?? new AccessResolver($this->universe),
            new FakeIndex($this->universe, $honestIndex),
            $this->audit,
            new ArrayCache,
        );
    }

    /** Markers of forbidden documents found anywhere in what was returned. */
    private function leaks(string $user, string $portal, mixed $returned): array
    {
        $bytes = json_encode($returned, JSON_THROW_ON_ERROR);

        return array_values(array_filter(
            $this->universe->forbiddenMarkers($user, $portal),
            fn (string $marker) => str_contains($bytes, $marker),
        ));
    }

    /**
     * THE REFUSAL TEST.
     *
     * A Public-side lender asks about a covenant. The index is as bad as an
     * index can be: it ignores the portal and the allow-list and offers every
     * matching document in the system, including a Private document, a
     * competitor's document, a staged amendment and another deal's files.
     * Exactly one document may come back.
     */
    public function test_unauthorised_retrieval_is_refused(): void
    {
        $search = $this->search(honestIndex: false);

        $result = $search->search('lender-a-public', 'atlas', self::QUERY);

        $this->assertSame(['a-public-read'], array_column($result->hits, 'documentId'));
        $this->assertSame(
            [],
            $this->leaks('lender-a-public', 'atlas', $result),
            'SAFETY FAILURE: a forbidden document reached a user. Do not ship. Do not retry this test to green.',
        );
        $this->assertSame(7, $search->secondCheckRejections, 'the second check must be what refused the other seven');

        // Asking for the Private document by id is refused too, and leaves no audit trace of a view.
        $this->assertNull($search->read('lender-a-public', 'atlas', 'a-private-download'));
        $this->assertSame(['a-public-read'], array_column($this->audit->views, 'document'));
    }

    /** If the resolver ever allowed everything, the detector above would see it. A detector that cannot fail proves nothing. */
    public function test_the_refusal_test_can_fail(): void
    {
        $broken = $this->search(honestIndex: false, resolver: new AllowEverythingResolver($this->universe));

        $result = $broken->search('lender-a-public', 'atlas', self::QUERY);

        $this->assertNotEmpty($this->leaks('lender-a-public', 'atlas', $result));
    }

    /** Every user, every portal, the leaky index, search plus a direct read of every document id. */
    public function test_no_user_receives_a_forbidden_marker_by_any_route(): void
    {
        foreach (array_keys(Universe::VISIBLE) as $user) {
            foreach (array_keys($this->universe->portals) as $portal) {
                $search = $this->search(honestIndex: false);
                $returned = [$search->search($user, $portal, self::QUERY)];
                foreach (array_keys($this->universe->documents) as $document) {
                    $returned[] = $search->read($user, $portal, $document);
                }

                $this->assertSame([], $this->leaks($user, $portal, $returned), "SAFETY FAILURE for $user in $portal");
            }
        }
    }

    /** I1: hidden, foreign and non-existent documents get the same answer. */
    public function test_hidden_foreign_and_missing_documents_are_indistinguishable(): void
    {
        $search = $this->search();

        $hidden = $search->read('lender-a-private', 'atlas', 'a-lender-b-only');
        $foreign = $search->read('lender-a-private', 'atlas', 'b-public'); // visible to them, but in Borealis
        $missing = $search->read('lender-a-private', 'atlas', 'does-not-exist');

        $this->assertNull($hidden);
        $this->assertSame(var_export($hidden, true), var_export($foreign, true));
        $this->assertSame(var_export($hidden, true), var_export($missing, true));
        $this->assertSame([], $this->audit->views);
    }

    /** I1: what a user receives must not change when documents they cannot see are added or removed. */
    public function test_results_are_identical_with_and_without_hidden_documents(): void
    {
        $with = $this->search()->search('lender-a-public', 'atlas', self::QUERY);

        foreach (array_keys($this->universe->documents) as $id) {
            if ($id !== 'a-public-read') {
                unset($this->universe->documents[$id], $this->universe->bodies[$id]);
            }
        }
        $without = $this->search()->search('lender-a-public', 'atlas', self::QUERY);

        $this->assertSame(json_encode($without), json_encode($with));
    }

    /** I4: the cache still lists the documents; the live second check refuses them. */
    public function test_revocation_takes_effect_inside_the_cache_window(): void
    {
        $search = $this->search();
        $this->assertCount(2, $search->search('lender-a-private', 'atlas', self::QUERY)->hits);

        unset($this->universe->memberships['lender-a-private|atlas']); // removed from the deal team

        $after = $search->search('lender-a-private', 'atlas', self::QUERY);

        $this->assertSame([], $after->hits);
        $this->assertSame(2, $search->secondCheckRejections, 'the stale cache offered two documents; both must be refused live');
        $this->assertNull($search->read('lender-a-private', 'atlas', 'a-public-read'));
    }

    /** I6: no permission answer means no data, not unfiltered data. */
    public function test_fails_closed_when_the_permission_source_is_down(): void
    {
        $search = $this->search(honestIndex: false);
        $this->universe->down = true;

        $result = $search->search('lender-a-private', 'atlas', self::QUERY);

        $this->assertFalse($result->available);
        $this->assertSame([], $result->hits);
        $this->assertNull($search->read('lender-a-private', 'atlas', 'a-public-read'));
    }

    /** No acknowledged audit hand-off, no answer. */
    public function test_fails_closed_when_the_audit_hand_off_is_refused(): void
    {
        $this->audit->refusing = true;
        $search = $this->search();

        $this->assertSame([], $search->search('lender-a-private', 'atlas', self::QUERY)->hits);
        $this->assertNull($search->read('lender-a-private', 'atlas', 'a-public-read'));
    }

    /** Every released document is recorded with the access level in force, and bypass is visible (I5). */
    public function test_every_released_view_is_audited_with_a_snapshot(): void
    {
        $result = $this->search()->search('lender-a-private', 'atlas', self::QUERY);

        $this->assertSame(array_column($result->hits, 'documentId'), array_column($this->audit->views, 'document'));
        $this->assertSame(Grant::ReadAndDownload, $this->audit->views[1]['decision']->grant);
        $this->assertFalse($this->audit->views[1]['decision']->adminBypass);

        $this->audit->views = [];
        $this->search()->search('admin', 'atlas', self::QUERY);

        $this->assertCount(5, $this->audit->views);
        foreach ($this->audit->views as $view) {
            $this->assertTrue($view['decision']->adminBypass);
        }
    }
}
