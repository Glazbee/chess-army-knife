# Handover: what is in PR 27, and what the next PR should do

Branch: `claude/wonderful-archimedes-07ikpe` (PR 27). The next PR should start from the default branch once this one is merged.

## How to work in this repository

- `composer install` then `vendor/bin/phpunit` runs the unit tests (about 600, 2 seconds).
- Integration tests need WordPress and a database and run only in GitHub CI (`composer test:integration`). Per `CLAUDE.md`, do not run them locally. CI found real bugs in this PR that unit tests could not (see "Lessons"), so read the CI logs before saying a change is done.
- PHPCS needs its installed paths set when run by hand:
  `P=$(ls -d vendor/wp-coding-standards/wpcs vendor/phpcsstandards/* vendor/phpcompatibility/* | xargs -I{} realpath {} | paste -sd,); vendor/bin/phpcs --runtime-set installed_paths "$P"` (and `phpcbf` the same way to fix).
- JS and SCSS: `npm ci`, `npm run format:js` (Prettier), `npm run build`. **`build/` is committed**, so rebuild and commit it with any change under `src/`.
- Do not bump the schema version (development mode, no live installs).

## What PR 27 contains

**Events**
- Events are no longer public pages. A page can be attached (choose one, or "Create a draft page"). An event links to its page only when that page is published.
- Events repeat weekly, monthly or annually, with a start date, optional stop date, start time and stop time. A repeating event is stored once; `Events::query()` works out the occurrences (`occurrence_starts()`).
- Event colour: its own colour, then a team's colour, then its first tag's colour (chosen on the tag, or picked from a palette by name).
- The month grid shows each event as a bubble (start time and title) that opens a panel. The agenda layout is unchanged. A key to the tag colours can be switched off.
- Event registration was removed completely (block, table, emails, portal list, privacy export).

**LMS**
- Import Events reads the LMS v2 API with an API key (Settings, or the Setup screen): seasons, then the event by name, then fixtures. Seasons and events are cached for 6 hours; results use the LMS cache setting.
- The v2 API gives no venue. A home fixture of our own team uses that team's venue, else the club venue (with its map link and what3words). An away fixture uses the venue of the club that hosts it, from the private **Clubs** directory. Import notes every team name it sees; **Sort Clubs** suggests which names are one club and asks where they play.
- Imported fixtures are tagged "League match" plus each of our teams' own **Calendar tag** (for example Lions).
- League Table and Team Carousel still use the old v1 API (see the backlog).

**Settings, Policies and Setup**
- Settings is grouped (Your club, ECF ratings, LMS, Membership, Accessibility, Advanced). New: club name, club venue, venue map link, venue what3words. Removed: LMS URL override, default event name, safeguarding and data contact fields.
- The retention period is set on the Policies screen only. Policies no longer show example wording on screen; the starting text appears only in the draft page, with `[add ...]` gaps.
- A **Setup** screen is offered once after activation (club name and venue, regular weekly events, ECF and LMS details).

**Tournament Games to Play**
- Shows one round at a time with Previous and Next links; `roundsPerPage` in the block (default 1, 0 for all).

## Lessons from this PR (read before the next one)

1. **Deleted code can hide in a large replace.** A slice-and-replace removed `Events_Display::title_html()` and broke the agenda and Next Club Event blocks. A cheap guard is a unit test that scans `includes/` and `src/` for every `Chess_Army_Knife_Class::method(` call and checks the method is defined. It is worth adding.
2. **`(array) ''` is `array('')`, not `array()`.** An event with no stored sides read as "away" and lost its venue. CI caught it. Use `array_filter` before testing array contents read from post meta.
3. **`get_post( 0 )` returns the page being viewed.** Never pass an unchecked meta value to `get_post()`.
4. **`Settings::sanitize()` starts from defaults.** Any option not submitted is reset, so a field moved off the Settings form must be preserved explicitly (the retention months are).
5. **The unit bootstrap has an explicit list of class files** (`tests/bootstrap.php`). Add a new class there if a unit test needs it.
6. WordPress global names (`$paged`, `$per_page`) trip PHPCS: use other names in `render.php` files.

## Decisions already made (do not re-ask)

- Tournament pagination is by **rounds**, not games.
- Players ask for a place in a team **in person**; there is no online request or note about teams. An admin then adds them.
- WhatsApp in the plugin is **records only** (consent, and who should be in each group); it never contacts WhatsApp.
- Squads are managed **only by admins in the admin panel**; captains who are not admins ask an admin. No captain-facing squad editing.
- A membership created in the admin panel is marked **junior** when its type is a junior one. A junior type does **not** offer "I am applying for my child"; the junior section simply appears for junior types.
- Clubs are a **private** directory; they only feed the location of events.
- Venues are entered by hand by the site admin (a Google Maps link and/or what3words), not looked up.
- Anything that depends on live behaviour I could not see must be **flagged "verify on your site"**, not reported as confirmed.

