<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\plan_branch_retag;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\run_branch_retag;

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
            return true;
        };
        $remover = function ($personId, $tagId) use ($calls) {
            $calls->removed[] = [$personId, $tagId];
            return true;
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
}
