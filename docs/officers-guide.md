# Officers' guide: the weekly routine

A short guide for the people who run the club's site. It follows the order you would work in. The
screens are all in the **Chess Army Knife** menu; the **Overview** lists every one and says which you
may use. Some need a permission that an administrator gives you on your user profile.

| You need | To use |
|---|---|
| The *Club memberships* permission | Members, Seasons, Renewals, Member Checks, Announcements, Do Not Record, Officers |
| The *Club teams* permission (or be a team's captain) | Teams (Details, Overview, Selection and Groups tabs), Clubs, Sort Clubs, Squad Review, League games (with the import); captains see only their own team in the Overview and Selection tabs |
| Administrator | Settings, Setup, Policies, Templates |

## Once, when you start

1. **Setup** asks for the club's name and venue, regular weekly events, your ECF details and your LMS
   details, and can add your first team and fetch its fixtures. Anything you skip can be done later.
2. Enter the **LMS API key** under Settings and press **Test the LMS connection**.
3. Open **Policies** and decide, for each policy, whether the plugin makes you a draft page to edit or you
   will write it yourself. Read the draft against what your club really does before you mark it reviewed.
4. The **Overview** has a **Still to do** list. Work down it; each line has a link.
5. Open **Officers**, name the club's positions (Chairman, Secretary...), put them in order and add who
   holds each. When someone stands down, press **Stand down** and add their successor: the history keeps
   both. Team captains are officers too; change them on the team, not here. Then add the **Club Officers**
   block to a page. It lists the officers in the order you set on the Officers screen; to give one block an
   order of its own, drag the positions in its settings (and press **Use the club's order** to go back).

## Every week

**1. Fetch the fixtures.** The import on *Club events → League games* runs by itself once a day. Press **Import now** if you need
fresh results sooner. The screen says when it last ran and how many leagues failed. A red line on the
Overview about the API key means the LMS stopped accepting it.

To keep your history, scroll to **Earlier seasons** on the same screen, tick the seasons you want (or
*All earlier seasons*) and press **Import selected seasons**. They come in as past events; squads are
not changed. A team whose league had a different name in an old season is reported and skipped.

**Results are kept too.** The import keeps each played match's score and who played each board, and the
event's page shows them (Club Event Details block). To set up your membership from this, open
**Members → Add players from the LMS**: it lists everyone who played for your teams and is not a member
yet. Tick the ones who are your members and they are added as *pending*; then open each from the
Members screen to add their contact details and approve them. Their consent to the club holding their
details is recorded as given; newsletter and WhatsApp consent are not assumed. Nobody is added until you
tick them, and someone on the Do Not Record list is never listed.

**2. Sort new clubs.** If the import found team names it has not seen, **Sort Clubs** offers them in
groups. Tick the names that belong to one club, say where it plays (a name, a map link, a what3words
address), and save. Import again and those clubs' away fixtures get their venue. To add many clubs at
once, paste a list from a spreadsheet at the top of the same screen.

**3. Look at each team.** **Team Overview** shows a team's captain, venue, leagues, next fixtures, how
many have said yes, maybe or no, who has not replied, and the squad with ratings.

**4. Ask who can play and pick the line-up.** On **Team Selection** choose the fixture and press
**Ask the squad if they can play**: each member of the squad gets an email with a link to answer yes, maybe or no (they
need no login). When the replies are in, pick the boards (or use **Fill from replies**, which puts the best rated players who said yes in first), save a draft, and
**Publish and tell the players**: the people picked are emailed, and anyone taken off is told. A line-up cannot be changed once
the fixture has started.

**5. Deal with applications.** New applications wait on **Members** under *Pending*. Approve or decline.
Approving makes someone a member; they pay for the season like everyone else. Tell people how to pay;
record the payment on their record when it arrives, or use **Mark paid** on the Members list.

**6. Seasons.** Membership is paid for every season. At the start of each season, open **Members → Seasons**
and press **Start new season**: everyone is marked as not paid and has to pay again, and you are asked to
check your teams (players and captains change) and to see who has not paid (**Unpaid this season** on
Members). Squads carry on into the new season unless you tick **Start every squad empty**; either way the
squads of the season that ended are kept and listed under **Squads by season**. Record each payment on the member's record with its date, method and amount. **Download payments
(CSV)** next to a season gives the treasurer a file that opens in Excel. The first season you start counts
the payments you have already recorded, so nobody is marked as unpaid by it.

**7. Renewals.** **Renewals** shows who is due a payment reminder at the next daily run. Reminders go out by
themselves if they are switched on under Settings, to members who have not paid for the season.

## Now and then

- **Tidy the squads.** Importing adds people to a squad when they play, and never takes anyone out.
  **Squad Review** lists people who have not played for a team in a year (you can change the number of
  months) with a tick box to take them out. They stay members.
- **Member Checks** lists records to tidy: members with no ECF code, or a code the ECF does not know.
- **Calendar.** Events are under **Club Events**. Mark an event **Cancelled** or **Moved** rather than
  deleting it, so nobody turns up for nothing. A repeating event has **Dates to skip** for holidays. A
  tournament can be put on the calendar from its own screen.
- **Tournaments.** **Create new**, then on the **Players** tab drag members into the tournament (or add
  someone who is not a member) and start it. Enter results on the **Results** tab, a round at a time.
  **Export** gives a CSV of the players and their results. The page the plugin makes for a tournament lists
  its status, players and the games still to play.

## When someone asks to be deleted

A person has the right to ask. Do these in order:

1. If they asked through the site (the Manage My Data block or WordPress's privacy tools), find the
   request under **Tools → Erase Personal Data**, check it is really them (it is confirmed by email), and
   run it. Otherwise find them on **Members**.
2. Use **Delete and do not record again** on their record (or, when you bulk-delete, tick *and do not record them again*). This
   removes their details, or, if the record is tied to a payment, photos or a tournament, removes the
   details and keeps the rest. It also adds them to **Do Not Record**, so the plugin does not make a
   record of them again by itself (for example when their ECF code turns up in a league import).
3. Their name stays on the results of tournaments already played. That is a legitimate record. If they
   object, open the tournament and use **Anonymise name** beside their entry.
4. Keep a copy of the **Do Not Record** list from time to time (**Download a backup**). It holds only
   one-way fingerprints, not names. If the site's secret keys (the salts in `wp-config.php`) are ever
   changed, the list stops working and has to be built again from the people who asked.

## Where to look when something is wrong

| What you see | Look at |
|---|---|
| No fixtures on the calendar | Overview → *Still to do*; League games (did the import run? any leagues failed?); Settings → *Test the LMS connection* |
| An away fixture has no venue | Sort Clubs: the other club's team names are probably still unsorted |
| A member says they never got an email | Members → their record: is the email right, and have they turned that kind of email off? |
| A block shows "No event called ..." | The league name does not match the LMS exactly (case and spaces); check it in the block settings |
| Something else | **Block Help** explains each block and what to set up first |
