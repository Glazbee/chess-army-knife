# Architecture: what is custom, and why

This plugin does a lot that is not stock WordPress. This note says what is custom, what it
replaces or sits beside, and why, so a change does not drift into reinventing something
WordPress already does. It describes the code as read in October 2026.

## Rule of thumb

Use the WordPress API when it does the job. Build something custom only where WordPress has no
equivalent, or where the equivalent would store the data somewhere unsuitable. When adding
anything new, check the lists below first.

## Where WordPress does the work

| Need | WordPress API used |
|---|---|
| Teams, clubs, events, announcements, team groups, membership types | Custom post types (events also use a taxonomy) |
| Who may do what | Capabilities (`add_cap`, `user_has_cap`), set on the user's profile under Users |
| Background jobs | WP-Cron (`wp_schedule_event`, `wp_schedule_single_event`) |
| Email | `wp_mail`, so SMTP plugins work |
| Remote APIs | `wp_remote_get` and `wp_remote_request`, with failures as `WP_Error` |
| Export and erasure of personal data | `wp_privacy_personal_data_exporters` and `_erasers`; "Manage my data" uses WordPress's own personal data requests |
| Settings | The options API |
| Policies | Ordinary pages |
| Public content | Blocks, registered from `src/` and built to `build/` |
| Short-lived tokens and counters | Transients |

## What is custom

### Own database tables

| Table | Holds | Why not posts or users |
|---|---|---|
| members | Applications, members, juniors, guests | Relational data with consents, dates and guardians. Many records are not people who should have a login. Personal data stays out of `wp_users` and usermeta, where other plugins list and export it |
| officers | Who held which position, and when | Dated history, linked to members |
| notification preferences | What each person agreed to be emailed about | Per person and category |
| member history | Each period of membership | Query by person and date |
| mail | Queue and send log | See Mailer |
| teams selection (availability, line-ups) | Availability replies and picked sides | Per fixture and player |
| tournaments, entrants, games | Tournament data | Relational, queried by round and player |
| cache | Remote API responses | See Cache |

Members are **not** WordPress users, on purpose. Officers and captains are: they are real
users with capabilities, and the officers table links them to members.

### Cache (`class-chess-army-knife-cache.php`)

Every remote call goes through it. It stores responses in its own table by default, because the
ECF API has a limit on processing time per day and a page visit must not trigger a new call while a
cached copy is valid. The settings page can switch it to transients. It also:

- holds a short transient lock so a crowd arriving as an entry expires causes one fetch (the lock
  is not strictly exclusive; it only stops a stampede),
- caches failures briefly (and longer when the service says it is being asked too often).

### Mailer (`class-mailer.php`)

A queue in front of `wp_mail`. It checks consent when a message is queued and again when it is
sent, sends in small batches from cron, retries, adds an unsubscribe link, and keeps only the
person's id (never the address), so erasing a person leaves nothing to find.

### Member portal and "Manage my data"

A member without an account sees and corrects their own details through an emailed private link.
This is deliberate: it avoids passwords, resets and a login system for people who want to change a
phone number once a year. Design points to keep:

- Sessions are transients keyed by a random token, limited to a short time and an absolute maximum.
- Every change is a POST with a nonce and the session token, and only records under the session's
  address can be touched.
- The answer never says whether an address is on file. A changed email address takes effect only
  when the new address confirms it, and the old address is told.
- See `docs/security-review-portal-2026-10.md` for the findings and what to tighten.

### Secrets (`class-secrets.php`)

The LMS API key is encrypted in the options table with libsodium, using a key made from the
site's salts, so it is not readable in a database dump. It can instead be set in `wp-config.php`.

### Domain logic with no WordPress equivalent

`class-swiss-dutch.php` (FIDE Dutch pairing), `class-matching.php` (blossom matching used by
it), `class-berger.php` (round-robin tables), `class-bracket.php`, `class-standings.php`,
`class-rating-chart.php`. These are chess rules rather than WordPress concerns. They are the
largest custom surface (the Swiss engine alone is about 2,100 lines), and are covered by unit tests.
Change them carefully and keep the tests passing.

## Things to avoid

- Do not move members into `wp_users`. If members-only content is wanted later, add a nullable
  link from a member to a user instead, and let WordPress own authentication.
- Do not store tokens, secrets or personal data in options, transients or logs in readable form
  if a hash or the person's id will do.
- Do not add a new remote call that does not go through the cache.
- Do not add a custom table where a post type with meta would do; do add one when the data is
  relational, personal, or queried by date.
