<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_branch_map;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\parent_plugin_required_helpers;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\plan_branch_retag;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\run_branch_retag;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\with_retries;

class RetagTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    /**
     * A cut-down branch map. Real runs pass get_branch_map().
     */
    private function branchMap(): array
    {
        return [
            'M1' => 'City Centre and Salford',
            'M27' => 'City Centre and Salford',
            'M20' => 'South Manchester',
            'M5' => null,
            'SK1' => 'Stockport',
        ];
    }

    private function person(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'email' => 'member@example.com',
            'zip_code' => 'M1 1AA',
            'tags' => [],
        ], $overrides);
    }

    private function planOne(array $person): array
    {
        $actions = plan_branch_retag([$person], $this->branchMap());
        $this->assertCount(1, $actions);
        return $actions[0];
    }

    // The job this exists for: members on the old branch move to the new one.

    public function test_member_on_the_wrong_branch_is_moved()
    {
        $action = $this->planOne($this->person(['tags' => ['South Manchester']]));

        $this->assertSame('move', $action['status']);
        $this->assertSame('City Centre and Salford', $action['addTag']);
        $this->assertSame(['South Manchester'], $action['removeTags']);
    }

    public function test_member_with_no_branch_tag_is_given_one()
    {
        $action = $this->planOne($this->person(['zip_code' => 'M27 1AA']));

        $this->assertSame('add', $action['status']);
        $this->assertSame('City Centre and Salford', $action['addTag']);
        $this->assertSame([], $action['removeTags']);
    }

    public function test_member_already_on_the_right_branch_is_left_alone()
    {
        $action = $this->planOne($this->person(['tags' => ['City Centre and Salford']]));

        $this->assertSame('unchanged', $action['status']);
        $this->assertNull($action['addTag']);
        $this->assertSame([], $action['removeTags']);
    }

    public function test_member_on_a_branch_that_did_not_change_is_left_alone()
    {
        $action = $this->planOne($this->person([
            'zip_code' => 'M20 2AA',
            'tags' => ['South Manchester'],
        ]));

        $this->assertSame('unchanged', $action['status']);
        $this->assertNull($action['addTag']);
        $this->assertSame([], $action['removeTags']);
    }

    // Safety: never touch a tag that is not a branch.

    public function test_non_branch_tags_are_never_removed()
    {
        $action = $this->planOne($this->person([
            'tags' => ['South Manchester', 'Unconfirmed', 'Volunteer', 'Gorton Stall'],
        ]));

        $this->assertSame(['South Manchester'], $action['removeTags']);
    }

    public function test_a_member_carrying_two_stale_branch_tags_loses_both()
    {
        $action = $this->planOne($this->person([
            'tags' => ['South Manchester', 'Stockport'],
        ]));

        $this->assertSame('move', $action['status']);
        $this->assertSame('City Centre and Salford', $action['addTag']);
        $this->assertSame(['South Manchester', 'Stockport'], $action['removeTags']);
    }

    public function test_member_carrying_the_right_branch_and_a_stale_one_loses_the_stale_one()
    {
        $action = $this->planOne($this->person([
            'tags' => ['City Centre and Salford', 'South Manchester'],
        ]));

        $this->assertSame('remove', $action['status']);
        $this->assertNull($action['addTag']);
        $this->assertSame(['South Manchester'], $action['removeTags']);
    }

    /**
     * An outcode mapped to null is "in area, no branch yet". Stripping a branch
     * tag with nothing to put in its place would quietly cut a member off from
     * their organisers, so we report it and leave it to GMTU.
     */
    public function test_outcode_with_no_branch_leaves_existing_tags_alone()
    {
        $action = $this->planOne($this->person([
            'zip_code' => 'M5 3AA',
            'tags' => ['South Manchester'],
        ]));

        $this->assertSame('review', $action['status']);
        $this->assertNull($action['addTag']);
        $this->assertSame([], $action['removeTags']);
        $this->assertStringContainsString('no branch', $action['reason']);
    }

    public function test_outcode_with_no_branch_and_no_tags_is_unchanged()
    {
        $action = $this->planOne($this->person(['zip_code' => 'M5 3AA']));

        $this->assertSame('unchanged', $action['status']);
    }

    // Members we cannot classify are reported, never guessed at.

    public function test_unparseable_postcode_is_skipped()
    {
        $action = $this->planOne($this->person(['zip_code' => 'not a postcode']));

        $this->assertSame('skipped', $action['status']);
        $this->assertNull($action['addTag']);
        $this->assertSame([], $action['removeTags']);
    }

    public function test_missing_postcode_is_skipped()
    {
        $action = $this->planOne($this->person(['zip_code' => '']));

        $this->assertSame('skipped', $action['status']);
    }

    public function test_outcode_outside_the_branch_map_is_skipped()
    {
        $action = $this->planOne($this->person([
            'zip_code' => 'SW1A 1AA',
            'tags' => ['South Manchester'],
        ]));

        $this->assertSame('skipped', $action['status']);
        $this->assertSame([], $action['removeTags']);
    }

    public function test_action_reports_what_it_looked_at()
    {
        $action = $this->planOne($this->person(['zip_code' => 'm1 1aa']));

        $this->assertSame(1, $action['id']);
        $this->assertSame('member@example.com', $action['email']);
        $this->assertSame('m1 1aa', $action['postcode']);
        $this->assertSame('M1', $action['outcode']);
    }

    public function test_action_names_the_branch_the_member_should_be_on()
    {
        $moving = $this->planOne($this->person(['tags' => ['South Manchester']]));
        $settled = $this->planOne($this->person(['zip_code' => 'M20 2AA', 'tags' => ['South Manchester']]));

        $this->assertSame('City Centre and Salford', $moving['expectedBranch']);
        $this->assertSame('South Manchester', $settled['expectedBranch']);
    }

    public function test_action_has_no_expected_branch_where_the_answer_is_not_known()
    {
        $noBranch = $this->planOne($this->person(['zip_code' => 'M5 3AA', 'tags' => ['South Manchester']]));
        $unreadable = $this->planOne($this->person(['zip_code' => 'rubbish']));
        $outOfArea = $this->planOne($this->person(['zip_code' => 'SW1A 1AA']));

        $this->assertNull($noBranch['expectedBranch']);
        $this->assertNull($unreadable['expectedBranch']);
        $this->assertNull($outOfArea['expectedBranch']);
    }

    public function test_plans_every_person_given()
    {
        $actions = plan_branch_retag([
            $this->person(['id' => 1, 'tags' => ['South Manchester']]),
            $this->person(['id' => 2, 'zip_code' => 'M20 2AA', 'tags' => ['South Manchester']]),
            $this->person(['id' => 3, 'zip_code' => 'nonsense']),
        ], $this->branchMap());

        $this->assertSame(['move', 'unchanged', 'skipped'], array_column($actions, 'status'));
    }

    // run_branch_retag: fetching, applying, and not applying.

    /**
     * Fake Zetkin transport. Returns the five callables run_branch_retag takes,
     * plus a recorder object so tests can assert on what was written.
     */
    private function fakeZetkin(array $people)
    {
        $calls = new \stdClass();
        $calls->added = [];
        $calls->removed = [];
        $calls->resolved = [];

        $pages = array_chunk($people, 2);
        $lister = function ($page, $perPage) use ($pages) {
            return $pages[$page] ?? [];
        };
        $tagsGetter = function ($personId) use ($people) {
            foreach ($people as $person) {
                if ($person['id'] === $personId) {
                    return array_map(fn($title) => ['id' => crc32($title), 'title' => $title], $person['tags']);
                }
            }
            return [];
        };
        $resolver = function ($title) use ($calls) {
            $calls->resolved[] = $title;
            return ['id' => crc32($title), 'title' => $title];
        };
        $adder = function ($personId, $tagId) use ($calls) {
            $calls->added[] = [$personId, $tagId];
            return 'ok';
        };
        $remover = function ($personId, $tagId) use ($calls) {
            $calls->removed[] = [$personId, $tagId];
            return 'ok';
        };

        return [$lister, $tagsGetter, $resolver, $adder, $remover, $calls];
    }

    public function test_dry_run_writes_nothing()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover, $calls] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);

        $result = run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertFalse($result['applied']);
        $this->assertSame([], $calls->added);
        $this->assertSame([], $calls->removed);
        $this->assertSame(1, $result['counts']['move']);
    }

    public function test_apply_adds_and_removes_tags()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover, $calls] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertTrue($result['applied']);
        $this->assertSame([[1, crc32('City Centre and Salford')]], $calls->added);
        $this->assertSame([[1, crc32('South Manchester')]], $calls->removed);
    }

    public function test_apply_does_not_touch_members_who_are_already_correct()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover, $calls] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M20 2AA', 'tags' => ['South Manchester']],
        ]);

        run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertSame([], $calls->added);
        $this->assertSame([], $calls->removed);
    }

    public function test_pages_through_the_whole_membership()
    {
        $people = [];
        for ($i = 1; $i <= 5; $i++) {
            $people[] = ['id' => $i, 'email' => "m$i@example.com", 'zip_code' => 'M1 1AA', 'tags' => []];
        }
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin($people);

        $result = run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertCount(5, $result['actions']);
    }

    public function test_limit_stops_early()
    {
        $people = [];
        for ($i = 1; $i <= 5; $i++) {
            $people[] = ['id' => $i, 'email' => "m$i@example.com", 'zip_code' => 'M1 1AA', 'tags' => []];
        }
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin($people);

        $result = run_branch_retag(false, 3, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertCount(3, $result['actions']);
    }

    public function test_resolves_each_tag_title_once_however_many_members_need_it()
    {
        $people = [];
        for ($i = 1; $i <= 4; $i++) {
            $people[] = ['id' => $i, 'email' => "m$i@example.com", 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']];
        }
        [$lister, $tagsGetter, $resolver, $adder, $remover, $calls] = $this->fakeZetkin($people);

        run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertSame(
            ['City Centre and Salford', 'South Manchester'],
            array_values(array_unique($calls->resolved))
        );
        $this->assertCount(2, $calls->resolved);
    }

    /**
     * The command depends on bulk Zetkin and Mailchimp helpers that first
     * shipped, under their final names, in parent 1.4.40. Against an older parent it
     * must say so, not fatal halfway through a run. Neither service exists in
     * the test suite, so the default wiring stands in for an out-of-date
     * parent.
     */
    public function test_refuses_to_run_against_a_parent_plugin_without_the_bulk_helpers()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/1\.4\.40 or newer/');

        run_branch_retag();
    }

    /**
     * The guard checks Zetkin first and neither service exists in this suite,
     * so a run always fails on Zetkin and never reaches the Mailchimp names.
     * Assert on the requirement list itself, otherwise dropping a Mailchimp
     * helper from it would go unnoticed until a live run fataled mid-write.
     */
    public function test_mailchimp_helpers_are_required_of_the_parent_plugin()
    {
        $required = parent_plugin_required_helpers();

        $this->assertSame(
            ['isConfigured', 'tryAddTag', 'tryRemoveTag'],
            $required['CommonKnowledge\\JoinBlock\\Services\\MailchimpService']
        );
    }

    public function test_zetkin_helpers_are_required_of_the_parent_plugin()
    {
        $required = parent_plugin_required_helpers();

        $this->assertSame(
            ['listPeople', 'getPersonTags', 'findOrCreateTagByTitle', 'tryAddTagToPerson', 'tryRemoveTagFromPerson'],
            $required['CommonKnowledge\\JoinBlock\\Services\\ZetkinService']
        );
    }

    public function test_injected_callables_do_not_need_the_parent_plugin()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => []],
        ]);

        $result = run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertCount(1, $result['actions']);
    }

    /**
     * If Zetkin ever ignored the page parameter, the walk would fetch the same
     * first page forever. Stop instead of looping, and say why.
     */
    public function test_stops_if_paging_returns_the_same_people_again()
    {
        $page = [
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => []],
            ['id' => 2, 'email' => 'b@example.com', 'zip_code' => 'M1 1AA', 'tags' => []],
        ];

        // Bounded so that a regression fails the test rather than hanging the
        // suite until PHP runs out of memory.
        $calls = 0;
        $lister = function ($p, $perPage) use ($page, &$calls) {
            $calls++;
            $this->assertLessThan(50, $calls, 'run_branch_retag kept paging over the same people');
            return $page;
        };
        $tagsGetter = fn($personId) => [];
        $resolver = fn($title) => ['id' => 1, 'title' => $title];
        $adder = fn($personId, $tagId) => true;
        $remover = fn($personId, $tagId) => true;

        $result = run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertCount(2, $result['actions']);
    }

    public function test_counts_summarise_the_run()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
            ['id' => 2, 'email' => 'b@example.com', 'zip_code' => 'M27 1AA', 'tags' => []],
            ['id' => 3, 'email' => 'c@example.com', 'zip_code' => 'M20 2AA', 'tags' => ['South Manchester']],
            ['id' => 4, 'email' => 'd@example.com', 'zip_code' => 'rubbish', 'tags' => []],
        ]);

        $result = run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertSame(1, $result['counts']['move']);
        $this->assertSame(1, $result['counts']['add']);
        $this->assertSame(1, $result['counts']['unchanged']);
        $this->assertSame(1, $result['counts']['skipped']);
    }

    // Mailchimp. GMTU use it alongside Zetkin, so a branch tag that is only
    // fixed in Zetkin is only half fixed. Zetkin stays the system of record:
    // it holds the postcode the plan is built from, and Mailchimp is matched
    // on email afterwards.

    /**
     * Fake Mailchimp transport. $outcomes maps an email to the status the
     * helpers should report; anything unlisted succeeds.
     */
    private function fakeMailchimp(array $outcomes = [])
    {
        $calls = new \stdClass();
        $calls->added = [];
        $calls->removed = [];
        $calls->order = [];

        $adder = function ($email, $tag) use ($outcomes, $calls) {
            $calls->added[] = [$email, $tag];
            $calls->order[] = "add:$tag";
            return $outcomes[$email] ?? 'ok';
        };
        $remover = function ($email, $tag) use ($outcomes, $calls) {
            $calls->removed[] = [$email, $tag];
            $calls->order[] = "remove:$tag";
            return $outcomes[$email] ?? 'ok';
        };

        return [$adder, $remover, $calls];
    }

    /**
     * Mailchimp's tags cannot be read, so it is told to switch off every known
     * branch other than the expected one. Switching off a tag the member does
     * not have is a no-op there.
     */
    private function mailchimpRemovalsFor(string $email, string $expected): array
    {
        $branches = array_values(array_unique(array_filter(array_values(get_branch_map()))));
        $others = array_values(array_filter($branches, fn($branch) => $branch !== $expected));

        return array_map(fn($branch) => [$email, $branch], $others);
    }

    public function test_apply_also_retags_in_mailchimp()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame([['a@example.com', 'City Centre and Salford']], $mc->added);
        $this->assertSame($this->mailchimpRemovalsFor('a@example.com', 'City Centre and Salford'), $mc->removed);
        $this->assertSame('ok', $result['actions'][0]['mailchimp']);
    }

    /**
     * Same discipline as Zetkin: the new tag goes on before the old one comes
     * off, so an interruption leaves a member over-tagged rather than adrift.
     */
    public function test_mailchimp_gains_the_new_tag_before_losing_the_old_one()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame('add:City Centre and Salford', $mc->order[0]);
        $verbsAfterTheAdd = array_map(fn($call) => explode(':', $call)[0], array_slice($mc->order, 1));
        $this->assertSame(['remove'], array_values(array_unique($verbsAfterTheAdd)));
        $this->assertContains('remove:South Manchester', $mc->order);
    }

    public function test_dry_run_writes_nothing_to_mailchimp()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame([], $mc->added);
        $this->assertSame([], $mc->removed);
    }

    /**
     * Zetkin being right says nothing about Mailchimp: an earlier run may have
     * failed there, or had Mailchimp switched off. Every member with a known
     * branch is brought into line in Mailchimp, whether or not Zetkin changed.
     */
    public function test_members_already_right_in_zetkin_are_still_brought_into_line_in_mailchimp()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M20 2AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame([['a@example.com', 'South Manchester']], $mc->added);
        $this->assertSame($this->mailchimpRemovalsFor('a@example.com', 'South Manchester'), $mc->removed);
    }

    /**
     * Someone in Zetkin but not in the Mailchimp audience is the known blind
     * spot of planning from Zetkin. It must be counted and visible, not
     * silently treated as done.
     */
    public function test_member_missing_from_mailchimp_is_reported_not_failed()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'ghost@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove] = $this->fakeMailchimp(['ghost@example.com' => 'not_found']);

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame('not_found', $result['actions'][0]['mailchimp']);
        $this->assertSame(1, $result['counts']['mailchimpNotFound']);
        $this->assertSame(0, $result['counts']['failed']);
        $this->assertSame(1, $result['counts']['move']);
    }

    /**
     * A Mailchimp outage must not be recorded as a Zetkin failure. Zetkin is
     * the system of record and its write succeeded.
     */
    public function test_mailchimp_failure_is_counted_separately_from_the_zetkin_result()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover, $calls] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove] = $this->fakeMailchimp(['a@example.com' => 'error']);

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame('error', $result['actions'][0]['mailchimp']);
        $this->assertSame(1, $result['counts']['mailchimpFailed']);
        $this->assertSame(0, $result['counts']['failed']);
        $this->assertNotSame([], $calls->added);
    }

    /**
     * If the Zetkin write failed, do not push the change to Mailchimp. That
     * would drive the two systems further apart rather than together.
     */
    public function test_mailchimp_is_left_alone_when_the_zetkin_write_failed()
    {
        [$lister, $tagsGetter, $resolver, , $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        $failingAdder = fn($personId, $tagId) => 'error';
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        $result = run_branch_retag(
            true,
            null,
            $lister,
            $tagsGetter,
            $resolver,
            $failingAdder,
            $remover,
            $mcAdd,
            $mcRemove
        );

        $this->assertSame([], $mc->added);
        $this->assertSame([], $mc->removed);
        $this->assertSame(1, $result['counts']['failed']);
    }

    public function test_mailchimp_outcomes_are_counted()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
            ['id' => 2, 'email' => 'b@example.com', 'zip_code' => 'M25 1AA', 'tags' => []],
            ['id' => 3, 'email' => 'c@example.com', 'zip_code' => 'M20 2AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove] = $this->fakeMailchimp();

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame(3, $result['counts']['mailchimpReconciled']);
        $this->assertSame(0, $result['counts']['mailchimpNotFound']);
        $this->assertSame(0, $result['counts']['mailchimpFailed']);
    }

    /**
     * With Mailchimp switched off, the run still does its Zetkin job and says
     * plainly that Mailchimp was not touched, rather than implying it is done.
     */
    public function test_mailchimp_is_reported_as_disabled_when_not_configured()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover);

        $this->assertSame('disabled', $result['actions'][0]['mailchimp']);
        $this->assertFalse($result['mailchimpEnabled']);
    }

    public function test_mailchimp_is_reported_as_enabled_when_wired_up()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove] = $this->fakeMailchimp();

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertTrue($result['mailchimpEnabled']);
    }

    // The Zetkin callables report ZetkinService::TAG_* statuses. A non-empty
    // string is truthy, so a truthiness check would count 'error' as success;
    // these pin the comparisons against the constants.

    public function test_a_zetkin_error_status_counts_as_failed()
    {
        [$lister, $tagsGetter, $resolver, , $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        $erroringAdder = fn($personId, $tagId) => 'error';

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $erroringAdder, $remover);

        $this->assertSame(1, $result['counts']['failed']);
        $this->assertSame(0, $result['counts']['move']);
    }

    public function test_a_zetkin_error_on_removal_counts_as_failed()
    {
        [$lister, $tagsGetter, $resolver, $adder] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        $erroringRemover = fn($personId, $tagId) => 'error';

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $erroringRemover);

        $this->assertSame(1, $result['counts']['failed']);
    }

    /**
     * Removing a tag the person did not have is Zetkin saying the end state
     * is already true, not a failure.
     */
    public function test_a_missing_tag_on_removal_still_counts_as_success()
    {
        [$lister, $tagsGetter, $resolver, $adder] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        $missingRemover = fn($personId, $tagId) => 'missing';

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $missingRemover);

        $this->assertSame(0, $result['counts']['failed']);
        $this->assertSame(1, $result['counts']['move']);
    }

    // A Zetkin write can throw rather than report a status: the parent's
    // tryAddTagToPerson lets Guzzle's exception through on an HTTP 500. One
    // member's failure must not end the run for everyone after them.

    private function twoMembersMovingOffSouthManchester(): array
    {
        return [
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
            ['id' => 2, 'email' => 'b@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ];
    }

    /**
     * Retries pause between attempts. Tests must not actually wait.
     */
    private function noPause(): callable
    {
        return function ($seconds) {
        };
    }

    private function http500(): \RuntimeException
    {
        return new \RuntimeException(
            'Server error: `PUT people/1/tags` resulted in a `500 Internal Server Error` response'
        );
    }

    public function test_a_zetkin_add_that_throws_fails_that_member_and_the_run_carries_on()
    {
        [$lister, $tagsGetter, $resolver, , $remover] = $this->fakeZetkin($this->twoMembersMovingOffSouthManchester());
        $adder = function ($personId, $tagId) {
            if ($personId === 1) {
                throw $this->http500();
            }
            return 'ok';
        };
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove, $this->noPause());

        $this->assertSame(['failed', 'move'], array_column($result['actions'], 'status'));
        $this->assertSame(1, $result['counts']['failed']);
        $this->assertSame(1, $result['counts']['move']);
        $this->assertNotContains('a@example.com', array_column($mc->added, 0));
        $this->assertNotContains('a@example.com', array_column($mc->removed, 0));
        $this->assertContains('b@example.com', array_column($mc->added, 0));
    }

    public function test_a_zetkin_removal_that_throws_fails_that_member_and_the_run_carries_on()
    {
        [$lister, $tagsGetter, $resolver, $adder] = $this->fakeZetkin($this->twoMembersMovingOffSouthManchester());
        $remover = function ($personId, $tagId) {
            if ($personId === 1) {
                throw $this->http500();
            }
            return 'ok';
        };
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove, $this->noPause());

        $this->assertSame(['failed', 'move'], array_column($result['actions'], 'status'));
        $this->assertNotContains('a@example.com', array_column($mc->added, 0));
        $this->assertNotContains('a@example.com', array_column($mc->removed, 0));
        $this->assertContains('b@example.com', array_column($mc->added, 0));
    }

    /**
     * Tag lookups are cached per title, so a lookup that still fails after
     * its retries must not be cached as a failure: the next member tries again.
     */
    public function test_a_tag_lookup_that_keeps_throwing_fails_that_member_and_the_next_one_retries()
    {
        [$lister, $tagsGetter, , $adder, $remover] = $this->fakeZetkin($this->twoMembersMovingOffSouthManchester());
        $lookups = 0;
        $resolver = function ($title) use (&$lookups) {
            $lookups++;
            if ($lookups <= 3) {
                throw $this->http500();
            }
            return ['id' => crc32($title), 'title' => $title];
        };

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, null, null, $this->noPause());

        $this->assertSame(['failed', 'move'], array_column($result['actions'], 'status'));
    }

    // Successive runs. A run that half-succeeds must be put right by running
    // it again, so these use CRMs whose tags really change when written to.

    /**
     * One member, id 1, a@example.com, postcode M1 (City Centre and Salford).
     * Returns the shared state and a function that applies one run against it.
     * Queue one-shot failures in $state->failNext, keyed "crm:verb:tag", as a
     * status string or a Throwable to throw.
     */
    private function statefulCrms(array $zetkinTags, ?array $mailchimpTags): array
    {
        $state = new \stdClass();
        $state->zetkin = $zetkinTags;
        $state->mailchimp = $mailchimpTags;
        $state->failNext = [];
        $state->mailchimpCalls = [];

        $email = 'a@example.com';

        $failure = function (string $key) use ($state) {
            if (!array_key_exists($key, $state->failNext)) {
                return null;
            }
            $outcome = $state->failNext[$key];
            unset($state->failNext[$key]);
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }
            return $outcome;
        };

        $lister = fn($page, $perPage) => $page === 0
            ? [['id' => 1, 'email' => $email, 'zip_code' => 'M1 1AA']]
            : [];
        $tagsGetter = fn($personId) => array_map(fn($title) => ['id' => $title, 'title' => $title], $state->zetkin);
        $resolver = fn($title) => ['id' => $title, 'title' => $title];
        $adder = function ($personId, $tag) use ($state, $failure) {
            if (($failed = $failure("zetkin:add:$tag")) !== null) {
                return $failed;
            }
            $state->zetkin = array_values(array_unique(array_merge($state->zetkin, [$tag])));
            return 'ok';
        };
        $remover = function ($personId, $tag) use ($state, $failure) {
            if (($failed = $failure("zetkin:remove:$tag")) !== null) {
                return $failed;
            }
            if (!in_array($tag, $state->zetkin, true)) {
                return 'missing';
            }
            $state->zetkin = array_values(array_diff($state->zetkin, [$tag]));
            return 'ok';
        };
        $mcAdd = function ($email, $tag) use ($state, $failure) {
            $state->mailchimpCalls[] = "add:$tag";
            if (($failed = $failure("mailchimp:add:$tag")) !== null) {
                return $failed;
            }
            if ($state->mailchimp === null) {
                return 'not_found';
            }
            $state->mailchimp = array_values(array_unique(array_merge($state->mailchimp, [$tag])));
            return 'ok';
        };
        $mcRemove = function ($email, $tag) use ($state, $failure) {
            $state->mailchimpCalls[] = "remove:$tag";
            if (($failed = $failure("mailchimp:remove:$tag")) !== null) {
                return $failed;
            }
            if ($state->mailchimp === null) {
                return 'not_found';
            }
            $state->mailchimp = array_values(array_diff($state->mailchimp, [$tag]));
            return 'ok';
        };

        $run = function (bool $withMailchimp = true) use ($lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove) {
            return $withMailchimp
                ? run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove, $this->noPause())
                : run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, null, null, $this->noPause());
        };

        return [$state, $run];
    }

    /**
     * Zetkin adds the new branch but fails to remove the old one, so Mailchimp
     * is rightly left alone. The retry is removal-only in Zetkin, because the
     * new tag is already there. Mailchimp must still gain the new branch before
     * losing the old one, or it ends up with no branch at all.
     */
    public function test_a_retry_after_a_failed_zetkin_removal_leaves_mailchimp_on_the_new_branch()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['South Manchester']);
        $state->failNext['zetkin:remove:South Manchester'] = 'error';

        $first = $run();

        $this->assertSame('failed', $first['actions'][0]['status']);
        $this->assertSame(['South Manchester'], $state->mailchimp);

        $second = $run();

        $this->assertSame('remove', $second['actions'][0]['status']);
        $this->assertSame(['City Centre and Salford'], $state->zetkin);
        $this->assertSame(['City Centre and Salford'], $state->mailchimp);
    }

    public function test_a_mailchimp_failure_is_put_right_by_the_next_run()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['South Manchester']);
        $state->failNext['mailchimp:add:City Centre and Salford'] = 'error';

        $first = $run();

        $this->assertSame('error', $first['actions'][0]['mailchimp']);
        $this->assertSame(['City Centre and Salford'], $state->zetkin);
        $this->assertSame(['South Manchester'], $state->mailchimp);

        $second = $run();

        $this->assertSame('unchanged', $second['actions'][0]['status']);
        $this->assertSame('ok', $second['actions'][0]['mailchimp']);
        $this->assertSame(['City Centre and Salford'], $state->mailchimp);
    }

    public function test_a_failed_mailchimp_removal_is_put_right_by_the_next_run()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['South Manchester']);
        $state->failNext['mailchimp:remove:South Manchester'] = 'error';

        $run();

        $this->assertSame(['South Manchester', 'City Centre and Salford'], $state->mailchimp);

        $run();

        $this->assertSame(['City Centre and Salford'], $state->mailchimp);
    }

    public function test_mailchimp_switched_off_for_the_first_run_is_put_right_by_a_later_one()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['South Manchester']);

        $first = $run(false);

        $this->assertSame('disabled', $first['actions'][0]['mailchimp']);
        $this->assertSame(['City Centre and Salford'], $state->zetkin);
        $this->assertSame(['South Manchester'], $state->mailchimp);

        $run(true);

        $this->assertSame(['City Centre and Salford'], $state->mailchimp);
    }

    public function test_bringing_mailchimp_into_line_keeps_its_non_branch_tags()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['Newsletter', 'South Manchester', 'Volunteer']);

        $run();

        $this->assertSame(['Newsletter', 'Volunteer', 'City Centre and Salford'], $state->mailchimp);
    }

    public function test_a_mailchimp_call_that_throws_is_counted_and_put_right_by_the_next_run()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['South Manchester']);
        $state->failNext['mailchimp:add:City Centre and Salford'] = $this->http500();

        $first = $run();

        $this->assertSame('error', $first['actions'][0]['mailchimp']);
        $this->assertSame(1, $first['counts']['mailchimpFailed']);
        $this->assertSame(0, $first['counts']['failed']);

        $run();

        $this->assertSame(['City Centre and Salford'], $state->mailchimp);
    }

    /**
     * Rerunning a finished job changes nothing: same end state, every time.
     */
    public function test_rerunning_a_completed_job_changes_nothing()
    {
        [$state, $run] = $this->statefulCrms(['South Manchester'], ['South Manchester']);

        $run();
        $second = $run();

        $this->assertSame('unchanged', $second['actions'][0]['status']);
        $this->assertSame(['City Centre and Salford'], $state->zetkin);
        $this->assertSame(['City Centre and Salford'], $state->mailchimp);
    }

    /**
     * Review and skipped members have no known branch, so there is nothing to
     * bring Mailchimp into line with. It is not called for them at all.
     */
    public function test_mailchimp_is_not_called_for_members_without_a_known_branch()
    {
        [$lister, $tagsGetter, $resolver, $adder, $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M29 1AA', 'tags' => ['South Manchester']],
            ['id' => 2, 'email' => 'b@example.com', 'zip_code' => 'rubbish', 'tags' => ['South Manchester']],
        ]);
        [$mcAdd, $mcRemove, $mc] = $this->fakeMailchimp();

        $result = run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, $mcAdd, $mcRemove);

        $this->assertSame(['review', 'skipped'], array_column($result['actions'], 'status'));
        $this->assertSame([], $mc->added);
        $this->assertSame([], $mc->removed);
    }

    // Dropped connections. Zetkin drops the odd connection, and a full run
    // makes over a thousand of them, so each Zetkin call gets two retries.

    private function recordingPauses(): array
    {
        $pauses = new \stdClass();
        $pauses->seconds = [];
        $sleeper = function ($seconds) use ($pauses) {
            $pauses->seconds[] = $seconds;
        };
        return [$sleeper, $pauses];
    }

    private function failingTimes(int $failures, $result = 'done'): array
    {
        $calls = new \stdClass();
        $calls->count = 0;
        $call = function () use ($failures, $result, $calls) {
            $calls->count++;
            if ($calls->count <= $failures) {
                throw new \RuntimeException("dropped connection {$calls->count}");
            }
            return $result;
        };
        return [$call, $calls];
    }

    public function test_a_call_that_works_first_time_is_not_retried()
    {
        [$sleeper, $pauses] = $this->recordingPauses();
        [$call, $calls] = $this->failingTimes(0);

        $this->assertSame('done', with_retries($call, $sleeper));
        $this->assertSame(1, $calls->count);
        $this->assertSame([], $pauses->seconds);
    }

    public function test_a_dropped_connection_is_retried_after_a_pause()
    {
        [$sleeper, $pauses] = $this->recordingPauses();
        [$call, $calls] = $this->failingTimes(2);

        $this->assertSame('done', with_retries($call, $sleeper));
        $this->assertSame(3, $calls->count);
        $this->assertSame([2, 5], $pauses->seconds);
    }

    public function test_after_three_failed_attempts_the_last_error_is_passed_on()
    {
        [$sleeper, $pauses] = $this->recordingPauses();
        [$call, $calls] = $this->failingTimes(99);

        try {
            with_retries($call, $sleeper);
            $this->fail('Expected the last error to be passed on');
        } catch (\RuntimeException $e) {
            $this->assertSame('dropped connection 3', $e->getMessage());
        }
        $this->assertSame(3, $calls->count);
        $this->assertSame([2, 5], $pauses->seconds);
    }

    /**
     * A bug in our own code will fail the same way every time, so retrying
     * it only wastes time. Only exceptions are retried, not PHP errors.
     */
    public function test_a_programming_error_is_not_retried()
    {
        [$sleeper, $pauses] = $this->recordingPauses();
        $calls = 0;
        $call = function () use (&$calls) {
            $calls++;
            throw new \TypeError('a bug, not a dropped connection');
        };

        try {
            with_retries($call, $sleeper);
            $this->fail('Expected the error to be passed on');
        } catch (\TypeError $e) {
            $this->assertSame(1, $calls);
        }
        $this->assertSame([], $pauses->seconds);
    }

    public function test_a_dropped_connection_while_reading_members_is_retried()
    {
        [, , $resolver, $adder, $remover] = $this->fakeZetkin([]);
        [$listPage, $listCalls] = $this->failingTimes(1, [
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA'],
        ]);
        $lister = fn($page, $perPage) => $page === 0 ? $listPage() : [];
        [$readTags] = $this->failingTimes(1, [['id' => 'sm', 'title' => 'South Manchester']]);
        $tagsGetter = fn($personId) => $readTags();

        $result = run_branch_retag(false, null, $lister, $tagsGetter, $resolver, $adder, $remover, null, null, $this->noPause());

        $this->assertSame(['move'], array_column($result['actions'], 'status'));
        $this->assertSame(['South Manchester'], $result['actions'][0]['currentBranchTags']);
    }

    public function test_a_dropped_connection_while_writing_is_retried_and_the_member_still_moves()
    {
        [$lister, $tagsGetter, $resolver, , $remover] = $this->fakeZetkin([
            ['id' => 1, 'email' => 'a@example.com', 'zip_code' => 'M1 1AA', 'tags' => ['South Manchester']],
        ]);
        [$adder, $addCalls] = $this->failingTimes(2, 'ok');

        $result = run_branch_retag(
            true, null, $lister, $tagsGetter, fn($t) => $resolver($t), fn($id, $tag) => $adder(), $remover,
            null, null, $this->noPause()
        );

        $this->assertSame(['move'], array_column($result['actions'], 'status'));
        $this->assertSame(0, $result['counts']['failed']);
        $this->assertSame(3, $addCalls->count);
    }

    /**
     * Every member is read before anything is written, so if Zetkin cannot be
     * read even after retrying, nothing has changed. Say so plainly.
     */
    public function test_if_members_cannot_be_read_even_after_retrying_the_run_stops_before_writing()
    {
        [, $tagsGetter, $resolver, $adder, $remover, $calls] = $this->fakeZetkin([]);
        [$listPage] = $this->failingTimes(99);
        $lister = fn($page, $perPage) => $listPage();

        try {
            run_branch_retag(true, null, $lister, $tagsGetter, $resolver, $adder, $remover, null, null, $this->noPause());
            $this->fail('Expected the run to stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nothing was written', $e->getMessage());
            $this->assertStringContainsString('dropped connection 3', $e->getMessage());
        }
        $this->assertSame([], $calls->added);
        $this->assertSame([], $calls->removed);
    }
}
