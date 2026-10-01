# Accessibility audit: front-end blocks

Target: **WCAG 2.2 Level AAA** (every A, AA and AAA criterion). Nothing is to be downgraded to AA without a decision from the site owner.

Scope: the 17 front-end blocks (`src/*/render.php`, `view.js`, `style.scss`) and the PHP that feeds them. The block editor and the admin screens are out of scope for now.

## How this audit was done, and its limits

- **Method:** a read of every block's markup, styles and scripts, plus contrast ratios calculated from the stylesheet colours. The plugin has not yet been run in a browser, with axe/Pa11y, or with a screen reader.
- **What this can't show:** real screen-reader output, computed focus order, and how the theme changes things. Items marked *verify* need a browser check.
- **Colours:** ratios are for the plugin's own hard-coded colours against white or the stated background. Text that inherits the theme's colour can't be rated here.
- **Severity:** **Blocker** = a Level A failure (some people cannot use the content). **Major** = an AA or AAA failure that affects many users. **Minor** = an AAA failure with limited impact, or polish.

## Summary

There are 19 cross-cutting findings (F-G) and about 50 per-block findings. Four are Level A blockers (F-CH1, F-CA1, F-CA3 and the lost form data in F-G7/F-FM1). The rest are AA or AAA failures.

The five most serious problems:

1. The rating chart is a bare `<canvas>` with no text alternative (F-CH1).
2. The carousel auto-advances with no pause control (F-CA1).
3. The carousel shows only its first slide without JavaScript (F-CA3).
4. A form error sends the person back with every field empty (F-FM1).
5. Backgrounds are hard-coded but text colour is inherited, so dark themes can produce unreadable text (F-G1).

---

## Status

Progress is on branch `claude/sweet-gauss-ktq43g`. "Done" means changed and covered by a unit or integration test where the code could be tested. The integration tests run in GitHub CI, not here.

**Done**

- F-CH1 to F-CH5: the chart is a server-drawn SVG with a summary and a table. Chart.js is gone.
- F-CA1 to F-CA5: the fixtures block no longer moves, works without JavaScript, and says scores in words.
- F-G1 to F-G3: text follows the theme colour, tints are made from it, and meaningful borders use the text colour. Templates refuse colours under 7:1 (text) and 3:1 (accent).
- F-G4, F-G5: a focus ring that shows on light and dark pages, and 44px targets.
- F-G6: real headings at a level set under Settings → Accessibility.
- F-G7, F-G8, F-G9, F-FM1 to F-FM4: forms keep what was typed, messages take focus and link to the field, required fields are marked in words, hints are tied to fields, and buttons and links say who they are for.
- F-G10: AAA 3.3.6 is met by the "checked, with a chance to correct" route (the form is validated and comes back with the answers kept), plus a confirm tick before deleting. Results entered in the games block can be corrected afterwards.
- F-G11, F-G14, F-G15, F-G16, F-G19: abbreviations explained, link purposes made unique, new-tab warnings, correct `autocomplete`, localised dates and spoken scores.
- F-G13: line spacing 1.5 and an 80-character line length on the plugin's text.
- F-FM5: portal sessions last an hour, show when they end, warn at 15 minutes and can be extended. Saving also starts the hour again.
- F-LT, F-CR, F-BG, F-FP, F-TS, F-ST, F-TG, F-EV, F-MT: the per-block findings above.
- High contrast: automatic with `prefers-contrast: more`, and a site setting (off, follow device, always).

**Done since**

- F-G18: the spam-trap fields are now `inert` (off-screen, out of the accessibility tree and unfocusable) instead of `aria-hidden`. Bots reading the HTML still see and fill them.

**Accepted by the site owner**

- 2.2.5 (re-authenticating, AAA): if a session ends while a form is half filled in, the answers are lost when the person signs in again. Accepted because the page shows when the session ends, warns 15 minutes before, offers an extend button, and every save restarts the hour.

**Open**

- F-G12 and F-MT4: plain-language wording needs your approval. See `plain-language-drafts.md`.
- 1.4.12 text spacing, 1.4.10 reflow at 320px, and a screen-reader pass are still to be done in a real browser against a real WordPress page.
- The admin screens and the block editor have not been audited.

**Checked**