## Open questions

None at the moment.

## Backlog for the next PR, in the proposed order

Each step should be its own commit, with unit tests and (where it touches WordPress) integration tests.

### 1. Membership flow
- **Junior section only for junior types.** Add an "Is a junior membership" setting to the membership type (admin). The Membership Application Form shows the "Juniors (under 18)" section (junior tick box, date of birth, guardian details, contact permission) only when a junior type is selected, with scripted show/hide and a no-script fallback that shows everything. A member record created from a junior type is marked junior. Files: `src/membership-form/render.php` (the fieldset around line 117), `includes/class-memberships.php`, `includes/class-memberships-admin.php`, `includes/class-membership-form.php`.
- **Members do not pick their teams: DONE in PR 27.** The team ticks are gone from the Membership Form, Manage My Data and the Member Portal, and the `whatsapp_teams` column and everything that used it are removed. Squads are set by an admin only (Teams or Members screen); captains who are not admins ask an admin. Import Events adds the people who played for a team to its squad, matched by ECF code. A WhatsApp agreement now means "add me to the groups of the squads I am in".

### 2. Calendar
- "Add to my calendar" link on each event (a one-event `.ics`; the feed code is in `includes/class-events-feed.php`).
- A cancelled or moved flag on an event, shown in the block and the feed (`STATUS:CANCELLED`).
- Holiday skips for repeating events (dates to leave out).
- Next Club Event: show the next 3, or today and tomorrow.

### 3. Imports and set-up visibility
- A scheduled daily import (WP-Cron) with a last-run line on the Overview and on the import page (what changed, what failed). A bad API key should show as an Overview warning.
- An Overview panel listing what is not set up (no LMS key, no teams, no tournament chosen, policies not reviewed), each linking to the fix.

### 4. LMS v2 for the league blocks
- Move League Table and Team Carousel from the v1 API to v2 (`includes/class-lms-client.php` already has seasons, events and results). They are the two largest render files (309 and 382 lines) and mix logic and markup: extract the data work into a class so it can be unit tested, as `Events_Display` was.
- A team view that shows a team's results, table row and next fixture together, using the board-by-board results v2 provides.
- **Verify on your site:** I could not see real v2 responses. Check the field names against a real response before relying on them.

### 5. Block foundations
- Native styling supports (colour, spacing, typography, border) in every `block.json`; today they declare only `html` and `align`.
- Template support for the five blocks that have none: Member Portal, Membership Form, Manage My Data, Memberships, Team Profiles.
- Translatable editor strings: `wp_set_script_translations` is not called anywhere and there is no `languages/` folder.
- Editor experience: the editor renders every block with `ServerSideRender`, which may call the ECF or LMS APIs on a first load. Consider cached or sample data in the editor. **Verify on your site.**

### 6. Accessibility pass (do this last, so the final markup is audited once)
The target is WCAG AAA (`docs/accessibility-audit.md`; every earlier finding is done except a screen-reader pass). Not yet audited: the calendar bubbles and panel, tag colours and the key, round pagination, and the Setup, Clubs and Policies screens. Check contrast of the palette colours and the 14% tints in light, dark and high-contrast modes, keyboard use of the panel (open, Escape, focus returns), axe on the new markup, and update the audit document. **Verify on your site:** no screen-reader output has been seen.

### Other ideas (not committed to)
- Tournament: a "My games" view for a player (next opponent and colour), tie-break columns in standings, an expression of interest for upcoming tournaments, paging by round in the other tournament blocks.
- ECF blocks: "last refreshed" and a manual refresh for admins on Club Results and Biggest Gainers; a two-player Rating Chart.
- Memberships: a "your membership runs out on..." line and renewal link in the Member Portal.
- Setup: also ask for team details and offer to run the first import.
- Performance: `Events::query()` loads every published event and expands repeats in PHP, which is fine for hundreds. Cache month HTML if a club has thousands.

## Things that were not verified (flag these to the site owner)

- Nothing was run against a real WordPress page, a real LMS v2 response, or a screen reader.
- The calendar bubble layout was checked in a static browser mock-up only.
- The LMS v2 field names (`home_team`, `away_team`, `date`, `time`) come from the OpenAPI file, not a live response.
- The first-run redirect after activation and the weekly-event creation on the Setup screen are covered by tests but have not been tried by hand.
