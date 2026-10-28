# Rebuild notes — Quiz, Spin, Branding

Everything from the handwritten `changes.jpeg` note, point by point.

## Migration — nothing for you to do

**The migration runs itself.** Just open the site
(`http://localhost/games%202.0/`) and the first page load applies it.

How it works: `includes/migrate.php` compares a `schema_version` setting against
the code's expected version. If the database is behind, it applies the matching
file from `config/` inside a lock, then bumps the version. On every request after
that it is a no-op, so it costs nothing. Every statement is idempotent
(`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`), so an interrupted run just picks
up where it left off.

To confirm it worked, open **`migrate.php`** — it lists each table, its row count
and the schema version, and has a force re-run button if anything looks off.
Delete that file once you have seen it green.

Manual route, if you prefer:

```
mysql -u root -p asviora_games < config/upgrade-quiz-spin.sql
```

A fresh install from `config/database.sql` already includes everything.

---

## 1. Logo on the page background

- The whole palette now comes out of the logo: neon cyan `#22d3ee` and electric
  purple `#a855f7` on a deep midnight base, with a faint hex-grid echoing the
  honeycomb texture inside the shield.
- The logo itself sits behind **every page** as a slowly breathing watermark
  (`body::after`, ~5% opacity, `mix-blend-mode: screen`).
- The logo also fills the hub of the spin wheel.
- **Admin → Site Settings → Logo** now has a real uploader. Whatever you upload
  becomes the header, footer, favicon, social preview *and* the background
  watermark — no file editing on the server.

## 2 + 5. Quiz contest

Player side:

- `quiz.php` — lobby: pick a topic and one of the three levels.
- `quiz-play.php` — the round itself.

| Level | Time per question | Coins per correct |
|-------|------------------|-------------------|
| Easy  | 30 s             | +10               |
| Mid   | 20 s             | +25               |
| Hard  | 15 s             | +50               |

All nine numbers are editable in **Admin → Site Settings → Quiz contest**.

- 10 questions per round (configurable), animated countdown ring per question.
- Correct answers pay coins and XP immediately; a perfect round pays a bonus.
- 5 rewarded rounds per day per player — beyond that rounds still score but
  stop paying, so the economy can't be farmed.
- Explanation shown after each answer.
- Answer keys by keyboard too: `1-4` or `A-D`.

**Copy protection (the "not able to copy" line):**

- Text selection, right-click, copy, cut, paste and drag are all blocked on the
  question and answer block.
- `F12`, `Ctrl+Shift+I/J/C/K`, `Ctrl+U`, `Ctrl+S`, `Ctrl+P`, `Ctrl+A` blocked.
- Printing the page hides the quiz content.
- Most importantly: **the correct answer is never in the page source.** The page
  fetches one question at a time from `api/quiz.php` and only learns which
  option was right *after* the player commits. The timer is also validated
  server-side, so a paused clock or an edited script doesn't help.

Admin side:

- **Admin → Contests → Quiz Questions** — full CRUD, filters by level/topic/text,
  per-question accuracy stats, optional image, show/hide toggle.
- **Admin → Contests → Quiz Topics** — categories.
- 31 starter questions across 4 topics are seeded so it works immediately.

## 3. Mail ID, Facebook, Instagram

- New slim **top bar** above the header: email, phone and every social icon.
- Footer already listed them; WhatsApp added and the "Explore" column now links
  Quiz and Spin.
- All values live in **Admin → Site Settings** — leave one blank and it
  disappears from the site automatically.

## 4. Daily spin

- `spin.php` — an SVG prize wheel with the logo in the hub, one free spin per
  player per calendar day, live countdown to the next one.
- The result is picked **on the server** using weighted odds; the browser only
  gets told where to stop. The once-a-day rule is a unique database key on
  `(user_id, spin_date)`, so refreshing, double-clicking or opening a second tab
  cannot produce a second payout.
- **Admin → Contests → Daily Spin** — edit coin amounts, weights and colours per
  slice, see live odds, payout totals and recent spins. Weight `0` removes a
  slice; two blank rows are always available for adding new ones.

## 6. Username not showing after register

The account was being created correctly — the username was being hidden by CSS.
`.user-btn span` was `display:none` below 780px and the entire `.user-menu` was
`display:none` below 400px, so on a phone (where most people register) the name
vanished and the account menu with it.

Now the username is visible at **every** width:

- desktop → inside the account pill
- ≤780px → moves to its own "Hi, *name*" chip
- ≤470px → the mobile drawer opens with a full identity card (avatar, real name,
  `@username`, level, coin balance)

The account dropdown is no longer hidden on small screens, and it now shows
`@username` alongside the display name.

---

## New files

```
quiz.php                        quiz lobby
quiz-play.php                   the timed round
spin.php                        daily spin wheel
api/quiz.php                    quiz engine (server-authoritative)
api/spin.php                    spin engine (server-authoritative)
admin/quiz.php                  question bank
admin/quiz-categories.php       quiz topics
admin/spin.php                  wheel config + payout history
config/upgrade-quiz-spin.sql    the migration
```

## One thing you may want to change

The site name still reads **Asviora Games** in the database while the logo says
**AEGIS ESPORTS**. Update it in **Admin → Site Settings → General → Site name**
and it changes everywhere at once.