- Axe (WCAG 2 A, AA, AAA and best-practice rules) reports no problems on the plugin's markup in light, dark and high-contrast pages. The page was built from the plugin's own helper code and stylesheets, not from a live WordPress render, so a run against real pages is still needed.

## Cross-cutting findings

| ID | WCAG | Severity | Finding | Where | Fix |
|---|---|---|---|---|---|
| F-G1 | 1.4.3, 1.4.6 | Major | Light backgrounds (`#f6f7f7`, `#fcf0f1`, `#edf7ed`, `#fafafa`, `#fff`) are set without a matching text colour. On a dark theme the inherited light text sits on a pale background. | every `style.scss` (`.chess-army-knife-notice`, `-empty`, carousel, month nav, admin bar) | Set foreground and background together, or use transparent tints (`color-mix`/`rgba`) that follow the theme. |
| F-G2 | 1.4.6 | Major | Hard-coded text colours miss 7:1 (see the table below). Reduced `opacity` on text lowers contrast further. | all styles | Move every colour to `--cak-*` custom properties with 7:1 defaults. |
| F-G3 | 1.4.11 | Major | Borders and dots that identify controls are far below 3:1: `#ccc` on white is 1.61:1. | carousel nav, month nav, inactive dots | Use a border colour of at least 3:1, and never `#ccc`. |
| F-G4 | 2.4.7, 2.4.11, 2.4.12, 2.4.13 | Major | No focus styles of our own; the plugin relies on the theme. `overflow: hidden` on the carousel can clip the focus ring. | all interactive elements | Add `:focus-visible` with a 3px outline and a 3:1 contrast with its neighbours, plus `scroll-margin` so sticky headers don't hide focus. |
| F-G5 | 2.5.5 (AAA), 2.5.8 | Major | Targets are below 44×44px: carousel dots are 8px, nav buttons about 35px, featured-player pills about 28px tall, standalone links like Subscribe and Cancel are text height. | carousel, month nav, featured links | Enforce a 44px minimum in plugin CSS; native checkboxes and radios are exempt if their whole label is clickable. |
| F-G6 | 1.3.1, 2.4.6, 2.4.10 | Major | Block titles are `<p>` elements styled bold, not headings. Sub-sections (standings groups, game rounds, matchups) are also `<p>`. Where headings exist they skip levels (an `h3` under a `<p>` title). | all blocks except event-registration, member-portal, team-profiles, data-policy | Add a heading-level attribute with a sensible default, and use real headings throughout. |
| F-G7 | 3.3.1, 3.3.3, 3.3.4, 3.3.6, 3.3.7 | Blocker for A | After an error, the form redirects with a GET and an error code, so **every field is empty again**. | membership-form, event-registration, my-data, member-portal | Re-populate the form after an error (keep the values server-side for one request), or submit with AJAX and keep the form in place. |
| F-G8 | 3.3.1, 4.1.3 | Major | Errors are a single `role="alert"` message at the top. They don't name the field, there is no `aria-invalid`, no `aria-describedby`, and focus is not moved. A notice that already exists at page load is unreliably announced. | all forms | Move focus to an error summary with links to the fields, mark fields `aria-invalid`, and link messages with `aria-describedby`. |
| F-G9 | 3.3.2, 3.3.5 | Major | Required fields show no visual "required" cue, hints are not tied to fields (`aria-describedby`), and there is no help for things like "ECF rating code". | membership-form, event-registration, portal | Mark required fields in text, and add short linked hints. |
| F-G10 | 3.3.6 | Major | AAA needs every submission to be reversible, checked or confirmed. The membership form, details form and tournament results have no review, confirm or undo step. | membership-form, member-portal, tournament-games | Add a review step, or a clear confirmation plus an undo/edit route. |
| F-G11 | 3.1.3, 3.1.4 | Major | Abbreviations are unexplained: P, W, D, L, Pts, #, R1, v, ECF, LMS, board. | league-table, tournament-standings, carousel, forms | Use `<abbr title>` or visually hidden full words. |
| F-G12 | 3.1.5 | Major | AAA needs content to be readable at lower-secondary level, or a simpler version. Consent, WhatsApp and privacy text is long. | membership-form, data-policy, my-data, portal | Plain-language rewrite; split long sentences. This is content work, not only code. |
| F-G13 | 1.4.8 | Major | Line spacing, paragraph spacing and line length come from the theme. Text blocks can exceed 80 characters; the carousel centres text. | all | Set `line-height: 1.5`, paragraph spacing, and `max-width: 80ch` on plugin-owned text. |
| F-G14 | 2.4.9 | Major | AAA needs link purpose to be clear from the link text alone. Repeated "Apply for this membership", "Cancel" and "Subscribe" links are identical. | memberships, member-portal, calendar | Add a visually hidden name to each link. |
| F-G15 | 3.2.5 | Minor | Links open in a new tab with no warning. | featured-player | Add "(opens in a new tab)" text. |
| F-G16 | 1.3.5, 1.3.6 | Minor | Missing or wrong `autocomplete`: portal and event-registration fields have none; the junior's date of birth uses `bday`, which would suggest the parent's own birthday. | membership-form, event-registration, member-portal | Add correct values and remove the wrong one. |
| F-G17 | 1.4.1 | Minor | Some state is shown by colour or opacity only: the active carousel dot, withdrawn players, saved game rows, and team colour on events (when team names are hidden). | carousel, standings, players, games, calendar | Add text or a shape as well. |
| F-G18 | 4.1.2 | Minor | Honeypot fields sit inside `aria-hidden` containers while still being focusable (they rely on `tabindex="-1"`). | membership-form, event-registration, my-data, member-portal | Use `hidden`-style clipping that removes them from the accessibility tree. |
| F-G19 | 1.3.1, 3.1.5 | Minor | Dates are shown raw (`2025-03-04`), ranges use `–`, and arrows use `→`, all read poorly. `gmdate` is not localised. | league-table, carousel, club-results, biggest-gainers | Use `wp_date()` with the site format, `<time datetime>`, and words ("from 1500 to 1580"). |

