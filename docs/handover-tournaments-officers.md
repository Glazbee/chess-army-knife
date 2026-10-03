# Handover: redo the Tournaments and Officers admin screens

Branch: `claude/jolly-maxwell-rw99ke`. PR 33 (Club Teams block) is merged; 16 commits since then are pushed to this branch but **no PR is open for them yet**. They cover Teams, Groups, Leagues, Names, Members and the menu consolidation (see "What exists now"). Open a PR from this branch, or start the next one from it.

Read `docs/handover-next-pr.md` first for how to run tests, PHPCS and the JS build. Two extra points:

- PHPCS: if `phpcs` reports "Referenced sniff does not exist", run `vendor/bin/phpcs --config-set installed_paths $(pwd)/vendor/wp-coding-standards/wpcs,$(pwd)/vendor/phpcsstandards/phpcsutils,$(pwd)/vendor/phpcsstandards/phpcsextra` once. Then `vendor/bin/phpcs includes src tests` and `phpcbf` work.
- **The integration tests have not run on this branch.** The recent work changed how names are stored (see below), and about 25 integration test expectations were edited by reading, not by running. Push, read the CI result, and fix what CI finds before building on top.

## What exists now that the next work should reuse

**Tab groups.** One menu item per area, with the other screens as tabs.
- `Chess_Army_Knife_Team_Tabs` (Teams: Teams, Groups, Leagues, Squad review; per team: Details, Overview, Selection), `Chess_Army_Knife_Member_Tabs` (Members, Checks, Membership types, Renewals, Players from the LMS, Do not record) and `Chess_Army_Knife_Section_Tabs` (Other clubs, Club events, Settings). `Section_Tabs::groups()` is a data table: add a group there for new tabs.
- A tab without a menu item is an area in `Chess_Army_Knife_Menu::areas()` with `'unlisted' => true`. `Menu::register()` registers it with a `null` parent, so it opens but is not listed. **Do not use `remove_submenu_page()`**: WordPress then refuses the page ("Sorry, you are not allowed to access this page"). The tab classes also filter `parent_file` and `submenu_file` to keep the menu item lit.
- Custom pages call `...Tabs::render()` under their `<h1>`; post type screens get the bar from `edit_form_top` and `all_admin_notices`.

**Two-list pickers** (drag between lists, arrow buttons for the keyboard, announcements for screen readers):
- `assets/squad-picker.js` and `Teams_Admin::render_squad()`: members not in the squad on the left (with a search box), the squad on the right. Each row has a hidden input that is disabled while the person is in the left list, so only the right list is saved.
- `assets/group-teams.js` and `Team_Groups_Admin`: also reorders the right list.
- `assets/leagues.js` and `Leagues_Page`: one list per division, plus a "not in a division" list.
Use jQuery UI sortable (`jquery-ui-sortable`, `jquery-touch-punch`) as these do.

**CSV export.** `includes/class-member-export.php` has the pattern: `columns()`, `row()`, `safe_cell()` (guards against spreadsheet formula injection), `to_csv()`, `download()` (needs a nonce and the permission). The tournament export should follow it.

