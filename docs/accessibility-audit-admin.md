# Accessibility audit: admin screens

Target: WCAG 2.2 Level AAA, as for the front end. Scope: the plugin's own admin screens (Dashboard, Members, Member Checks, Renewals, Teams, Events, Event registrations, Announcements, Memberships, Tournaments, Team Selection, Settings, Templates) and `assets/admin.css`/`admin.js`. The block editor is a separate decision (see the end).

Method: a read of the screens' code and the colour values, plus the contrast of WordPress's own admin colours. Nothing has been run in a browser yet. Items marked *verify* need that.

## The decision that shapes this audit

The plugin's screens are built from WordPress's own admin components (list tables, form tables, buttons, notices). **WordPress core aims for WCAG 2.2 AA in its admin, not AAA**, and its colours show it:

| Core admin colour | Ratio | AAA needs |
|---|---|---|
| Links `#2271b1` on the page grey | 4.54 | 7 |
| Links on white | 5.17 | 7 |
| White text on a primary button | 5.17 | 7 |
| Description text `#646970` | 4.86 | 7 |
| Body text `#1d2327` | 13.95 | 7 (passes) |

Core's controls are also small (buttons 30px high, checkboxes 16px), where AAA asks for 44px targets, and its focus ring is thinner than AAA's 2.4.13 asks for.

So on the plugin's screens the plugin either **accepts core's AA** for the parts core owns, or **overrides them on the plugin's own screens only**. I recommend overriding, scoped by a body class so other plugins' and core's screens are untouched: links and descriptions at 7:1, buttons at 7:1, a 3px focus ring and 44px targets. I have not decided this for you.

## Findings

Severity: **Blocker** = Level A failure. **Major** = AA or AAA failure that affects many users. **Minor** = limited impact or polish.

| ID | WCAG | Severity | Finding | Where | Fix |
|---|---|---|---|---|---|
| A-1 | 1.3.1, 3.3.2, 4.1.2 | **Blocker** | Selects and inputs with no label: the result select on every game row, the three bye selects, the manual-player name and rating (placeholder only), and the per-row tick in Member Checks. | Tournaments page, Player selector, Member Checks | Add `aria-label`s that name the player or game, and real labels. |
| A-2 | 4.1.2, 2.4.9 | Major | Actions are links: Renew and Delete are `<a href>` with `onclick="confirm()"`. A link should go somewhere; an action should be a button. | Members page, Templates, Tournaments | Small forms with real buttons and the existing confirm step. |
| A-3 | 2.4.9, 2.4.6 | Major | The same words repeat on every row (Edit, History, Renew, Delete, Photos) with no hint of who they are for. | Members, Tournaments, Teams, Events lists | Hidden text with the person or item name. |
| A-4 | 1.3.1, 2.4.10 | Major | Tables have no caption or accessible name, and the Dashboard bars and meters give a tooltip (`title`) as their only text. | all list tables, Dashboard | A screen-reader heading or caption for each table; remove the `title` tooltips, since the numbers are already beside the bars. |
| A-5 | 1.4.6 | Major | Core colours below 7:1 (table above). The plugin's own `#b32d2e` error text is 6.30:1. | all screens, `assets/admin.css` | Depends on the decision above. |
| A-6 | 2.4.7, 2.4.13 | Major | Core's focus ring is thinner than AAA asks for. | all screens | Depends on the decision above. |
| A-7 | 2.5.5 | Major | Targets under 44px: core buttons, checkboxes, row-action links, the 16px colour swatches. | all screens | Depends on the decision above. |
| A-8 | 3.1.3, 3.1.4, 3.1.5 | Major | Chess and club terms are unexplained (Swiss, Berger, bye, board, ECF, LMS), and some help text is long. | Tournaments, Teams, Settings | Short explanations beside the terms; a reading-level pass like the front end. |
| A-9 | 4.1.3 | Minor | Messages and the player search results are not announced. (Core's own notices behave the same way.) The selector count is a live region, which is good. | Player selector, notices | Live region for the results; focus the message after a save. |
| A-10 | 1.4.10 | Minor | Inline fixed widths (`width:220px`, `max-width:640px`, `720px`). | Tournaments, Teams, Team Selection | Relative widths. |
| A-11 | 1.4.6 | Minor | The Dashboard's colours are fixed in an inline `<style>` block; its text colours pass (muted text 7.33:1) but the bars and tracks should be checked. | Dashboard | *verify*; move to `admin.css`. |
| A-12 | 3.3.6 | Minor | Deleting uses a native confirm (good). Saving a tournament result has no confirm, but it can be corrected afterwards, which meets the criterion. | Tournaments | None needed. |

## Status

Decisions (agreed): override core's colours, sizes and focus ring on the plugin's own screens only; make the plugin's own parts of the block editor AAA and document the rest as a limit.

**Done**

- A-1: every unlabelled select and input now has a label (game results, byes, manual player, bulk ticks, registrations).
- A-3: row links say who they are for (Edit, History, Renew, Delete, Photos, Approve, Decline, Manage, Remove, Withdraw, Cancel). Registration ticks name the person.
- A-4: every list table has a screen-reader caption; the empty actions heading is named; the dashboard bars are decorative (the numbers are beside them), and the hover tooltips are gone.
- A-5, A-6, A-7: `assets/admin-accessibility.css`, loaded only on the plugin's screens (a `cak-admin-screen` body class). Links and help text are at least 7:1 (9.4 to 12.8 measured against the admin greys), buttons are 7:1 with a 2px border and 44px high, fields are 44px high, ticks are pressed through 44px labels, and the focus ring is a 3px black outline with a white gap.
- A-9: the member search says how many it found; the block editor's player picker does too.
- Block editor: the plugin's player picker announces results, errors and the selection, and its list border is 3:1.

**Not changed, with reasons**

- A-2 (actions as links): WordPress's own list tables use links for actions such as Trash, and no WCAG criterion fails for it, so I have left the links (each still asks before it deletes). This was over-rated in the first audit; it is a best practice, not a failure.
- A-10 (fixed widths): the widths are maximums, so the screens still reflow. Not a failure.
- A-8 (explaining chess terms in the admin) and the reading level: waiting on the wording approval in `plain-language-drafts.md`.

**Still to check**

- The admin stylesheet has been written against core's colour values, but not seen in a real admin, in each colour scheme. A browser pass is needed.
- The rest of the block editor's sidebar (core components) stays at core's AA, as agreed.

## What looks fine

- Dates, times and numbers use native inputs.
- Labels wrap most checkboxes (settings, announcements, events, teams).
- Members list has a per-row `aria-label` on its tick.
- The colour inputs are labelled.
- The menu icon is a decorative dashicon.

## Proposed order

1. A-1 (labels): small, and the only Level A failure.
2. A-2 and A-3 (real buttons, row context).
3. A-4 and A-9 (captions, live regions).
4. The core-colour decision, then A-5 to A-7 in one admin stylesheet.
5. A-8 (explanations), once you have approved the front-end wording approach.

## Decisions needed

1. **Core's colours and sizes.** Override them on the plugin's own screens (my recommendation), or accept core's AA there?
2. **The block editor.** Its sidebar controls come from WordPress's own component library, which also aims at AA. The plugin's own parts there (the player picker, template picker, tag control) can be made AAA; the rest cannot. Do you want the plugin's parts done, with the rest documented as a limit?