### Contrast of the plugin's own colours (AAA needs 7:1 for text, 3:1 for UI components)

| Use | Colour on background | Ratio | AAA |
|---|---|---|---|
| Carousel event name | `#888` on `#fff` | 3.54 | Fail |
| Gainers rank | `#999` on `#fff` | 2.85 | Fail |
| Labels, dates, detail text | `#666` on `#fff` | 5.74 | Fail |
| Location, team names | `#555` on `#fff` | 7.46 | Pass |
| Hint text on grey | `#555` on `#f6f7f7` | 6.95 | Fail |
| Win / rating up | `#1a7f37` on `#fff` | 5.08 | Fail |
| Loss / rating down | `#cf222e` on `#fff` | 5.36 | Fail |
| Draw | `#9a6700` on `#fff` | 4.87 | Fail |
| Error text | `#b32d2e` on `#fff` | 6.30 | Fail |
| Error text on pink | `#b32d2e` on `#fcf0f1` | 5.67 | Fail |
| Admin bar badge | `#8a6d1a` on `#fff8e5` | 4.63 | Fail |
| Default accent | `#1e3a5f` on `#fff` | 11.50 | Pass |
| Nav/dot borders | `#ccc` on `#fff` / `#fafafa` | 1.61 / 1.54 | Fail (3:1 needed) |
| Text at `opacity: 0.6` | black at 60% on white | about 5.7 | Fail |

The accent, background and text colours that admins can choose under Templates are not checked at all. A template can produce unreadable text.

---

## Findings by block

### Rating Chart
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-CH1 | 1.1.1 | **Blocker** | `<canvas>` has no role, label or fallback content. A screen-reader user gets nothing. | Replace with SVG, or add `role="img"` with a text summary plus a full data table. |
| F-CH2 | 1.4.4, 1.4.9 | Major | Chart text is drawn in canvas at fixed pixel sizes, so it doesn't follow browser text size (and counts as an image of text for AAA). | The table/SVG fix covers this. |
| F-CH3 | 2.1.1 | Major | Values appear only in a hover tooltip. | Keyboard-reachable points, or the data table. |
| F-CH4 | 1.4.11 | Minor | A user-chosen line colour isn't checked; `+ '22'` assumes a 6-digit hex. | Validate contrast and format on save. |
| F-CH5 | forced colours | Minor | Canvas ignores Windows High Contrast. | SVG using `currentColor`. |