**Names.** `Chess_Army_Knife_Names` (`includes/class-names.php`). Names are now **stored** as "Surname, Firstname" (the ECF way) by `Membership_Store::save_member()`, and **shown** with `Names::person( $row, $style )` or `Names::format( $name, $style, $nickname )`, where `$style` comes from `Names::style_for( $attributes )` (a block's own `nameFormat`, else Settings → Names). Any new screen or export that shows a member's name should say which it wants: the stored form for admin lists, the formatted form for the public site and emails. Tournament entries read the person's name through a join (`Tournament_Store::entry_select()`), so they carry `name` and `nickname`.

**Schema without a version bump.** The plugin is in development, so the schema version is not bumped. `Membership_Store::maybe_add_columns()` adds new columns to an existing table once, on `init`. Do the same for any new column.

**Menu and Overview.** Every screen is listed in `Menu::areas()`; the Overview and the "needs permission" screens read the same list.

## 1. Tournaments: redo the admin screen

Files: `includes/class-tournaments-page.php` (1,177 lines; the page and its `admin-post` handlers), `includes/class-tournaments.php` (formats, pairings, standings), `includes/class-tournament-store.php` (tables `tournaments`, `entries`, `games`), `includes/class-player-selector.php` (typed and found players), `includes/class-tournament-summary.php` (what the blocks show), `includes/class-tournament-rest.php`.

How it works today: the page is the list plus a create form (`render_list()`); a tournament opens `render_tournament()` with draft controls (add players with the Player Selector, start), then the round forms, standings, requested byes, an event section and a page section. Players can only be added or removed while the tournament is a draft. Entries are rows in `entries` (`player_id`, `seed`, `start_rating`, `rating_source`, `status`, `group_no`); games are rows in `games` (white and black entry ids, `result`, `is_bye`, `round`, `stage`). Results are entered inline on the tournament screen.

What to build:

1. **Tabs.** Make Tournaments one place like Teams and Members. Top level: **Tournaments** (the list, already there) and **Create new** (the form that is on the list page now). Inside a tournament consider **Players**, **Results**, **Standings** and **Export**. Add a tab group to `Section_Tabs::groups()` (or a small class of its own if the per-tournament tabs need the tournament id, as `Team_Tabs` does for a team).
2. **Export to CSV.** One file per tournament: player name, ECF code, starting rating and where it came from (`rating_source`), seed, status, then for each round the colour (W or B, blank for a bye), the opponent and the result, and the total points. Colours come from `games.white_entry_id` and `black_entry_id`. Needs the tournaments permission and a nonce. ECF codes are personal data, so keep the same care as the Member export (no notes; say what the file holds). Decide whether names are exported as stored ("Cave, Nathan") or formatted; for a spreadsheet, stored is probably better.
3. **Drag members into the tournament.** Replace the Player Selector's "add selected" with the two-list picker: members on the left (with the search box), entrants on the right. The right list is what is saved when the tournament is a draft. Keep the rule that entrants are fixed once it starts (show the list read-only after that). Show each person's rating beside their name so seeding is visible; a "(manual)" or "(no rating)" label is already used in the Tournament Players block.
4. **Non-club players.** Make it obvious when adding someone who is not a member, and whether they have an ECF rating code. Today `Player_Selector::sanitize_player()` takes name, ECF code and a manual rating in a row of text boxes. Suggested: a clear "Add someone who is not a member" box with two choices, "I have their ECF code" (look the code up; the plugin already can: `Membership_Store::record_ecf_player()`) and "No code" (name and an optional manual rating, with the allowed range written next to the box). Say plainly what is kept: a person without a code is recorded as a non-member (`status = nonmember`), left out of member lists, and still goes through Do Not Record checks (`ensure_person()`).
5. **A tab for entering results.** A screen listing the tournament's rounds and games, with one results form per round (the same `handle_save_results()` and `render_round_form()` exist). Make the current round the default, show who has not been entered, and keep "next round" and "redo round" beside it. The Swiss controls and the Berger/knockout logic stay in `Chess_Army_Knife_Tournaments`; this is a screen change, not a rules change.

Open questions to ask the user before starting:
- Should "Create new" be a tab of the Tournaments list, or a button on it? (They asked for tabs.)
- Should the export include games still to play, or only results?
- Should a completed tournament still be editable (correcting a result)? Today `handle_save_results()` has its own rules; check them first.

Loose end: the **Tournament Games to Play** block (administrators only) draws its player names in the browser from the REST route; those names are not run through `Names::format()` and ignore the site's name style. Fix it while in there (pass the style as a data attribute and format in the REST response).

## 2. Officers: redo the admin screen, with one global order

Files: `includes/class-officers-page.php` (the screen, 338 lines), `includes/class-officers.php` (positions, terms, history, `items()`, `order_items()`, `listing()`), `src/officers/` (the block; `edit.js` and a shared sortable list, `src/shared/sortable-list.js`), the REST route `officer-items`.

How it works today: positions are a short list kept in an option, in the club's chosen order; each position has terms (who, since, until). The Positions table has Up and Down buttons. The block lists every position, then each team's captain, in that default order, and the block can override with its `order` attribute (drag-and-drop in the editor): `Officers::order_items( $items, $order )` lists the block's keys first, then anything it does not mention in the default order.

So the "global ranking" the user wants **already exists in the code**: the order of the Positions table is the default for every block, and a block overrides it only when someone reorders it there. The work is mostly the screen:

1. Rebuild the Officers screen with the controls we now have: drag to reorder positions (the same sortable list; keep arrow buttons for the keyboard), a drop-down of members to choose who holds a position (the page already uses a members select for assigning; check it lists the right people), and a clear label that this order is the **site-wide default**.
2. Make the block's editor say whether it follows the default or has its own order, and give it a "Use the club's order" button that clears the block's `order`. At the moment a block that has been reordered once keeps its own order with no way back short of dragging it again.
3. Decide where team captains sit: they are appended after the positions (`items()`), listed per team. A global setting for "captains first/last" may be wanted; ask.
4. Add an integration test: reorder the default, check a block with no `order` follows it and a block with one does not.

Open questions for the user:
- Should the default order also include team captains as draggable items, so a captain can be placed among the positions?
- Should a person who holds two positions appear once or under each?

## Known loose ends from the recent work (not part of this task)

- Nicknames apply to club members. Names that come from the ECF or the LMS (Featured Player, board lists in Team Results) are only reordered; matching them to a member by ECF code would allow a nickname.
- The LMS organisation name on the Leagues tab is read from the seasons response if present (`LMS_Client::get_org_name()`); the shape of that response is not documented and could not be checked from the build container. If it is absent the admin types the name once. Check against a real response.
- Hero teams in the Club Teams block are chosen per block, so the same group in two blocks needs them ticked twice.
- A guardian chosen from the members is copied onto the junior when the junior is saved, so a parent's later change of email does not reach the junior until the junior's record is saved again.
- Existing members keep the old "Firstname Surname" form of their name until their record is saved; lookups accept both.
