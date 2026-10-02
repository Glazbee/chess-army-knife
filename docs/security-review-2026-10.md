# Security review: admin handlers added or changed since PR 27

Method: a read of each handler and the page that feeds it, for a nonce, a capability check, input
handling, redirects and output escaping. No handler was run against a live site, and no dynamic or
automated scanner was used. It is a code review, not a penetration test.

## Handlers read

| Handler | Nonce | Capability | Input | Result |
|---|---|---|---|---|
| Setup (`class-setup.php`) | `check_admin_referer` | `manage_options` | Each field `sanitize_text_field`, map link and what3words cleaned again on save, weekday clamped 0-6, times validated by `combine_datetime` | Fine |
| Sort Clubs (`class-clubs.php`) | yes | teams permission | Team names must be ones an import saw; existing club must be a club post; venue cleaned | Fine |
| Club edit box (`Clubs::save`) | yes | `edit_post` | cleaned | Fine |
| Do Not Record | yes | members permission | code and name only become one-way fingerprints | Fine |
| Bulk member actions | yes | members permission | ids `absint`; type and team looked up; deletion needs a confirm tick; the notice that echoes request values is `esc_html` | Fine |
| Policies: set up, review, restore | per-policy nonce | `edit_pages`; restore also needs `edit_post` on the page | key checked against the known list | Fine |
| Policies: retention period | yes | **`edit_pages`** | number | **Finding 1** |
| Import Events, Test LMS connection | yes | teams / `manage_options` | none | Fine |
| Calendar feed and one-event download (public) | n/a | published events only | `event` is an id, `start` must be a date the event really happens; text escaped for iCalendar | Fine |

## Findings

1. **Medium. Anyone who can edit pages could change how long members' details are kept.** The
   retention period decides when lapsed members' records are deleted automatically, but it sat
   behind the Policies screen's `edit_pages` permission, which an Editor has. **Fixed:** changing it
   now needs an administrator or someone given the members permission (`Policies::can_set_retention()`);
   others see the period as text. Covered by an integration test.
2. **Low. The LMS API key was written into the Settings and Setup pages.** It was in a password box
   but its value was in the page source for any administrator, and so in browser history, caches and
   screenshots. **Fixed:** the key is never printed again; a blank box keeps the saved key, typing
   replaces it, and a tick box removes it. Covered by a unit test and an integration test.

## Noted, not changed

- The LMS API key is still stored as plain text in the settings option, as WordPress stores every
  setting. Anyone with database access, or who can read exports of the options table, can read it.
  A key with only read access to the LMS limits the harm.
- The membership and team permissions are given to individual users rather than roles, which is
  deliberate; this review did not look at how they are granted (`class-memberships-admin.php`).
- Earlier handlers (members, renewals, selection, tournaments, the public forms and the portal) were
  not re-read in this pass.