### Team Fixtures Carousel
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-CA1 | 2.2.2, 2.2.4 | **Blocker** | Auto-advance is on by default and pauses only on hover or focus. There is no pause control, and it restarts when focus or the mouse leaves. | Default to off; if on, add a visible Pause/Play button; respect `prefers-reduced-motion`. |
| F-CA2 | 4.1.2, 1.3.1 | Major | No `role="region"`, `aria-roledescription="carousel"` or accessible name. Dots have no current state (`aria-current`). Slide changes aren't announced. | Add the ARIA; an `aria-live` region that is off while auto-rotating and polite when the user moves it. |
| F-CA3 | 1.3.1, 2.1.1 | **Blocker** | Without JavaScript only the first slide is visible (others are `display: none` in CSS). | Show all slides by default; JS opts in by adding a class. |
| F-CA4 | 1.4.1 | Major | The active dot differs by background colour only, and vanishes in forced-colours mode. | Add a ring/shape and `aria-current`. |
| F-CA5 | 3.1.4 | Minor | "Home v Opponent" and "3 – 1" read poorly. | "Home against X", "3 to 1". |

### League Standings & Matchups
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-LT1 | 1.3.1 | Major | No `<caption>`; team cell isn't a row header; `#`, P, W, D, L, Pts are bare. | Caption, `<th scope="row">` for team, expanded headings. |
| F-LT2 | 1.3.1 | Major | The highlighted team is shown by background and bold only. | Add hidden text "(our team)". |
| F-LT3 | — | Minor | `class=\"ecf-league__match-venue\"` has stray backslashes, so the venue style never applies. | Fix the markup (an existing bug found in passing). |
| F-LT4 | 1.3.1 | Minor | The "Matchups" sub-heading is a `<p>`. | Real heading. |

### Club Results
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-CR1 | 1.3.1 | Major | No caption or `scope`; the Player cell isn't a row header. | As F-LT1. |
| F-CR2 | 1.4.6 | Major | Result colours miss 7:1 (they are also spelled out as text, which is good). | Darker colours. |
| F-CR3 | 3.1.5 | Minor | Date uses `gmdate`, not localised. | `wp_date()`. |

### Biggest Gainers
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-BG1 | 1.4.6 | Major | Rank `#999` (2.85:1) and detail `#666`. | Darker colours. |
| F-BG2 | 1.3.1 | Minor | "1500 → 1580" reads as "rightwards arrow". | "from 1500 to 1580". |

### Featured Player
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-FP1 | 2.5.5 | Major | Profile link pills are about 28px tall. | 44px minimum. |
| F-FP2 | 3.2.5 | Minor | Links open in a new tab without warning. | Hidden text. |
| F-FP3 | 1.1.1 | Minor | Photo `alt` repeats the adjacent name. | Empty alt, or an editable description. |
| F-FP4 | 1.4.6 | Minor | Meta text uses `opacity: 0.8`. | Solid colour at 7:1. |

### Tournament Status / Players / Winners
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-TS1 | 1.3.1 | Major | Players and Winners tables have no caption or scoped headers. | As F-LT1. |
| F-TS2 | 1.4.6 | Minor | Withdrawn rows use `opacity: 0.6`. | Solid colour, keep the "(withdrawn)" text. |

### Tournament Standings
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-ST1 | 2.1.1 | Major | The horizontal scroll wrapper isn't keyboard-focusable, so keyboard users can't scroll wide tables. | `tabindex="0"`, `role="region"`, `aria-labelledby`. |
| F-ST2 | 1.3.1 | Major | Round headers "R1…" are cryptic; empty cells mean "no game" but say nothing; groups are `<p>`. | "Round 1", hidden "no game", real headings, caption. |
| F-ST3 | 1.4.6 | Minor | Withdrawn rows at `opacity: 0.6`. | As F-TS2. |

### Tournament Games to Play
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-TG1 | 1.3.3 | Major | Which player has White is shown only by position and CSS class. | Hidden "(White)" / "(Black)". |
| F-TG2 | 4.1.3 | Major | Status text is `…` then `✓`, then the page reloads immediately, so the result is never announced. The reload also drops focus. | Announce success, then move focus deliberately (or update in place). |
| F-TG3 | 4.1.3 | Major | Row errors are plain spans without alert semantics; status messages in JS aren't translatable. | `role="alert"`, `aria-describedby` on the row, translated strings. |
| F-TG4 | 3.2.2 | Minor | Choosing one score silently changes the other select. | Announce it in the live region. |
| F-TG5 | 1.1.1 | Minor | The empty option is "—" (read as "em dash"). | "No result". |
| F-TG6 | 1.4.1 | Minor | Saved rows are shown by opacity only. | Text "Saved". |

