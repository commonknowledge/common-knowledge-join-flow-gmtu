# CK Join Flow Extensions for GMTU

This add-on to the main [CK Join Flow plugin](https://github.com/commonknowledge/join) includes functionality specific to the Greater Manchester Tenants Union (GMTU) installation.

## Features

- **Postcode validation** — Validates that postcodes are within the Greater Manchester coverage area and blocks out-of-area submissions with helpful error messages.
- **Branch assignment** — Automatically assigns members to a branch (e.g. South Manchester, Harpurhey, Stockport) based on their postcode outcode.
- **Branch tagging** — Adds the assigned branch name as a tag when members are synced to external services (Mailchimp, Zetkin, etc.).
- **Email notifications** — Sends admin and branch-specific notification emails when a new member registers.
- **Postcode lookup caching** — Caches postcodes.io API responses as WordPress transients (7-day TTL) to reduce external API calls.

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
```

## Configuration

The main configuration is in `join-gmtu.php` and includes:

- Out-of-area error messages (for postcode lookup and form submission)
- Admin notification email addresses
- Notification subject and message templates

Branch-to-postcode mappings and branch email addresses are in `src/Branch.php`.
