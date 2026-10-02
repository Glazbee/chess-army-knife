# Security review: admin handlers, public forms and REST routes

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

## Older handlers, re-read (second pass)

| Area | Nonce | Capability | Result |
|---|---|---|---|
| Members screen: save, approve and decline, delete, refresh ratings | yes (delete and approve are tied to the member's id) | members permission | Fine. Approve, delete and renew are links (GET) protected by a nonce; a POST would be stricter |
| Member CSV export | yes | members permission | Fine. Cells starting `=`, `+`, `-` or `@` are neutralised, so a name cannot become a spreadsheet formula |
| Renewals: send, test, renew | yes | members permission | Fine |
| Member checks (verify ECF codes) | yes | members permission | Fine |
| Selection (availability requests, line-ups) | yes (tied to fixture and team) | the user must manage that team; only squad members who are active can be placed | Fine |
| Availability reply (public link) | signed link | n/a | Fine: HMAC of the row with the site salt, compared with `hash_equals`; a reply only changes that person's answer for that fixture |
| Tournaments (12 handlers) | yes | tournaments permission | Fine. The nonces are per action, not per tournament |
| Tournament results (REST) | cookie nonce | tournaments permission | Fine |
| Templates, clear cache | yes | `manage_options` | Fine. Custom CSS has tags, `@import`, `expression(` and `javascript:` stripped; it comes from administrators |
| Teams and announcements edit boxes | yes | `edit_post`, plus members permission for announcements, `promote_users` for choosing a captain's login | Fine |
| Who gets the membership and team permissions | yes | `promote_users` | Fine |
| Public: membership form, Manage My Data, Member Portal | yes | none (public) | Fine: honeypot, throttling by IP, the same answer whether or not an address is on file, 32-character random tokens, the redirect target is checked, and saved form values leave out tokens and nonces |
| Public: calendar feed | n/a | published events only | Fine |
| Editor REST routes (templates, defaults, tournaments list) | cookie nonce | `edit_posts` | Fine: they return no personal data |
| Editor REST route: player search | cookie nonce | `edit_posts` | **Finding 3** |

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
3. **Medium. Contributors and Authors could search members by name.** The editor's player picker
   (`/wp-json/ecf-lms/v1/players`) returns the name and ECF code of members, including juniors, to
   anyone with `edit_posts`. A club site that lets members write posts would show them to every
   such member. **Fixed:** the default is now `edit_others_posts` (an Editor). The
   `Chess_Army_Knife_member_search_capability` filter still lets a site narrow it to membership
   officers, or widen it again. **A change in behaviour:** an Author who used the picker in the
   editor no longer can. Covered by an integration test.
4. **Low. Pages opened from an emailed link carry a token in the address.** The member portal,
   email-change and withdraw links put a 32-character token in the query string. A page cache could
   store such a page, and the address could be passed on in a Referer header. **Fixed:** these pages
   are sent with no-cache headers and `Referrer-Policy: no-referrer` (`Member_Requests::protect_token_pages()`).
   The recognition is unit tested; the headers themselves are not, so **check on your site** that
   they are sent (browser developer tools, Network tab, on a portal link).

## Encryption of the LMS key

The key is now encrypted in the database (libsodium, with a key made from the site's salts), and
decrypted when read, so it is not in plain text in a database dump or backup
(`Chess_Army_Knife_Secrets`). Keys saved before this are encrypted the next time an administrator
opens the admin area. The key can instead be set in `wp-config.php` with
`define( 'CHESS_ARMY_KNIFE_LMS_API_KEY', '...' );`, which keeps it out of the database altogether
and is the stronger choice. Limits: someone who can read both the database and `wp-config.php`, or
run code on the site, can still get the key; and if the site's salts are changed, the stored key can
no longer be read and must be entered again.

## Noted, not changed

- Destructive and state-changing admin links (delete a member, delete a tournament, approve, renew)
  are GET requests with a nonce. A POST form would be stricter.
- The tournament nonces are per action, not per tournament, unlike the members screen.
- Asking for a portal link sends an email only when the address is on file, so a careful observer
  could tell from the response time. The answer itself is the same.
- Throttling is by the connecting IP address. Behind a proxy that hides visitors' addresses, all
  visitors share one allowance.
- Not reviewed: the ECF rating refresh and the mailer's queue internals, the plugin's data export and
  erasure code beyond its permission checks, and the block editor scripts.
- This remains a read of the code. Nothing was run against a live site and no scanner was used.
