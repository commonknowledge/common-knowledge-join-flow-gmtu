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

use CommonKnowledge\JoinBlock\Services\MailchimpService;
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
 * The Zetkin helpers landed in Common Knowledge Join Flow 1.4.38 and the
 * Mailchimp ones in 1.4.39. Against an older parent the run would fatal
 * partway through, which on a write run could leave members half re-tagged,
 * so refuse before touching anything.
 *
 * @since 1.5.12
 *
 * @throws \RuntimeException If the parent plugin is missing or out of date.
 * @return void
 */
function require_parent_plugin_helpers() {
    $required = [
        ZetkinService::class => [
            'listPeople',
            'getPersonTags',
            'findOrCreateTagByTitle',
            'addTagToPerson',
            'removeTagFromPerson',
        ],
        MailchimpService::class => [
            'isConfigured',
            'addTagToMember',
            'removeTagFromMember',
        ],
    ];

    foreach ($required as $class => $methods) {
        $shortName = substr((string) strrchr($class, '\\'), 1);

        foreach ($methods as $method) {
            if (!method_exists($class, $method)) {
                throw new \RuntimeException(
                    "$shortName::$method() is not available. Branch re-tagging needs "
                    . "Common Knowledge Join Flow 1.4.39 or newer. Update the parent plugin and try again."
                );
            }
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
 * @param callable|null $mc_tag_adder   fn(string $email, string $tag): string
 * @param callable|null $mc_tag_remover fn(string $email, string $tag): string
 * @return array{actions: array, counts: array, applied: bool, mailchimpEnabled: bool}
 */
function run_branch_retag(
    bool $apply = false,
    ?int $limit = null,
    ?callable $people_lister = null,
    ?callable $tags_getter = null,
    ?callable $tag_resolver = null,
    ?callable $tag_adder = null,
    ?callable $tag_remover = null,
    ?callable $mc_tag_adder = null,
    ?callable $mc_tag_remover = null
): array {
    $usingParentPlugin = $people_lister === null
        || $tags_getter === null
        || $tag_resolver === null
        || $tag_adder === null
        || $tag_remover === null;

    if ($usingParentPlugin) {
        require_parent_plugin_helpers();
    }

    $list_people = $people_lister ?? fn($page, $perPage) => ZetkinService::listPeople($page, $perPage);
    $get_tags    = $tags_getter   ?? fn($personId) => ZetkinService::getPersonTags($personId);
    $resolve_tag = $tag_resolver  ?? fn($title) => ZetkinService::findOrCreateTagByTitle($title);
    $add_tag     = $tag_adder     ?? fn($personId, $tagId) => ZetkinService::addTagToPerson($personId, $tagId);
    $remove_tag  = $tag_remover   ?? fn($personId, $tagId) => ZetkinService::removeTagFromPerson($personId, $tagId);

    // Zetkin is the system of record: it holds the postcode the plan is built
    // from. Mailchimp cannot be planned from, because a member's postcode only
    // reaches it inside the ADDRESS merge field, which is skipped entirely on
    // update flows and when no street address was collected. So the plan comes
    // from Zetkin and Mailchimp is matched on email afterwards. The blind spot
    // is anyone in Mailchimp but not Zetkin; they are never reached, which is
    // why the run reports its Mailchimp outcomes separately rather than
    // folding them into the totals.
    $mailchimpInjected = $mc_tag_adder !== null && $mc_tag_remover !== null;
    $mailchimpEnabled = $mailchimpInjected || (
        method_exists(MailchimpService::class, 'isConfigured') && MailchimpService::isConfigured()
    );

    $mc_add_tag    = $mc_tag_adder   ?? fn($email, $tag) => MailchimpService::addTagToMember($email, $tag);
    $mc_remove_tag = $mc_tag_remover ?? fn($email, $tag) => MailchimpService::removeTagFromMember($email, $tag);

    $perPage = 100;
    $people = [];
    $page = 0;

    // Guards against a paging API that hands back the same page forever, which
    // would otherwise walk until the process runs out of memory.
    $seen = [];

    while ($limit === null || count($people) < $limit) {
        $batch = $list_people($page, $perPage);
        if (empty($batch)) {
            break;
        }

        $newInBatch = 0;

        foreach ($batch as $person) {
            $personId = $person['id'] ?? null;
            $key = $personId ?? ($person['email'] ?? null);

            if ($key === null) {
                log_warning('Skipping a Zetkin record with neither an id nor an email');
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $newInBatch++;

            $people[] = [
                'id' => $personId,
                'email' => $person['email'] ?? '',
                'zip_code' => $person['zip_code'] ?? '',
                'tags' => array_column($get_tags($personId), 'title'),
            ];
        }

        if ($newInBatch === 0) {
            log_warning("Zetkin returned no new people on page $page, stopping the walk there. "
                . "Check that the people endpoint is paging as expected.");
            break;
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
        'mailchimpUpdated' => 0,
        'mailchimpNotFound' => 0,
        'mailchimpFailed' => 0,
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
        $actions[$index]['mailchimp'] = $mailchimpEnabled ? 'skipped' : 'disabled';

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
            continue;
        }

        if (!$mailchimpEnabled) {
            continue;
        }

        // Only once Zetkin is settled. Pushing a change to Mailchimp after a
        // failed Zetkin write would drive the two further apart, not together.
        $mailchimpResult = 'ok';

        if ($action['addTag'] !== null) {
            $mailchimpResult = $mc_add_tag($action['email'], $action['addTag']);
        }

        // Add before remove here too, so an interruption leaves a member
        // over-tagged in Mailchimp rather than with no branch at all.
        if ($mailchimpResult === 'ok') {
            foreach ($action['removeTags'] as $title) {
                $status = $mc_remove_tag($action['email'], $title);
                if ($status !== 'ok') {
                    $mailchimpResult = $status;
                    break;
                }
            }
        }

        $actions[$index]['mailchimp'] = $mailchimpResult;

        if ($mailchimpResult === 'ok') {
            $counts['mailchimpUpdated']++;
        } elseif ($mailchimpResult === 'not_found') {
            $counts['mailchimpNotFound']++;
            log_info("{$action['email']} is not in the Mailchimp audience, so only Zetkin was updated");
        } else {
            $counts['mailchimpFailed']++;
            log_warning("Could not update Mailchimp tags for {$action['email']}");
        }
    }

    return [
        'actions' => $actions,
        'counts' => $counts,
        'applied' => $apply,
        'mailchimpEnabled' => $mailchimpEnabled,
    ];
}
