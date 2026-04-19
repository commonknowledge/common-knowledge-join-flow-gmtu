# CK Join Flow Extensions for GMTU

This add-on to the main [CK Join Flow plugin](https://github.com/commonknowledge/join) includes functionality specific to the Greater Manchester Tenants Union (GMTU) installation.

## Features

- **Postcode validation** — Validates that postcodes are within the Greater Manchester coverage area and blocks out-of-area submissions with helpful error messages.
- **Branch assignment** — Automatically assigns members to a branch (e.g. South Manchester, Harpurhey, Stockport) based on their postcode outcode.
- **Branch tagging** — Adds the assigned branch name as a tag when members are synced to external services (Mailchimp, Zetkin, etc.).
- **Email notifications** — Sends admin and branch-specific notification emails when a new member registers.
- **Postcode lookup caching** — Caches postcodes.io API responses as WordPress transients (7-day TTL) to reduce external API calls.
- **Membership lapsing override** — Applies GMTU's own standing rules instead of lapsing members immediately on Stripe payment failure.

## Hook lifecycle

The parent plugin fires hooks at each stage of member registration and membership management. This plugin hooks into them in the following order:

| # | Hook | File | What we do |
|---|------|------|------------|
| 1 | `ck_join_flow_postcode_validation` (filter) | `PostcodeValidation.php` | Check outcode against branch map; return error if out of area |
| 2 | `ck_join_flow_step_response` (filter) | `PostcodeValidation.php` | Second-line validation on form step submission |
| 3 | `ck_join_flow_pre_handle_join` (filter) | `BranchAssignment.php` | Look up postcode outcode, find branch, inject into `$data["branch"]` |
| 4 | `ck_join_flow_add_tags` (filter) | `Tagging.php` | Append branch name to tags sent to external services |
| 5 | `ck_join_flow_success` (action, priority 5) | `LapsingOverride.php` | Clear sticky-lapsed flag when a member explicitly rejoins |
| 6 | `ck_join_flow_success` (action, priority 10) | `Notifications.php` | Send admin notification email |
| 7 | `ck_join_flow_success` (action, priority 20) | `Notifications.php` | Send branch-specific notification email |
| 8 | `ck_join_flow_should_lapse_member` (filter) | `LapsingOverride.php` | Override lapse decision using GMTU standing rules (see below) |
| 9 | `ck_join_flow_should_unlapse_member` (filter) | `LapsingOverride.php` | Override unlapse decision using GMTU standing rules (see below) |

## Membership lapsing override

### Why this exists

Stripe fires webhook events whenever a payment fails or a subscription is cancelled. The parent plugin responds to these by marking the member as lapsed in all configured integrations. For GMTU, this is too aggressive — a single missed payment does not mean a member has lapsed under GMTU's rules.

This plugin intercepts the parent plugin's lapsing decisions and applies GMTU's own standing classification instead.

### Standing classification rules

Membership standing is classified by counting **completed calendar months** since the member's last successful GMTU payment. The current in-progress month is always excluded from this count.

| Missed completed months | Status |
|------------------------|--------|
| 0–2 | Good standing |
| 3 | Early arrears |
| 4–6 | Lapsing |
| 7 or more | **Lapsed** |

Additional rules:

- **Only GMTU payments count.** Payments are identified by the Stripe **product ID** on a subscription's line items: only subscriptions whose product ID matches a configured GMTU membership plan are counted. Configured product IDs are discovered by reading every `wp_options` row with the `ck_join_flow_membership_plan_` prefix (written by the parent plugin when plans are saved) and collecting each plan's `stripe_product_id`. Unrelated subscriptions on the same Stripe customer are ignored.
- **Only paid invoices count.** The fetcher asks Stripe for invoices with `status=paid` and records each invoice's `status_transitions.paid_at` timestamp. Draft, open, void, and uncollectible invoices are skipped. (A later refund does not reset an invoice's `paid` status, so a refunded payment's month will still count — out-of-band refunds are rare enough for GMTU that this is acceptable.)
- **Lapsed is permanent.** Once a member reaches Lapsed status, a later payment does not automatically reinstate them. They must rejoin via the join form. This state is stored persistently in the WordPress database (see below).
- **New member exception.** If someone makes their very first successful GMTU payment in the current month, they are treated as Good standing immediately.

### How the override hooks work

Both override filters inspect the `provider` value on the context array passed by the parent plugin and only run their custom logic when `provider === 'stripe'`. Any non-Stripe provider returns the incoming decision unchanged, leaving the parent plugin's default behaviour in place.

**`ck_join_flow_should_lapse_member`**

Called by the parent plugin when a Stripe payment event signals that a member should be lapsed. This plugin:

1. Fetches the member's GMTU payment history by querying the Stripe Customers, Subscriptions, and Invoices APIs: find all Stripe customers for the email, list their subscriptions, filter to GMTU membership subscriptions (see above), and collect the `paid_at` timestamps of each subscription's paid invoices.
2. Classifies their standing using the rules above.
3. Returns `true` (allow lapse) only if the member is classified as **Lapsed** (7+ missed months). Records the lapsed flag.
4. Returns `false` (suppress lapse) for Good standing, Early arrears, or Lapsing -- the parent plugin is acting more aggressively than GMTU rules require.
5. If the member has no GMTU payment history at all, logs a warning and passes through to the parent plugin default.
6. Falls through to the parent plugin default on Stripe API errors, to avoid accidental lapsing due to a transient network failure.

**`ck_join_flow_should_unlapse_member`**

Called by the parent plugin when a Stripe payment event signals that a member should be unlapsed (e.g. after a successful payment). This plugin:

1. Fetches the member's GMTU payment history and classifies their standing.
2. Returns `true` (allow unlapse) only if the member is **Good standing** and is not flagged as lapsed.
3. Returns `false` (suppress unlapse) if the member is lapsed -- they must rejoin explicitly via the join form.
4. Returns `false` if the member is in Early arrears or Lapsing -- one payment is not enough to restore Good standing.
5. Falls through to the parent plugin default on Stripe API errors.

**`ck_join_flow_success` (priority 5)**

When a member completes the join form successfully, the lapsed flag is cleared. This is what allows a previously-lapsed member to regain Good standing, but only after going through the full join flow again.

### Example

Suppose today is 15 August. The last completed month is July.

| Last payment | Missed months | Status | Lapse webhook outcome |
|---|---|---|---|
| April | May, Jun, Jul (3) | Early arrears | Suppressed |
| January | Feb, Mar, Apr, May, Jun, Jul (6) | Lapsing | Suppressed |
| December (prior year) | Jan through Jul (7) | Lapsed | Allowed; lapsed flag recorded |

### Lapsed flag storage

The lapsed flag is stored in WordPress `wp_options`, keyed by `gmtu_lapsed_` followed by the SHA-256 hash of the member's lowercased email address. The stored value is a JSON object recording the email, timestamp, and webhook trigger, for audit purposes. The flag is cleared automatically when the member completes a new join form submission.

## Structure

```
join-gmtu.php              # Plugin entry point, config, hook registration
src/
  Logger.php               # Logging utilities (wraps joinBlockLog)
  Postcode.php             # Postcode outcode lookup via postcodes.io with caching
  Branch.php               # Branch map (outcode -> branch) and branch email map
  Member.php               # Extracts and formats member details from registration data
  Email.php                # Email body building and send functions
  PostcodeValidation.php   # Validates postcodes are within GM coverage area
  BranchAssignment.php     # Assigns branch to member data based on postcode
  Tagging.php              # Adds branch as tag in external services
  Notifications.php        # Registers success notification hooks
  MembershipStanding.php   # Pure GMTU standing classifier (no I/O, fully unit-tested)
  LapsedStore.php          # Persists lapsed flag in wp_options
  StripePaymentHistory.php # Fetches paid-invoice months from Stripe (Customers + Subscriptions + Invoices)
  LapsingOverride.php      # Hooks into parent lapsing filters using the above three
```

## Configuration

The main configuration is in `join-gmtu.php` and includes:

- Out-of-area error messages (for postcode lookup and form submission)
- Admin notification email addresses
- Notification subject and message templates

Branch-to-postcode mappings and branch email addresses are in `src/Branch.php`.

## Local Development

Requires Docker. Spins up WordPress with the parent plugin installed, this plugin mounted and activated, and Mailpit to capture outbound emails.

```bash
docker compose up -d          # start stack
docker compose logs -f wpcli  # watch setup progress
```

| Service   | URL                          | Credentials   |
|-----------|------------------------------|---------------|
| WordPress | http://localhost:8080/wp-admin | admin / admin |
| Mailpit   | http://localhost:8025          | —             |

All emails sent by `wp_mail()` are captured in the Mailpit web UI.

```bash
docker compose down            # stop (keep data)
docker compose down -v         # stop and wipe all data
```

## Tests

```bash
composer install
composer test
```
