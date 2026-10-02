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

This plugin adds sixteen blocks to the WordPress block editor, pulling live data from:

* The [ECF Ratings API](https://rating.englishchess.org.uk/help/api) — England's official chess rating database.
* The [ECF League Management System (LMS) API](https://lms.englishchess.org.uk/lms/node/34) — used by most English chess leagues to run their divisions.

**Blocks included**

Blocks are grouped in the inserter under five headings: **Chess: Ratings & Players**, **Chess: Leagues & Teams**, **Chess: Tournaments**, **Chess: Events** and **Chess: Membership**. **Chess Army Knife → Block Help** explains how to add each block, what to choose in its settings and what to set up first; everyone who can open the admin area can read it.

1. **ECF Rating Chart** — choose a club member and show how their rating has moved over their recent rated games, as a line chart, with current/peak/lowest/change stats. Works for standard, rapid, blitz, and their online equivalents.
2. **ECF Club Results** — a merged feed of recent rated results for the club's current members who have an ECF rating code (win/draw/loss, colour, event). Opponents are not shown.
3. **ECF League Standings & Matchups** — enter your league's LMS organisation ID and an exact event/division name to show the league table and/or recent and upcoming matchups. Includes an optional "highlight team" so your own club's row stands out.
4. **ECF Team Fixtures** — every team with its last result and next fixture. Show it as a list (the default: nothing moves and it works without scripts) or as a carousel with buttons, a button for each team and a pause button. Teams are read automatically from the league table, or you can supply your own list.
5. **ECF Biggest Rating Gainers** — showcase the club's current members whose rating has risen the most over a recent period.

6. **ECF Featured Player** — spotlight a player with a photo, a short blurb on why they're featured, their ECF rating, and chess.com / Lichess profile links.
7. **Tournament Status** — where a tournament stands: status, format, players, current round (Swiss), games played and the winner.
8. **Tournament Games to Play** — the games in a tournament that still have no result, grouped by round. Administrators also get a pair of score selectors per game and a Save button.
9. **Tournament Standings** — a cross-table with each player's points in every round and their total, in rank order.
10. **Tournament Past Winners** — the winners of completed tournaments, most recent first.
11. **Tournament Players** — the players in a tournament with their ECF codes and ratings.
12. **Club Event Calendar** — upcoming club events by date, as a list or a month grid where each event is a coloured bubble (start time and title) that opens its details, optionally only those with chosen tags.
13. **Next Club Event** — the next club event, the next three, or today's and tomorrow's, optionally only those with a chosen tag.
14. **Club Memberships** — advertise the memberships the club offers (junior, adult, senior or any others) with their prices and descriptions, and how to pay. Optionally link each one to your application form.
15. **Membership Application Form** — a form for people to apply for a membership. Applications wait for the club to review them.
O. **Manage My Data** — lets a member stop the newsletter or WhatsApp groups, ask for a copy of their details, or ask for them to be deleted, without an account. Changes are confirmed by an emailed link.
P. **Club Teams** — shows the club's teams: name, description, home venue and leagues (never the captain or squad). Show all teams or pick one.
Q. **Member Portal** — lets members see and correct their own details, choose their emails, change their email address and delete their data, using an emailed link.

**Memberships**

Memberships are managed under **Memberships** in the admin menu:

* **Membership Types** are what you advertise: a name, description, price and length (12 months, or 0 for no expiry). Publish a type to offer it; keep it as a draft to hide it. Use the Order box to arrange them.
* **Members** lists everyone, with views for current members, pending applications, expired, and declined or cancelled. Approve or decline applications, and use **Add member** to enter someone who cannot use the online form. Record when a payment was received and how, and keep private notes.
* **Bulk actions** on the Members screen work on the members you tick: change their membership type (their dates are left as they are), add them to a team or take them out of one, or export them to CSV. A member can be in any number of teams, or none; set them from a member's record too. The CSV has contact details, ECF codes and ratings, membership dates, payment, teams and email choices (private notes are left out) and can be downloaded only by someone with the membership permission.
* **Member Checks** (Chess Army Knife → Member Checks) lists the records to tidy: current members **missing an ECF code**, **invalid ECF codes** (codes that do not look like one are found at once; **Check with the ECF** then asks the ECF about ten codes at a time, each answer kept for six hours, to stay within its daily allowance), **incomplete applications** (no email, membership type or consent, or a junior without a date of birth or parent's details), **expired memberships** (with Renew) and **possible duplicates** (the same ECF code, the same email address, or the same name unless their dates of birth or codes differ; a parent's email is ignored because siblings share it). Each list can be ticked and exported to CSV. The pattern for a valid code can be changed with the `Chess_Army_Knife_ecf_code_pattern` filter.
* **Membership history** is shown at the bottom of a member's record: when they first became a member, how long they have been a member, where their membership lapsed, and each period with its dates and payment. A period is noted whenever a member is approved, renewed or saved as active. Someone who joined before this existed shows their current dates as one period. It is included in their data export and removed when they are erased.
* **How to pay** is entered once under **Chess Army Knife → Settings**. The website never takes payments: members pay by bank transfer, cash or whatever you describe, and each member has a payment reference (such as MEM-12) to quote so you can match transfers.

Members' details are personal, so the Memberships menu is only for people with the "manage members" permission. It is **not** given to every administrator: whoever activates the plugin has it, and any administrator who can edit users can tick **Club memberships** on a user's profile to give it to (or take it from) someone else, who need not be an administrator. The application form is built around how a chess club uses data:

* **Running the club** is the basis for the required details (name, email, phone, ECF rating code): to run the membership and to provide playing members to the ECF, who are given each member's name and ECF rating code. Applicants tick to confirm they have read how their details are used, the time is recorded, and the form links to your privacy policy if the site has one.
* **Fixtures calendar**: add your LMS API key under Settings, then run **Import Events** (set each team's venue under Teams, and use **Sort Clubs** for other clubs' venues) (Chess Army Knife → Import Events) after adding each team's league entries so fixtures are linked to them. The Club Event Calendar block then offers a team filter, home or away, team names and colours, and a calendar subscription link (the feed is at `/?chess_army_ics=1`, with optional `teams`, `venue` and `tags`). The feed holds only what the public calendar shows.
* **Member portal**: put the **Member Portal** block on a members' page. Members ask for a link by email; the portal lasts one hour (filter `Chess_Army_Knife_portal_session_seconds`). The page says when the session ends, warns 15 minutes before, and has a **Keep me signed in** button; saving anything also starts the hour again. Membership, dates of birth and payments stay the officers' to change.
* **Announcements** (Chess Army Knife → Announcements): officers with the membership permission write and send them; nothing is emailed until "Send" is ticked and the announcement saved. Members see the ones sent to them in the Member Portal.
* **Team selection**: set each team's boards and its captain's website login under Teams (the login needs the power to promote users). Captains use **Team Selection** in the Chess Army Knife menu and see only their own team's squad. Fixtures come from Import Events, linked to teams. Players are asked by email (category "Fixture and availability requests", which they can turn off) and reply from a link.
* **Teams** (Chess Army Knife → Teams) hold each team's description, home venue, captain, squad and **league entries**: the LMS organisation ID and event or division the team plays in, one row for each season, with an optional "name in the LMS" if it differs from the team's name. Import Events, the fixtures carousel and league table highlighting all read these entries. Only the name, description, venue and leagues are public (Club Teams block); the captain and squad are for officers. Members choose their WhatsApp groups from these teams. The old **Chess Army Knife → Club Teams** list has been merged into this: on first load each entry moves onto the team it belongs to (a team is created for any name that has none).
* **Who can do what with teams.** Editing teams, their league entries, Import Events and Team Selection for every team needs the **Club teams** permission, ticked on a user's profile by someone who can promote users. It shows nothing about members beyond the names in each team's squad. Choosing who is in a squad or captains it needs the membership permission, which also includes the team permission. A team's captain, with a website login set on the team, needs neither: they get Team Selection for their own team only.
* **Dashboard** (Chess Army Knife → Dashboard) summarises the club at a glance using totals from the people table; it shows no names.
* **Renewal reminders** are emailed by a daily job to current members, at the days you set (default 30, 7 and 0 days before the last day of membership and 7 days after). They are service messages, so they do not need the newsletter opt-in, but every email has a link to stop them, and a junior's goes to their parent or guardian. Turn them on and word them under **Chess Army Knife → Settings**; **Chess Army Knife → Renewals** shows who is due and sends a test. Use **Renew** on the Members screen when someone pays.
* **Newsletter and WhatsApp groups** are two separate, optional, unticked choices on the form, each recorded with its time, because neither is needed to run the membership and a WhatsApp group shows a member's number to the rest of the group. Members never choose their teams: the club puts them in squads (an admin on the Teams or Members screen, or automatically when a fixture's board results show they played for a team), and a member who agreed to WhatsApp is added to the groups of the teams they are in (WhatsApp needs a phone number). A member can withdraw either at any time and still stay a member (see Manage My Data below, or untick them on the member's record). The one required tick only confirms they have read how their details are used.
* **Juniors (under 18)**: the form asks for the junior's date of birth and a parent or guardian's name, email and phone, and writes to the parent rather than the junior. The junior's own email and phone are only kept if the parent ticks that the club may contact the junior directly. Someone whose date of birth is under 18 is treated as a junior even if they do not tick the box. An adult's date of birth is not kept. The parent or guardian gives the consent for a junior.
* It also limits how often one visitor can apply.

**Every player is written down once**

The club's people table is the only place anyone's details are kept. Tournament entries are references to a person in it: a tournament holds no names or ECF codes of its own, so correcting a record corrects every tournament, and exporting or erasing a person covers their tournament entries too. (There is no separate Players page any more; add people on the Members screen or from the tournament screens.)

* **Guests.** People who are not members, such as tournament guests, are recorded as **Not a member**. They are kept in the same table but left out of the member lists and counts, appear under their own **Not members** view, and can still be chosen as tournament players and tagged in photos. Guests are deleted with the same retention period, counted from when they were last used. A player typed into the tournament screens is recorded this way automatically. A member's or guest's manual rating (for someone without an ECF code) is part of their record.
* **Typed ECF codes.** A block can still be given an ECF rating code by hand. Before anything is fetched from the ECF about that code, it is matched to the person's record (with or without the letter), or, if nobody has that code, the ECF is asked who it is and they are written down as a non-member. A code the ECF does not know records nobody.
* **Ratings stay up to date.** An hourly background job refreshes the ECF rating of every current member who has an ECF code, once per cache period (set under **Chess Army Knife → Settings**, 6 hours by default), using the default rating list. Enter your club's **ECF club code** (for example `4USL`) under **Chess Army Knife → Settings** and one request to the ECF's club list refreshes every member at once, instead of one request per member. The list also contains people the club holds no record of, so it is never cached and only the ratings of people already on your records are kept; the rest is dropped immediately. Anyone the list does not cover (and everyone, if no club code is set) is checked individually, least recently checked first, a small batch at a time (20 by default, change it with the `Chess_Army_Knife_rating_refresh_batch` filter) so the ECF's daily processing limit is respected. The club list only has standard, rapid and blitz ratings, so an online rating list is checked individually. The latest rating is kept on each member's record and shown on the Members screen with how long ago it was checked, and the cache the blocks read is refilled, so visitors never wait for the ECF. A code the ECF rejects is noted and not retried until the next period, and a run stops early if the ECF keeps failing. **Refresh ECF ratings** on the Members screen runs a refresh straight away. Guests are not refreshed in the background; their rating is fetched when a tournament starts.
* **Choosing players.** The player pickers in the block editor and on the Tournaments screen search the club's own people: current members and guests with an ECF code. The ECF's own player database is never searched.
* **Club rosters.** Club Results and Biggest Gainers use the club's own current members with an ECF code, not the ECF's club roster, so nobody appears there without a record. The club search, club picker and default club code have been removed. Opponents' names and ratings are no longer shown, because the club holds no record of them.
* **Deleting.** Deleting a person outright is only possible if nothing ties them to a payment, a photo or a tournament. Otherwise their personal details are removed and the rest is kept.

**Manage My Data**

The Manage My Data block needs no account. The member gives their email address (a junior's parent uses theirs) and chooses:

* **Change my newsletter and WhatsApp choices**: a private, one-time link is emailed to that address; it lets them untick (or tick) the newsletter and WhatsApp choices for everyone under the address. Nobody can change anyone else's choices.
* **Send me a copy of my details** or **Delete my details**: these use WordPress's own personal data requests. The person confirms by email, then the request appears under **Tools → Export / Erase Personal Data**, where the club's exporter and eraser do the work.

The answer is the same whether or not the address is on file, and the number of emails one visitor or address can cause is limited. Put the block on a page such as your privacy policy.

**Photos**

Anyone who can manage members sees a **Members in this photo** checklist in a photo's details (in the upload dialog and on the Edit Media screen). Tick the members and guests who appear. Tags hold the member's id, not their name, and are only visible to people who manage members. Find a person's photos from the **Photos** link on the Members screen, the thumbnails on their record, or the Media Library's member filter, which works in both the grid and list views. Photo tags are included when a person's data is exported. Erasing a person does not delete photos, because they may show other people: their record is kept without personal details, so the tagged photos can still be found and reviewed by hand.

**Data protection (GDPR)**

* Members are covered by WordPress's own **Tools → Export Personal Data** and **Erase Personal Data**, found by email address. A junior is found by their parent or guardian's email address as well as their own. Exporting includes everything held, including your notes. Erasing deletes the record; if a payment was recorded on it, the record is kept for your accounts with all personal details removed.
* Records are deleted automatically after the period set under **Chess Army Knife → Settings → Keep old membership records for** (24 months by default, 0 to keep everything): memberships that ended, and applications that were declined, cancelled or never approved. Current members are never removed.
* **Policies** (Chess Army Knife → Policies): a club's policies are specific to that club, so the plugin makes nothing until you ask. For the **club data policy**, the **safeguarding policy** and the **privacy policy** you can have a **draft page** made, or say you will write the policy yourself (you can change your mind). Details the draft needs that the plugin cannot know (who to contact, who your safeguarding officer is) are left as gaps in square brackets for you to fill in on the page. How long you keep details after someone stops being a member is set at the top of this screen. The Overview reminds you until each one is set up. A page is an ordinary page: edit it in the block editor, publish it, put it in a menu or the footer, and link to it from anywhere on the site. The starting text is general and is not legal advice, so a policy is **not finished until someone at the club has read it, changed it to match what the club really does, and marked it as reviewed**; until then the Policies screen and the page's own editor say so. **Put the starting text back** replaces a page's text (the earlier text stays in the page's revisions) and asks for another review. If the site already has a privacy policy page, that page is used and never rewritten; otherwise the new one becomes the site's privacy page. Add your own paragraphs with the `Chess_Army_Knife_data_policy_sections` and `Chess_Army_Knife_safeguarding_sections` filters. Deleting the plugin leaves the pages in place.
* Collect only what you need: an adult's date of birth is never stored by the form. Deleting the plugin removes members only if you tick the delete-data option.

**Accessibility**

The blocks aim to meet WCAG 2.2 Level AAA, and the audit and its progress are in `docs/accessibility-audit.md`.

* **Headings.** Each block's title is a real heading, so people using a screen reader can move around the page. Under **Chess Army Knife → Settings → Accessibility** choose the level that fits under your page title (H2 is right when the page title is an H1). Headings inside a block go one level lower.
* **High contrast.** Under the same settings choose **Follow the visitor's device** (the default: used for people who have asked their device for more contrast), **Always on** or **Off**. It shows the plugin's blocks in black on white with underlined links and solid borders. It covers the plugin's blocks only; the rest of your theme is the theme's responsibility.
* **Colours.** The blocks use your theme's text colour, so their contrast is your theme's. A template that sets its own colours is refused unless the text and background have a contrast of at least 7:1 and the accent colour at least 3:1 against the background.
* **Nothing moves unless you ask.** The fixtures block shows every team at once. If you choose the carousel layout it has previous, next, a button for each team and a pause button; it moves on by itself only if you turn that on, never for visitors whose device asks for less motion, and for good once a visitor uses a button. The rating chart is a picture drawn on the server, with a written summary and a table of the ratings.
* **Forms.** After a mistake the form comes back with what was typed, a message that takes focus and links to the field, and required fields are marked in words.

**Global defaults**

Set a default LMS organisation ID, event name and rating list once under **Settings → Chess Army Knife**. Any block field left blank will use that default automatically, and can still be overridden individually per block.

All API responses are cached in a dedicated database table (so cached data survives object-cache evictions on shared hosting) — durations are configurable under **Settings → Chess Army Knife**, where you can also switch back to plain WordPress transients if you prefer.

= A note on the LMS API =

The ECF itself describes the LMS API as "experimental," and its response fields aren't formally documented. The League Standings & Matchups and Team Fixtures Carousel blocks parse this data defensively. The League Standings block includes a "Show raw API data (debug)" toggle in its sidebar so you can see exactly what your league's LMS instance returns if a table or matchup doesn't look right.

The ECF's own API documentation page currently points requests at a legacy host (`ecflms.org.uk`) that no longer resolves for most networks. This plugin defaults to the live host (`lms.englishchess.org.uk`) instead, with an automatic fallback and a settings-page override in case the ECF changes it again.

== Installation ==

1. Upload the `chess-army-knife` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Visit **Chess Army Knife → Settings** in the admin menu to (optionally) set a default LMS organisation ID, and add your club's teams, with the leagues they play in, under **Chess Army Knife → Teams**.
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
* Added: deleting people's details from the admin panel. On the Members list, tick people and choose **Delete personal details…** (with a confirm tick) to delete a group at once, or use **Delete** or **Delete and do not record again** on a single member. A record tied to a payment or photos is kept without any personal details. A person's name stays on the results of tournaments already started, as a historical record, but is unlinked from any record, so nothing leads from it to their other games; in a tournament that has not started their entry is simply removed.
* Added: an **Add to my calendar** link on each event (in the calendar panel, the list and Next Club Event) that downloads that one event as an .ics file.
* Added: an event can be marked **Cancelled** or **Moved**, with a short note. It stays on the calendar, marked in words (and a line through a cancelled title), and the calendar feed marks a cancelled one `STATUS:CANCELLED`. A cancelled event has no Add to my calendar link.
* Added: **Dates to skip** on a repeating event, such as holidays. Those dates are left out of the blocks and the feed.
* Added: the Next Club Event block can show the next event, the next three, or today and tomorrow.
* Added: **Do Not Record** (Chess Army Knife → Do Not Record). A person who asked to be deleted is not recorded again by the plugin by itself: not when their ECF rating code is used in a tournament entry, an ECF lookup or an import, and not by name when no code is given. The list holds only one-way fingerprints of the ECF code and name (no codes or names), so keeping it does not keep their details. Someone on the list who plays in a tournament is entered by name only (their ECF code is not kept). People who delete their own details, or ask for erasure through WordPress, are added automatically. An admin who adds someone by hand, or a person who applies again, is never blocked. A name can stop a different person with the same name, so give an ECF code when entering them.
* Changed: members no longer choose their teams anywhere (application form, Manage My Data, Member Portal). Squads are set by an admin on the Teams or Members screen, and **Import Events now adds the people who played for each of your teams to its squad**, matched to member records by ECF code (a player in two teams is in both; nobody is ever removed by an import, and guests and players with no matching record are only counted). A member who agreed to WhatsApp is added to the groups of the squads they are in. Captains who are not admins ask an admin to change a squad. The WhatsApp list on an announcement is now the agreed members of the chosen teams' squads.
* Added: the Tournament Games to Play block shows one round at a time (set how many rounds a page in the block, or 0 for all), with Previous and Next links, so a big tournament does not list every game at once.
* Added: a **Calendar tag** on each team (for example Lions). Imported fixtures are still tagged "League match" and also get the tag of each of your teams in them, so a calendar block can show only one team's matches (Filter by tag) and the key lists the teams. Importing again adds a tag you give a team later to its existing fixtures; tags added by hand are never removed.
* Added: venues for imported fixtures. The LMS gives no locations, so a home fixture of your own team is held at that team's venue (or the club venue), and an away fixture at the venue of the club hosting it. **Clubs** (Chess Army Knife → Clubs) is a private directory of other clubs: each has a venue, an optional Google Maps link and what3words address, and the team names it plays under. Every import notes the team names it sees, and **Sort Clubs** suggests which belong to one club (for example Stroud Otters and Stroud Badgers) and asks where they play; import again to fill the venues in. An event you edited by hand keeps its own location. The club venue in Settings, each event and each club can have a map link and what3words address, shown beside the location.
* Fixed: the list ("agenda") layout of the Club Event Calendar and the Next Club Event block failed to load, and events without a page of their own linked to the page being viewed.
* Added: event colours follow the event's tag, so no event is grey. Each tag has a colour (Club Events > Event tags, or one is chosen for it), and the Club Event Calendar shows a key to the colours that you can switch off in the block's settings. An event's own colour, then a team's, still come first.
* Added: Setup (Chess Army Knife → Setup), offered once after the plugin is turned on. It asks for the club's name, venue (with an optional map link and what3words address), lets you tick the regular weekly events (club night, coaching, competitive games) to add to the calendar, and asks for your ECF club code and LMS details.
* Changed: Settings is simpler and grouped under Your club, ECF ratings, LMS and Membership. New: **Club name**, used in emails and policies; **Club venue** replaces "Default event location". Removed: the LMS API address override, the default event/division name (teams carry their own leagues), and the safeguarding and data protection contact fields (fill those in on the policy page). "Days to look back" and "Members to check" now sit together under ECF ratings.
* Changed: how long old membership records are kept is now set on the Policies screen only.
* Changed: policy pages no longer show example wording on the Policies screen; the starting text appears only in the draft page that is made, with gaps in square brackets to fill in.
* Changed: the month grid of the Club Event Calendar shows each event as a coloured bubble with its start time and title. Selecting it opens a panel with the date, time, location, team, tags, links and a link to the event's page. An event can have its own colour (Colour on the event screen), otherwise a team's colour is used.
* Removed: event registration (the Event Registration block, "Take registrations" on events, and the places and waiting list). Events are for club nights, coaching, games and tournaments; link a tournament to its event by attaching it on the event screen.
* Changed: club events no longer have a page of their own. An event can have a page attached (choose one, or tick "Create a draft page for this event"); the calendar and Next Club Event block link to it once it is published.
* Added: events can repeat every week, month or year, with a start date, an optional stop date, a start time and an optional stop time. A repeating event is entered once and shown on each of its dates, in the blocks and the calendar feed.
* Changed: Import Events now reads the LMS version 2 API, which needs an API key (Settings → League Management System → LMS API key; create it on your LMS account's API keys page). It finds each team's division in the organisation's active season by name. The new API gives no venue, so imported events use the default location. The league table and fixtures blocks still use the earlier LMS API.
* Changed: the Club Data Policy block is now a page, and there are two more: a safeguarding policy and a privacy policy. You choose which to use under Chess Army Knife → Policies; each is made as an editable draft page from a general example, and stays "not finished" until you mark it as reviewed.
* Removed: the Club Data Policy block.
* Changed: the blocks were reworked to meet WCAG 2.2 AAA (see `docs/accessibility-audit.md`): real headings, table captions and row headers, abbreviations explained, spoken scores, full dates in the calendar, a list of days on phones, keyboard-scrollable tables, visible focus, 44px targets, no text colour of the plugin's own, and high contrast support.
* Changed: the rating chart is now a server-drawn SVG with a summary and a table. Chart.js is no longer used, and the chart's line colour follows the text colour unless a chosen colour is easy to see.
* Changed: the Team Fixtures block shows every team as a list by default. The carousel is now an option (Layout), built to be usable with a keyboard and a screen reader, with a pause button, and it moves on by itself only if turned on.
* Changed: the member portal session lasts one hour (was two), with a warning 15 minutes before the end and a button to extend it.
* Added: forms keep what was typed after an error, and the message names the field.
* Added: Settings → Accessibility for the heading level and high contrast. Templates refuse colours that are hard to read.
* Added: announcements (Memberships → Announcements). Write a message, choose who gets it (all current members, the squads of chosen teams, or juniors, who are reached through their parents or guardians), choose whether it is the newsletter (opt-in only) or a club announcement (members can turn these off), and send it now or at a chosen time. It is emailed in batches through the mailer, once to each address, and what was sent and to how many is kept on the announcement. There is no approval step. For a team audience the screen lists who agreed to the team's WhatsApp group, for sending the same message there by hand. Members read past announcements in the Member Portal. New email choice "Club announcements".
* Added: team selection. On a team's page an officer who can promote users sets the captain's website login, which gives that user the **Team Selection** screen for that team only (no access to members or settings). For each league fixture the captain asks the squad whether they can play; players reply yes, maybe or no from an emailed link with no account. The captain builds the line-up board by board from current squad members ("fill from replies" suggests best-rated first), keeps it as a draft only they and officers can see, and publishes it: the players picked are emailed, and later changes email those added or dropped. Members see the fixtures they are picked for in the Member Portal. Replies and line-ups are in data exports, removed on erasure or when the fixture is deleted, and pruned with the retention period.
* Added: member portal (Member Portal block). With no account, a member signs in with a link emailed to the address the club holds (a junior's parent uses theirs), then can correct their name, phone, ECF code and a junior's parent details, see their membership, payment reference, teams, choose which emails they receive and their WhatsApp teams, change their email address, and delete their own details. A new email address only takes effect once confirmed from a link sent to it, and the old address is told. Deleting is immediate after a confirmation tick: the record is deleted, or kept without personal details if a payment, photos, tournament or event registration ties it to the club's accounts.
* Added: a team-aware calendar. Imported league fixtures are linked to the club team playing them (and whether it is home or away) when Import Events is run. The Club Event Calendar block can show only chosen teams, only home or only away fixtures, shows each fixture's team and side, and marks fixtures with the team's colour (set on the team). It adds a link to an iCalendar (.ics) feed for the same selection, so members can subscribe in their calendar app; one address covers the whole club and another each team.
* Added: **Block Help** (Chess Army Knife → Block Help), and the blocks are grouped into five "Chess:" categories in the inserter instead of all being under Widgets.
* Changed: everything is now under one **Chess Army Knife** admin menu (replacing ECF & LMS, Memberships and Teams), with an Overview page. Every screen is listed for everyone who can use the admin area, and a screen you may not use explains what permission it needs instead of being hidden or failing. Change who sees the menu with the `Chess_Army_Knife_menu_capability` filter (default `read`).
* Changed: Teams now have their own permission, and the Club Teams list is merged into them as each team's league entries (see Teams above). Import Events and Team Selection moved into the Teams menu.
* Added: Teams (Memberships → Teams). A permanent team such as "Club A" has a description, home venue, captain, squad and the seasons (Club Teams entries) it plays in, and a **Club Teams** block shows the public details. Squads and captains are private, appear in a person's data export and are cleared when they are erased. A button creates teams from your Club Teams list.
* Changed: members' WhatsApp team choices are stored by team id instead of name, so renaming a team loses nobody's choice. Choices saved under an old team name are matched to the team of that name.
* Added: Memberships > Dashboard: current, pending, expiring, lapsed and guest totals, joiners this month and year, members by type, juniors and adults, newsletter / WhatsApp / ECF code shares and a rating distribution. Totals only (no names), cached for an hour and refreshed when a record changes.
* Added: renewal reminders. A daily job emails current members at the days you choose before (and after) the last day of membership (default 30, 7, 0 and -7), with your payment instructions and the member's reference. Each member gets each stage once per expiry date; a junior's reminder goes to their parent or guardian; members can stop them from the link in each email. Turn them on under Settings, see who is due under Memberships > Renewals, and use Renew on the Members screen when someone pays.
* Added: a mail queue for emails to members (foundation for reminders, announcements and availability requests). Mail is sent in small batches through wp_mail(), to the member or a junior's parent or guardian, with a log of what was sent (subject and time only). Every email ends with a link to stop that kind of email; the newsletter is opt-in and the other kinds can be turned off. The log and choices are included in personal data exports, deleted on erasure, and the log is pruned after 180 days.
* Added: an ECF club code setting: one request to the ECF's club list refreshes every member's rating at once, keeping only people already on your records.
* Added: members' ECF ratings are refreshed hourly in the background (oldest check first, in small batches, backing off if the ECF fails), stored on their record, shown on the Members screen and kept in the cache the blocks read. A Refresh ECF ratings button checks a batch on demand.
* Changed: everyone's details are held once, in the people table. Tournament entries reference a person instead of copying their name and ECF code, and the Players page and its separate profile table are gone. A manual rating is part of a person's record.
* Changed: no player is fetched from the ECF without a record. A typed ECF code is matched to a person or, if unknown, written down as a non-member once the ECF has confirmed who it is. Club Results and Biggest Gainers use the club's own current members; the ECF club roster, club search, club picker and default club code are removed, and Club Results no longer shows opponents.
* Added: Manage My Data block: withdraw newsletter or WhatsApp consent, or change which teams' groups, from an emailed link, or ask for a copy or deletion through WordPress's personal data requests.
* Added: the Media Library member filter now works in grid view as well as list view.
* Added: club memberships. Membership Types (name, description, price, length), a Members screen with pending applications, current, expired and cancelled members, and manual adding, editing and payment recording. New Club Memberships and Membership Application Form blocks, and a payment instructions field under Settings.
* Added: data protection for members: personal data export and erase, recorded consent, automatic deletion of old records after a retention period, suggested privacy policy wording and a public Club Data Policy block.
* Added: the player pickers search the club's own members (and guests) instead of the ECF's player database.
* Added: people who are not members (such as tournament guests) can be recorded with a "Not a member" flag and are left out of member lists and counts.
* Added: tag members in photos from the Media Library, find a member's photos, and include them in personal data exports.
* Added: separate, unticked opt-ins on the application form for the club newsletter and for WhatsApp groups, with a choice of which team(s), each recorded with its time. A failed save is now reported to the applicant instead of a thank-you.
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