### Club Event Calendar / Next Club Event
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-EV1 | 1.3.1 | Major | Below 600px the month table is switched to `display: block`, which removes table semantics in some browsers. | Render a real list for small screens. |
| F-EV2 | 1.3.1 | Major | Day cells carry only a number ("5"), with no full date for screen readers. | Hidden full date in each cell. |
| F-EV3 | 4.1.3 | Major | Month change failures are silent; no loading state. | Announce failure and loading. |
| F-EV4 | 1.3.1, 3.3.5 | Major | Location is a `title` tooltip, which touch and keyboard users can't reach. | Visible text. |
| F-EV5 | 1.4.1 | Minor | Team colour is the only team cue when team names are hidden. | Text label. |
| F-EV6 | 1.3.1 | Minor | Heading level: `h3` day headings under a `<p>` title; times aren't `<time>`. | Headings (F-G6), `<time datetime>`. |
| F-EV7 | 1.3.2 | Minor | Prev/next glyphs (‹ ›) aren't mirrored in right-to-left languages. | Flip under `:dir(rtl)`. |

### Membership Form / Event Registration / Manage My Data / Member Portal
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-FM1 | 3.3.4, 3.3.7 | **Blocker** | Errors lose everything the person typed (F-G7). | As F-G7. |
| F-FM2 | 1.3.1 | Major | "Who is coming?" and the team list are paragraphs, not fieldset/legend. Hints aren't tied to fields. | Fieldsets, `aria-describedby`. |
| F-FM3 | 2.4.6, 2.4.9 | Major | Repeated identical buttons and links ("Save my choices", "Change address", "Delete my details", "Cancel") for each person in a family. | Hidden person name in every label. |
| F-FM4 | 4.1.3 | Major | Success and error notices are present at load, so `role="status"` / `role="alert"` is not reliably announced. | Move focus to the notice after redirect. |
| F-FM5 | 2.2.1, 2.2.6, 2.2.5 | Major | Portal session is a fixed 2 hours with no warning, and expiry loses unsaved changes. | **Agreed:** 1 hour, a warning at 15 minutes left, and an Extend option; preserve unsaved data on expiry. |
| F-FM6 | 3.3.6 | Major | No confirm/undo on details and membership submission. | As F-G10. |
| F-FM7 | 3.3.8, 3.3.9 | OK | Emailed-link sign-in has no cognitive test. Verify in a browser. | None. |

### Memberships / Club Teams / Data Policy
| ID | WCAG | Severity | Finding | Fix |
|---|---|---|---|---|
| F-MT1 | 1.3.1, 2.4.6 | Major | Membership cards are `div`/`p`; the name isn't a heading and the set isn't a list. | Headings and `<ul>`. |
| F-MT2 | 2.4.9 | Major | Identical "Apply for this membership" links. | Hidden type name. |
| F-MT3 | 1.3.1 | Minor | Club Teams' league list has no label saying what it is. | "Leagues" label. |
| F-MT4 | 3.1.5 | Major | Data policy reading level. | Plain-language review. |

---

## AAA criteria status

"Fail" = at least one failing finding above. "Theme" = depends on the site's theme or content, so the plugin can only help.

