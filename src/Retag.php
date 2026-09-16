<?php
/**
 * Bulk branch re-tagging.
 *
 * When GMTU reorganise branches, `Branch.php` changes and every new signup is
 * tagged correctly from that moment on. Existing members keep whatever tag they
 * were given when they joined, because nothing in the join flow revisits them.
 * This brings them back in line.
 *
 * The decision-making is pure and lives in `plan_branch_retag()`, so it can be
 * tested without touching Zetkin. `run_branch_retag()` is the thin layer that
 * fetches, calls the planner, and optionally writes.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

use CommonKnowledge\JoinBlock\Services\ZetkinService;

/**
 * Work out what should happen to each member's branch tags.
 *
 * Pure: no I/O, no side effects. Every person in, one action out, including
 * the ones where the answer is "do nothing" or "cannot tell".
 *
 * Two deliberate restraints:
 *
 * 1. Only tags whose titles are branch names in the given map are ever
 *    proposed for removal. Anything else a member carries is none of our
 *    business.
 * 2. A branch tag is only removed when there is a replacement to put in its
 *    place. Where an outcode is mapped to null (in area, no branch yet),
 *    an existing tag is reported for review rather than stripped, because
 *    stripping it would quietly cut the member off from their organisers.
 *
 * @since 1.5.12
 *
 * @param array $people    Person records: id, email, zip_code, tags (list of titles).
 * @param array $branchMap Outcode => branch name map, as per get_branch_map().
 * @return array List of actions: id, email, postcode, outcode, currentBranchTags,
 *               addTag, removeTags, status, reason. Status is one of move, add,
 *               remove, unchanged, review, skipped.
 */
function plan_branch_retag(array $people, array $branchMap): array {
    $knownBranches = array_values(array_unique(array_filter(array_values($branchMap))));

    $actions = [];

    foreach ($people as $person) {
        $postcode = $person['zip_code'] ?? '';
        $tags = $person['tags'] ?? [];
        $outcode = parse_outcode($postcode);

        $action = [
            'id' => $person['id'] ?? null,
            'email' => $person['email'] ?? '',
            'postcode' => $postcode,
            'outcode' => $outcode,
            'currentBranchTags' => array_values(array_intersect($tags, $knownBranches)),
            'addTag' => null,
            'removeTags' => [],
            'status' => 'skipped',
            'reason' => '',
        ];

        if ($outcode === null) {
            $action['reason'] = 'could not read an outcode from the postcode';
            $actions[] = $action;
            continue;
        }

        if (!array_key_exists($outcode, $branchMap)) {
            $action['reason'] = "outcode $outcode is not in the branch map";
            $actions[] = $action;
            continue;
        }

        $expected = $branchMap[$outcode];
        $current = $action['currentBranchTags'];

        if ($expected === null) {
            if (!empty($current)) {
                $action['status'] = 'review';
                $action['reason'] = "outcode $outcode has no branch, leaving "
                    . implode(', ', $current) . " in place for GMTU to decide";
            } else {
                $action['status'] = 'unchanged';
                $action['reason'] = "outcode $outcode has no branch";
            }
            $actions[] = $action;
            continue;
        }

        $needsAdd = !in_array($expected, $current, true);
        $stale = array_values(array_diff($current, [$expected]));

        $action['addTag'] = $needsAdd ? $expected : null;
        $action['removeTags'] = $stale;

        if ($needsAdd && !empty($stale)) {
            $action['status'] = 'move';
            $action['reason'] = "$outcode is " . $expected . ", currently " . implode(', ', $stale);
        } elseif ($needsAdd) {
            $action['status'] = 'add';
            $action['reason'] = "$outcode is " . $expected . ", currently no branch";
        } elseif (!empty($stale)) {
            $action['status'] = 'remove';
            $action['reason'] = "already on " . $expected . ", also carrying " . implode(', ', $stale);
        } else {
            $action['status'] = 'unchanged';
            $action['reason'] = "already on " . $expected;
        }

        $actions[] = $action;
    }

    return $actions;
}

/**
 * Check the parent plugin is new enough to do a bulk re-tag.
 *
 * The helpers this needs landed in Common Knowledge Join Flow 1.4.38. Against
 * an older parent the run would fatal partway through, which on a write run
 * could leave members half re-tagged, so refuse before touching anything.
 *
 * @since 1.5.12
 *
 * @throws \RuntimeException If the parent plugin is missing or out of date.
 * @return void
 */
function require_zetkin_bulk_helpers() {
    $required = [
        'listPeople',
        'getPersonTags',
        'findOrCreateTagByTitle',
        'addTagToPerson',
        'removeTagFromPerson',
    ];

    foreach ($required as $method) {
        if (!method_exists(ZetkinService::class, $method)) {
            throw new \RuntimeException(
                "ZetkinService::$method() is not available. Branch re-tagging needs "
                . "Common Knowledge Join Flow 1.4.38 or newer. Update the parent plugin and try again."
            );
        }
    }
}

