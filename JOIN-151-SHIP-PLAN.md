# JOIN-151 ship plan

Target: shipped today, 21 Sep 2026.

Three PRs are open. They must go out in order, because each depends on the one before.

| | PR | What it does | Blocks |
|---|---|---|---|
| 1 | [join#119](https://github.com/commonknowledge/join/pull/119) | Pin `@wordpress/env`, unblocking the e2e job | Everything. No release can happen while e2e cannot install |
| 2 | [join#118](https://github.com/commonknowledge/join/pull/118) | Parent plugin 1.4.39, Zetkin **and Mailchimp** bulk helpers | The GMTU re-tag command refuses to run without it |
| 3 | [gmtu#11](https://github.com/commonknowledge/common-knowledge-join-flow-gmtu/pull/11) | Branch map fix and `wp gmtu retag_branches`, re-tagging both CRMs | The actual ticket |

---

## Stage 1 — Unblock the release pipeline

`release-plugin.yml` gates the WordPress.org deploy on `needs: [php-tests, jest-tests, e2e]`. e2e has been dead since 4 September, so nothing can ship until this lands.

- [ ] Confirm e2e passes on join#119
- [ ] Review and merge join#119 to `master`
- [ ] Confirm e2e passes on `master` after merge

## Stage 2 — Release parent plugin 1.4.39

- [ ] Merge `master` into the join#118 branch so it picks up the e2e fix
- [ ] Confirm all three checks pass on join#118
- [ ] Review and merge join#118 to `master`
- [ ] Tag `1.4.39` on `master` and push the tag (this is what triggers the WordPress.org deploy; it is not automatic on merge)
- [ ] Watch `release-plugin.yml` through to the WordPress.org deploy step
- [ ] Confirm 1.4.39 appears on WordPress.org and as a GitHub release

## Stage 3 — Release the GMTU plugin

- [ ] Confirm tests pass on gmtu#11
- [ ] Review and merge gmtu#11 to `main`
- [ ] Confirm the release workflow auto-bumps to 1.5.12, tags it and creates the release (GMTU bumps on merge; the parent does not)

## Stage 4 — Deploy to production

- [ ] Update the parent plugin to 1.4.39 on the GMTU site
- [ ] Update the GMTU plugin to 1.5.12
- [ ] Confirm both versions in wp-admin plugins list
- [ ] Confirm no PHP errors in the log after activation

## Stage 5 — Verify new joiners are tagged correctly

Do this before re-tagging anyone. If new signups are still wrong, re-tagging just creates work to redo.

- [ ] Join through the live form with an **M3** postcode. Expect tag `City Centre and Salford`
- [ ] Join with an **M6** postcode (previously no branch at all). Expect tag `City Centre and Salford`
- [ ] Join with an **M25** postcode. Expect tag `Bury`
- [ ] Join with an **OL10** postcode. Expect tag `Rochdale`
- [ ] Join with an **M20** postcode. Expect tag `South Manchester`, unchanged
- [ ] Confirm each test member actually carries the tag in Zetkin, not just that the form succeeded
- [ ] Confirm each test member also carries the tag in **Mailchimp**, since new joiners go to both
- [ ] Confirm no duplicate near-identical tags were created in Zetkin (a typo silently creates a second tag rather than failing)
- [ ] Confirm the City Centre and Salford branch notification email arrived at `citycentre@tenantsunion.org.uk`
- [ ] Confirm the South Manchester notification arrived at `southmcr@` — **see open question 3 below**
- [ ] Delete or mark the test members

## Stage 6 — Dry run the re-tag

Needs production Zetkin credentials. Writes nothing.

- [ ] `wp gmtu retag_branches --limit=10` and read the output carefully
- [ ] Confirm the tag names it reports match the tags that actually exist in Zetkin
- [ ] Confirm it is only ever touching branch tags, nothing else
- [ ] `wp gmtu retag_branches` for the full preview
- [ ] Sanity check the totals: only M1, M2, M3 and M50 members should be *moving off* a branch. Everything else is additive
- [ ] Note how many members are affected and roughly how long the walk took
- [ ] Confirm the paging guard did not trigger. If it did, the Zetkin paging parameters are being ignored and the walk stopped early, so **stop and investigate** rather than applying
- [ ] Confirm the run reports Mailchimp as **enabled**. If it warns that Mailchimp is not configured, the credentials are missing and only half the job would run

## Stage 7 — Get sign-off, then apply

- [ ] Send Isaac the preview list
- [ ] Get explicit go-ahead before writing anything
- [ ] `wp gmtu retag_branches --limit=10 --apply`
- [ ] Check those ten in **both Zetkin and Mailchimp** by hand before going further
- [ ] `wp gmtu retag_branches --apply` for the full run
- [ ] Re-run the plain preview. It should now report nothing left to change
- [ ] Spot check a handful of members across City Centre and Salford, Bury and Rochdale, in both CRMs
- [ ] Read the "Not in the Mailchimp audience" count. Those members were fixed in Zetkin only. If it is large, the two systems are further out of sync than expected and worth a follow-up

## Stage 8 — Close out

- [ ] Confirm with Isaac that City Centre and Salford organisers can see their members
- [ ] Move JOIN-151 to Done
- [ ] Raise the follow-up tickets listed below

---

## Open questions for Isaac

Asked on JOIN-151, none of them blocking. Everything is implemented exactly as the sheet says, so each is a one line change if the answer differs.

- [ ] **M4, Northern Quarter.** Still South Manchester in both sheets, while M1, M2 and M3 all moved. Deliberate or missed?
- [ ] **BL8 and BL9, Bury town.** Still "No branch" even though the sheet now has a Bury branch. So Prestwich and Radcliffe get Bury and Bury does not
- [ ] **South Manchester email.** Sheet says `southmcr@tenantsunion.org.uk`, live code had `south.mcr@` with a dot. Only one can be receiving mail, and the wrong one fails silently. Following the sheet

## Resolved

- [x] **Is Mailchimp still a live integration for GMTU?** Yes. The re-tag command now fixes Mailchimp as well as Zetkin, planning from Zetkin postcodes and matching on email

## Follow-up tickets to raise

- [ ] **BL0 Ramsbottom is blocked from joining entirely.** It is absent from the branch map, and an absent outcode is rejected as out of area, so those people are told membership is not available where they live. Separate from the Bury branch question above
- [ ] **Members who move house keep their old branch tag.** They end up carrying both. The re-tag job clears today's backlog but nothing stops it recurring
- [ ] **Unpin `@wordpress/env`** once upstream publish `@php-wasm/node-8-1` or drop the reference
- [ ] **`packages/join-e2e` has no yarn.lock of its own**, which is why a bad upstream release could break CI with no change on our side. Worth committing one
- [ ] **Audit how many Mailchimp members are missing from Zetkin.** The re-tag run's "not in the Mailchimp audience" count measures the reverse. If Mailchimp holds people Zetkin does not, they will never be re-tagged by a Zetkin-driven job and need their own pass