| Criterion | Level | Status | Notes |
|---|---|---|---|
| 1.1.1 Non-text Content | A | Fail | F-CH1 |
| 1.2.x Media | A-AAA | N/A | The plugin embeds no audio or video. |
| 1.3.1 Info and Relationships | A | Fail | F-G6, tables, forms |
| 1.3.3 Sensory Characteristics | A | Fail | F-TG1 |
| 1.3.5 / 1.3.6 Input Purpose / Identify Purpose | AA / AAA | Fail | F-G16 |
| 1.4.1 Use of Colour | A | Fail | F-G17 |
| 1.4.3 / 1.4.6 Contrast | AA / AAA | Fail | F-G1, F-G2 |
| 1.4.4 Resize Text | AA | Fail | F-CH2 (canvas) |
| 1.4.8 Visual Presentation | AAA | Fail / Theme | F-G13 |
| 1.4.9 Images of Text | AAA | Fail | F-CH2 |
| 1.4.10 Reflow | AA | Verify | Tables scroll; the calendar switches layout. |
| 1.4.11 Non-text Contrast | AA | Fail | F-G3 |
| 1.4.12 Text Spacing | AA | Verify | |
| 1.4.13 Content on Hover/Focus | AA | Fail | F-EV4 (`title` tooltips) |
| 2.1.1 Keyboard / 2.1.3 | A / AAA | Fail | F-ST1, F-CH3 |
| 2.2.1 / 2.2.6 Timing Adjustable / Timeouts | A / AAA | Fail | F-FM5 |
| 2.2.2 Pause, Stop, Hide | A | Fail | F-CA1 |
| 2.2.3 No Timing | AAA | Decision made | Portal session with warning and extension (F-FM5). |
| 2.2.4 Interruptions | AAA | Fail | F-CA1 |
| 2.3.3 Animation from Interactions | AAA | Pass | No animation found; re-check after the carousel work. |
| 2.4.6 / 2.4.10 Headings and Labels / Section Headings | AA / AAA | Fail | F-G6 |
| 2.4.7 / 2.4.11 / 2.4.12 / 2.4.13 Focus | AA / AA / AAA / AAA | Fail | F-G4 |
| 2.4.9 Link Purpose (Link Only) | AAA | Fail | F-G14 |
| 2.5.5 / 2.5.8 Target Size | AAA / AA | Fail | F-G5 |
| 3.1.3 / 3.1.4 / 3.1.5 Unusual Words / Abbreviations / Reading Level | AAA | Fail | F-G11, F-G12 |
| 3.2.5 Change on Request | AAA | Fail | F-G15, F-TG2 |
| 3.3.1 / 3.3.3 Error Identification / Suggestion | A / AA | Fail | F-G7, F-G8 |
| 3.3.2 / 3.3.5 Labels / Help | A / AAA | Fail | F-G9 |
| 3.3.4 / 3.3.6 Error Prevention | AA / AAA | Fail | F-G10 |
| 3.3.7 Redundant Entry | A | Fail | F-G7 |
| 3.3.8 / 3.3.9 Accessible Authentication | AA / AAA | Pass (verify) | F-FM7 |
| 4.1.2 Name, Role, Value | A | Fail | F-CA2, F-G18 |
| 4.1.3 Status Messages | AA | Fail | F-G8, F-TG2, F-EV3 |

## Proposed fix order

1. **Blockers:** chart alternative (F-CH1), carousel pause and no-JS (F-CA1, F-CA3), form data kept on error (F-G7).
2. **Shared foundations:** design tokens for colour at 7:1 and the high-contrast mode, a shared focus style, target sizes, heading-level setting, and a small shared script for moving focus to notices.
3. **Tables and structure:** captions, scope, abbreviations, hidden text.
4. **Forms:** error summary, hints, required markers, confirm/review step, portal session timeout with warning and extend.
5. **Calendar and games:** the remaining live-region and semantics fixes.
6. **Content:** plain-language rewrite of forms and the data policy (needs your review, since it changes wording).
7. **Regression guard:** tests asserting the markup we rely on, and an axe/Pa11y run in CI.

## Decisions (agreed)

Where AAA and convenience conflict, AAA wins. We can relax something later by agreement.

1. **Carousel:** no motion at all. The block keeps its name so existing pages still work, but it renders every team as a static list. Auto-advance and its settings are retired.
2. **Template colours:** the Templates screen refuses colour combinations under 7:1 and says which pair failed.
3. **Reading level:** plain-language rewrites are drafted for approval (before wording is changed in the plugin), since some of it is consent wording.
4. **Rating chart:** a server-rendered SVG with a data table. Chart.js is removed.
5. **Portal session:** 1 hour, a warning with 15 minutes left, and an Extend option.
6. **High contrast:** automatic via `prefers-contrast` and `forced-colors`, plus a site-wide setting (Off / Follow device / Always on).
7. **Scope:** front-end blocks first, then admin.