/**
 * Walk the membership and bring branch tags into line with the branch map.
 *
 * Previews by default. Nothing is written unless $apply is true.
 *
 * The five callable parameters exist for testing. In production they all
 * default to the parent plugin's ZetkinService.
 *
 * @since 1.5.12
 *
 * @param bool          $apply         Write the changes. False previews only.
 * @param int|null      $limit         Stop after this many members. Null for all.
 * @param callable|null $people_lister fn(int $page, int $perPage): array
 * @param callable|null $tags_getter   fn($personId): array of tag records
 * @param callable|null $tag_resolver  fn(string $title): ?array tag record
 * @param callable|null $tag_adder     fn($personId, $tagId): bool
 * @param callable|null $tag_remover   fn($personId, $tagId): bool
 * @return array{actions: array, counts: array, applied: bool}
 */
function run_branch_retag(
    bool $apply = false,
    ?int $limit = null,
    ?callable $people_lister = null,
    ?callable $tags_getter = null,
    ?callable $tag_resolver = null,
    ?callable $tag_adder = null,
    ?callable $tag_remover = null
): array {
    $usingParentPlugin = $people_lister === null
        || $tags_getter === null
        || $tag_resolver === null
        || $tag_adder === null
        || $tag_remover === null;

    if ($usingParentPlugin) {
        require_zetkin_bulk_helpers();
    }

    $list_people = $people_lister ?? fn($page, $perPage) => ZetkinService::listPeople($page, $perPage);
    $get_tags    = $tags_getter   ?? fn($personId) => ZetkinService::getPersonTags($personId);
    $resolve_tag = $tag_resolver  ?? fn($title) => ZetkinService::findOrCreateTagByTitle($title);
    $add_tag     = $tag_adder     ?? fn($personId, $tagId) => ZetkinService::addTagToPerson($personId, $tagId);
    $remove_tag  = $tag_remover   ?? fn($personId, $tagId) => ZetkinService::removeTagFromPerson($personId, $tagId);

    $perPage = 100;
    $people = [];
    $page = 0;

    while ($limit === null || count($people) < $limit) {
        $batch = $list_people($page, $perPage);
        if (empty($batch)) {
            break;
        }

        foreach ($batch as $person) {
            $personId = $person['id'] ?? null;
            $people[] = [
                'id' => $personId,
                'email' => $person['email'] ?? '',
                'zip_code' => $person['zip_code'] ?? '',
                'tags' => array_column($get_tags($personId), 'title'),
            ];
        }

        $page++;
    }

    if ($limit !== null) {
        $people = array_slice($people, 0, $limit);
    }

    $actions = plan_branch_retag($people, get_branch_map());

    $counts = [
        'move' => 0,
        'add' => 0,
        'remove' => 0,
        'unchanged' => 0,
        'review' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    // Resolving a tag title costs a round trip, and a run moves many members
    // between the same handful of branches, so resolve each title once.
    $tagIds = [];
    $resolve = function ($title) use (&$tagIds, $resolve_tag) {
        if (!array_key_exists($title, $tagIds)) {
            $tag = $resolve_tag($title);
            $tagIds[$title] = $tag['id'] ?? null;
        }
        return $tagIds[$title];
    };

    foreach ($actions as $index => $action) {
        $counts[$action['status']]++;

        if (!$apply) {
            continue;
        }

        if ($action['addTag'] === null && empty($action['removeTags'])) {
            continue;
        }

        $ok = true;

        if ($action['addTag'] !== null) {
            $tagId = $resolve($action['addTag']);
            $ok = $tagId !== null && $add_tag($action['id'], $tagId);
            if ($ok) {
                log_info("Tagged {$action['email']} as {$action['addTag']}");
            } else {
                log_warning("Could not tag {$action['email']} as {$action['addTag']}");
            }
        }

        // Only take the old tag off once the new one is definitely on, so a
        // failure halfway through leaves a member over-tagged rather than
        // untagged.
        if ($ok) {
            foreach ($action['removeTags'] as $title) {
                $tagId = $resolve($title);
                if ($tagId === null || !$remove_tag($action['id'], $tagId)) {
                    $ok = false;
                    log_warning("Could not remove tag $title from {$action['email']}");
                    continue;
                }
                log_info("Removed tag $title from {$action['email']}");
            }
        }

        if (!$ok) {
            $actions[$index]['status'] = 'failed';
            $counts[$action['status']]--;
            $counts['failed']++;
        }
    }

    return [
        'actions' => $actions,
        'counts' => $counts,
        'applied' => $apply,
    ];
}
