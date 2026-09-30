=== Chess Army Knife ===
Contributors: yourclub
Tags: chess, ecf, ratings, league, blocks
Requires at least: 7.1.2
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gutenberg blocks for English chess clubs: ECF ratings and league data, club tournaments, club events and club memberships.

== Description ==

This plugin adds seventeen blocks to the WordPress block editor, pulling live data from:

* The [ECF Ratings API](https://rating.englishchess.org.uk/help/api) — England's official chess rating database.
* The [ECF League Management System (LMS) API](https://lms.englishchess.org.uk/lms/node/34) — used by most English chess leagues to run their divisions.

**Blocks included**

1. **ECF Rating Chart** — choose a club member and show how their rating has moved over their recent rated games, as a line chart, with current/peak/lowest/change stats. Works for standard, rapid, blitz, and their online equivalents.
2. **ECF Club Results** — a merged feed of recent rated results for the club's current members who have an ECF rating code (win/draw/loss, colour, event). Opponents are not shown.
3. **ECF League Standings & Matchups** — enter your league's LMS organisation ID and an exact event/division name to show the league table and/or recent and upcoming matchups. Includes an optional "highlight team" so your own club's row stands out.
4. **ECF Team Fixtures Carousel** — a rotating carousel showing each team's last result and next fixture. Teams are read automatically from the league table, or you can supply your own list.
5. **ECF Biggest Rating Gainers** — showcase the club's current members whose rating has risen the most over a recent period.

6. **ECF Featured Player** — spotlight a player with a photo, a short blurb on why they're featured, their ECF rating, and chess.com / Lichess profile links.
7. **Tournament Status** — where a tournament stands: status, format, players, current round (Swiss), games played and the winner.
8. **Tournament Games to Play** — the games in a tournament that still have no result, grouped by round. Administrators also get a pair of score selectors per game and a Save button.
9. **Tournament Standings** — a cross-table with each player's points in every round and their total, in rank order.
10. **Tournament Past Winners** — the winners of completed tournaments, most recent first.
11. **Tournament Players** — the players in a tournament with their ECF codes and ratings.
12. **Club Event Calendar** — upcoming club events by date, as a list or a month grid, optionally only those with chosen tags.
13. **Next Club Event** — the next club event, optionally only one with a chosen tag.
14. **Club Memberships** — advertise the memberships the club offers (junior, adult, senior or any others) with their prices and descriptions, and how to pay. Optionally link each one to your application form.
15. **Membership Application Form** — a form for people to apply for a membership. Applications wait for the club to review them.
O. **Manage My Data** — lets a member stop the newsletter or WhatsApp groups, ask for a copy of their details, or ask for them to be deleted, without an account. Changes are confirmed by an emailed link.

**Memberships**

Memberships are managed under **Memberships** in the admin menu:

* **Membership Types** are what you advertise: a name, description, price and length (12 months, or 0 for no expiry). Publish a type to offer it; keep it as a draft to hide it. Use the Order box to arrange them.
* **Members** lists everyone, with views for current members, pending applications, expired, and declined or cancelled. Approve or decline applications, and use **Add member** to enter someone who cannot use the online form. Record when a payment was received and how, and keep private notes.
* **How to pay** is entered once under **ECF & LMS → Settings**. The website never takes payments: members pay by bank transfer, cash or whatever you describe, and each member has a payment reference (such as MEM-12) to quote so you can match transfers.

Members' details are personal, so the Memberships menu is only for people with the "manage members" permission. It is **not** given to every administrator: whoever activates the plugin has it, and any administrator who can edit users can tick **Club memberships** on a user's profile to give it to (or take it from) someone else, who need not be an administrator. The application form is built around how a chess club uses data:

* **Running the club** is the basis for the required details (name, email, phone, ECF rating code): to run the membership and to provide playing members to the ECF, who are given each member's name and ECF rating code. Applicants tick to confirm they have read how their details are used, the time is recorded, and the form links to your privacy policy if the site has one.
* **Newsletter and WhatsApp groups** are covered by the same tick, which says so in plain words: joining the club is taken as agreeing to the club newsletter and to being added to the WhatsApp groups for the teams a member plays in. Each is recorded with its time, and a member can withdraw either at any time and still stay a member (see Manage My Data below, or untick them on the member's record). Note that consent bundled into joining is weaker than a separate tick box under GDPR and the email marketing rules, which is why withdrawing is easy and prominent.
* **Juniors (under 18)**: the form asks for the junior's date of birth and a parent or guardian's name, email and phone, and writes to the parent rather than the junior. The junior's own email and phone are only kept if the parent ticks that the club may contact the junior directly. Someone whose date of birth is under 18 is treated as a junior even if they do not tick the box. An adult's date of birth is not kept. The parent or guardian gives the consent for a junior.
* It also limits how often one visitor can apply.

**Every player is written down once**

The club's people table is the only place anyone's details are kept. Tournament entries are references to a person in it: a tournament holds no names or ECF codes of its own, so correcting a record corrects every tournament, and exporting or erasing a person covers their tournament entries too. (There is no separate Players page any more; add people on the Members screen or from the tournament screens.)

* **Guests.** People who are not members, such as tournament guests, are recorded as **Not a member**. They are kept in the same table but left out of the member lists and counts, appear under their own **Not members** view, and can still be chosen as tournament players and tagged in photos. Guests are deleted with the same retention period, counted from when they were last used. A player typed into the tournament screens is recorded this way automatically. A member's or guest's manual rating (for someone without an ECF code) is part of their record.
* **Typed ECF codes.** A block can still be given an ECF rating code by hand. Before anything is fetched from the ECF about that code, it is matched to the person's record (with or without the letter), or, if nobody has that code, the ECF is asked who it is and they are written down as a non-member. A code the ECF does not know records nobody.
* **Choosing players.** The player pickers in the block editor and on the Tournaments screen search the club's own people: current members and guests with an ECF code. The ECF's own player database is never searched.
* **Club rosters.** Club Results and Biggest Gainers use the club's own current members with an ECF code, not the ECF's club roster, so nobody appears there without a record. The club search, club picker and default club code have been removed. Opponents' names and ratings are no longer shown, because the club holds no record of them.
* **Deleting.** Deleting a person outright is only possible if nothing ties them to a payment, a photo or a tournament. Otherwise their personal details are removed and the rest is kept.

**Manage My Data**

The Manage My Data block needs no account. The member gives their email address (a junior's parent uses theirs) and chooses:

* **Change my newsletter and WhatsApp choices**: a private, one-time link is emailed to that address; it lets them untick (or tick) each choice for everyone under the address. Nobody can change anyone else's choices.
* **Send me a copy of my details** or **Delete my details**: these use WordPress's own personal data requests. The person confirms by email, then the request appears under **Tools → Export / Erase Personal Data**, where the club's exporter and eraser do the work.

The answer is the same whether or not the address is on file, and the number of emails one visitor or address can cause is limited. Put the block on a page such as your privacy policy.

**Photos**

Anyone who can manage members sees a **Members in this photo** checklist in a photo's details (in the upload dialog and on the Edit Media screen). Tick the members and guests who appear. Tags hold the member's id, not their name, and are only visible to people who manage members. Find a person's photos from the **Photos** link on the Members screen, the thumbnails on their record, or the Media Library's member filter, which works in both the grid and list views. Photo tags are included when a person's data is exported. Erasing a person does not delete photos, because they may show other people: their record is kept without personal details, so the tagged photos can still be found and reviewed by hand.

**Data protection (GDPR)**

* Members are covered by WordPress's own **Tools → Export Personal Data** and **Erase Personal Data**, found by email address. A junior is found by their parent or guardian's email address as well as their own. Exporting includes everything held, including your notes. Erasing deletes the record; if a payment was recorded on it, the record is kept for your accounts with all personal details removed.
* Records are deleted automatically after the period set under **ECF & LMS → Settings → Keep old membership records for** (24 months by default, 0 to keep everything): memberships that ended, and applications that were declined, cancelled or never approved. Current members are never removed.
* The **Club Data Policy** block shows the club's policy to anyone, and the same wording is offered under **Settings → Privacy → Policy Guide**. Set **Data protection contact** under **ECF & LMS → Settings** so people know who to ask. Add your own paragraphs with the `Chess_Army_Knife_data_policy_sections` filter. Review the wording so it matches what your club actually does; it is not legal advice.
* Collect only what you need: an adult's date of birth is never stored by the form. Deleting the plugin removes members only if you tick the delete-data option.

**Global defaults**

Set a default LMS organisation ID, event name and rating list once under **Settings → Chess Army Knife**. Any block field left blank will use that default automatically, and can still be overridden individually per block.

All API responses are cached in a dedicated database table (so cached data survives object-cache evictions on shared hosting) — durations are configurable under **Settings → Chess Army Knife**, where you can also switch back to plain WordPress transients if you prefer.

= A note on the LMS API =

The ECF itself describes the LMS API as "experimental," and its response fields aren't formally documented. The League Standings & Matchups and Team Fixtures Carousel blocks parse this data defensively. The League Standings block includes a "Show raw API data (debug)" toggle in its sidebar so you can see exactly what your league's LMS instance returns if a table or matchup doesn't look right.

The ECF's own API documentation page currently points requests at a legacy host (`ecflms.org.uk`) that no longer resolves for most networks. This plugin defaults to the live host (`lms.englishchess.org.uk`) instead, with an automatic fallback and a settings-page override in case the ECF changes it again.

== Installation ==

1. Upload the `chess-army-knife` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Visit **ECF & LMS → Settings** in the admin menu to (optionally) set a default LMS organisation ID, and **ECF & LMS → Club Teams** to list your club's teams across any divisions/organisations.
4. Add the blocks from the inserter — search for "ECF" or "LMS".

**Finding your LMS organisation ID**: go to your league's LMS homepage — the URL looks like `https://lms.englishchess.org.uk/lms/organisation/613`. The number at the end (`613`) is your organisation ID. The event/division name must match exactly what's shown in LMS (e.g. `Division 1`), including capitalisation and spacing.

== Frequently Asked Questions ==

= Why don't I see any data? =

Double-check the codes/names you entered. For the League Standings & Matchups block, turn on "Show raw API data (debug)" in the sidebar to see the League Management System's raw response and confirm the organisation ID and event name are correct.

= Does this slow my site down? =

All ECF/LMS lookups are cached (6 hours by default for ratings data, 30 minutes for league data), so only the first visitor after the cache expires triggers a live API call.

= Can I show more than one player/club/league on a page? =

Yes — add as many blocks as you like, each configured independently.

== Changelog ==

= Unreleased =
* Changed: everyone's details are held once, in the people table. Tournament entries reference a person instead of copying their name and ECF code, and the Players page and its separate profile table are gone. A manual rating is part of a person's record.
* Changed: no player is fetched from the ECF without a record. A typed ECF code is matched to a person or, if unknown, written down as a non-member once the ECF has confirmed who it is. Club Results and Biggest Gainers use the club's own current members; the ECF club roster, club search, club picker and default club code are removed, and Club Results no longer shows opponents.
* Added: Manage My Data block: withdraw newsletter or WhatsApp consent from an emailed link, or ask for a copy or deletion through WordPress's personal data requests. Joining the club now records the newsletter and WhatsApp agreement from the one tick, in plain words.
* Added: the Media Library member filter now works in grid view as well as list view.
* Added: club memberships. Membership Types (name, description, price, length), a Members screen with pending applications, current, expired and cancelled members, and manual adding, editing and payment recording. New Club Memberships and Membership Application Form blocks, and a payment instructions field under Settings.
* Added: data protection for members: personal data export and erase, recorded consent, automatic deletion of old records after a retention period, suggested privacy policy wording and a public Club Data Policy block.
* Added: the player pickers search the club's own members (and guests) instead of the ECF's player database.
* Added: people who are not members (such as tournament guests) can be recorded with a "Not a member" flag and are left out of member lists and counts.
* Added: tag members in photos from the Media Library, find a member's photos, and include them in personal data exports.
* Added: junior members (under 18) are handled through a parent or guardian, and the newsletter and WhatsApp groups are separate, recorded, optional consents.
* Added: a separate "manage members" permission for the Memberships screens, set per user on their profile rather than given to all administrators.
* Added: Tournament Standings block, a cross-table of each player's points in every round and their total.
* Changed: results are now entered in the Tournament Games to Play block. Everyone sees the games; administrators also get two score selectors per game (choosing 1 for one player gives the other 0, and ½ gives ½ to both) and one Save button at the top. Games are saved together, so a wrong score is not recorded by accident. Forfeits and corrections are still made on the Tournaments admin page.
* Removed: the Tournament Results Entry block, replaced by the above. Pages that used it need the Tournament Games to Play block instead.
* Changed: a tournament's own page now also includes the standings (except for a knockout), and lists the games to play before the players.
* Added: choosing players for a tournament is much quicker. A scrolling, filterable list of saved players lets you tick several at once; a search of the ECF list by name fills in the ECF code and saves the player as you go; players without a code can be added by hand. The same control is on the create-tournament form, so players can be added when the tournament is created. The Players page also searches the ECF list to fill in the name and code.
* Added: "Create a page for this tournament" on a tournament. It makes a draft page showing the tournament status, its players with their ratings, and the games still to play, for everyone at the event. New Tournament Players block for the list of players.
* Changed: the create-tournament form only shows the settings that apply to the chosen format (rounds and initial colour for Swiss; double round-robin, groups and the knockout stage for round-robin), and the players advancing setting only appears once the knockout stage is switched on.
* Changed: a manual player rating must now be 1300 or higher, and the Players page makes clear that the ECF code is preferred and the manual rating is only for players without one.
* Added: Swiss requested byes. A player who cannot play a round can be given a half-point or zero-point bye before that round is paired (Tournaments, Requested byes). They sit the round out and are left out of the pairing.
* Added: Swiss initial colour setting (white or black), the colour given to the top seed of the first pairing.
* Changed: Swiss Buchholz now treats byes, forfeits and rounds a player missed as a virtual opponent, as in the FIDE Tie-Break Regulations, instead of ignoring them.
* Added: Tournament Status, Tournament Games to Play and Tournament Past Winners blocks for showing tournaments on the site. All three support templates (styling only).
* Added: Swiss tournaments using the FIDE (Dutch) system, C.04.3 (2026 edition). Rounds are paired one at a time from the results so far, with the top half of each score group playing the bottom half, moved-down players, floaters, colour allocation, the bye and criteria C1-C21. Rankings use points, then Buchholz, Sonneborn-Berger and wins. A round can be paired again while it has no results, and a player who withdraws forfeits their unplayed game. Forfeit results (+/-) are available for Swiss and round-robin games.
* Fixed: pairing a Swiss round in a large field (roughly 30 players and above) could take minutes or never finish. Very large score groups are now searched with bounds that skip pairings which cannot be the best, and one round has a fixed amount of searching to do. In rounds up to about 40 players the result is the same as before; in the largest fields, if the search has to be cut short, the round is marked and a note is shown on the tournament page (the pairings are valid, but a few players may not get the colour they were due).
* Known limitation: the pairing engine was checked against an independent implementation on about 1,600 random rounds of up to 24 players and matched every one, and against the exact search on fields of up to 60 players.
* Added: Knockout tournaments. Standard seeding (in an 8-player draw: 1v8, 4v5, 2v7, 3v6, so the top two seeds can only meet in the final), with first-round byes for the top seeds when the field isn't a power of two. A drawn game creates a tie-break game (colours reversed) until one player wins; winners move on automatically, and withdrawn players forfeit their next game.
* Added: Round-robin groups with an optional knockout stage. Split players into groups (dealt out by seed), and send the top N from each group into a knockout that is created automatically when the last group game is played. Qualifiers are cross-seeded so group winners meet other groups' runners-up and players from the same group are kept apart for as long as possible.
* Added: Tournaments (ECF & LMS → Tournaments) and saved player profiles (ECF & LMS → Players). Phase 1 supports round-robin (Berger tables from the FIDE General Regulations, single or double round). Players with an ECF rating code have their rating fetched when the tournament starts and used for seeding; later rating changes don't affect seeding. Players without a code use a manual rating.
* Added: Tournament Results Entry block — an admin-only block for recording game results from the front end (visitors see nothing). Replaced later by the Tournament Games to Play block.
* Added: Settings option to also delete tournaments and players when the plugin is deleted. Off by default, so tournament history is kept.
* Changed: all blocks are now named `chess-army-knife/…` (previously `ecf-lms/…`), which also fixes block styles not applying. Existing pages using the old block names will need those blocks re-added.

= 0.0.1 =
* Added: Club Events (ECF & LMS → Club Events). Add club nights and events with a date, start (and optional end) time and location, free-form tags, and any number of attached tournaments and leagues. Several events can share a night or even a start time. Set a default location under Settings; events without their own location use it. Two blocks show them: **Next Club Event** and **Club Event Calendar** (upcoming events listed by date, several on one night together, or a month grid with previous/next arrows; on a phone the grid becomes a list of the days that have events). Both can be limited to events with chosen tags, for example only in-house events, and support templates. **ECF & LMS → Import Events** creates an event for each upcoming fixture of your Club Teams from the LMS, tagged "League match" and linked to its league. Importing again never duplicates events; one you have edited by hand or trashed is left alone. If the LMS gives no start time, the usual kick-off time from Settings is used.
* Changed: version numbering reset to 0.0.1 ahead of a first stable 1.0 release. Earlier 1.x entries below are historical.
* Changed: minimum WordPress version is now 7.1.2.
* Fixed: cache class file renamed to match the plugin's rename (`class-chess-army-knife-cache.php`); the plugin previously required a file that didn't exist.
* Added: PHPUnit test suite (run `composer install && composer test`).

= 1.5.0 =
* Added: ECF Featured Player block — spotlight a player with an optional photo, a blurb on why they're featured, their current ECF rating and club (when an ECF code is given), and links to their chess.com and/or Lichess profiles. Works for players without an ECF code too. Supports templates.

= 1.4.0 =
* Added: Templates (ECF & LMS → Templates). Reusable presets per block type covering settings (rating list, days back, matchups shown, show match location, show event column...) and appearance (accent, background and text colours, corner radius, custom CSS). Choose a template in any block's sidebar; template values override the block, so editing a template updates every block using it.
* Added: "Show match location / venue" option for League Standings and the Team Carousel (displays only when the LMS supplies a venue).
* Added: "Show event column" (Club Results) and "Show from → to detail" (Biggest Gainers) options.

= 1.3.1 =
* Fixed: an `{"error": ...}` reply from the LMS was treated as valid league data and cached for the full cache period. It is now reported as an error and only cached briefly; entries cached by earlier versions are ignored.
* Fixed: saving the Settings page could discard team entries from the old free-text field before they were migrated to Club Teams.
* Added: League Standings & Matchups automatically highlights any team listed under Club Teams for that organisation and division.
* Added: uninstall now removes the Club Teams list and drops the cache table.

= 1.3.0 =
* Fixed: LMS requests failing with `{"error":"invalid type table.json"}` — the service treats everything after `league/` as a literal resource type, so the URL must use the bare type name (`table`, `match`, `event`, `club`) with no `.json` suffix.
* Added: a dedicated top-level "ECF & LMS" admin menu, replacing the single page under Settings.
* Added: a proper "Club Teams" admin page (add/remove rows in a table) replacing the old free-text "org | event | team" per-line field. Existing free-text entries are migrated automatically the first time this page loads.

= 1.2.1 =
* Fixed: LMS requests failing with HTTP 415 (Unsupported Media Type) on POST — this LMS instance (Drupal 10) requires a JSON request body with `Content-Type: application/json`, not WordPress's default form-urlencoded POST. The client now tries POST+JSON first, then POST+form, then GET, stopping at the first accepted shape.

= 1.2.0 =
* Fixed: "Biggest Rating Gainers" no longer shows players whose rating fell or stayed flat.
* Fixed: LMS requests failing with HTTP 405 — the LMS API expects POST, not GET; the client now tries POST first with an automatic GET fallback.
* Fixed: the debug panel on League Standings now shows the actual HTTP status/response body on failure, not just a blank panel.
* Added: "Your club's teams" global list (org | event | team, one per line) so a club with teams across several divisions — and sometimes more than one team per division — only has to maintain the list once.
* Added: ECF Team Fixtures Carousel can now read directly from "Your club's teams", spanning any number of organisations/events with one match.json call per division.
* Added: ECF League Standings & Matchups now accepts multiple event/division names (one per line) under the same organisation, each getting its own table and matchups section.
* Added: match-day fast cache — automatically drops LMS cache duration to 5 minutes from 30 minutes before a configurable kick-off time until midnight, then reverts automatically.
* Added: an admin-only "Last refreshed X ago · Refresh now" control on every block's output, so a logged-in admin can force a fresh fetch for just that block without clearing the whole cache.

= 1.1.0 =
* Fixed: rating chart wasn't rendering in the block editor preview (only worked on the live site).
* Fixed: LMS requests failing with "Could not resolve host: ecflms.org.uk" — switched to the live lms.englishchess.org.uk host, with fallback and a settings override.
* Added: global default settings (club code, org ID, event name, rating list) that any block can inherit or override.
* Added: persistent local-database cache option (instead of transients only).
* Added: ECF Team Fixtures Carousel block.
* Added: ECF Biggest Rating Gainers block.

= 1.0.0 =
* Initial release: Rating Chart, Club Results, and League Standings & Matchups blocks.
