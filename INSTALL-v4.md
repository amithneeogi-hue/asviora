# Asviora Games — v4 update

Everything you asked for, in one drop. Copy the files over your site,
open any page once, and the database migrates itself.

---

## 1. Install

1. **Back up** `D:\xamp\htdocs\gamesv` and export the database first.
2. Copy every file from this package into the site folder, keeping the
   folder structure. Existing files that are meant to be replaced are
   listed at the bottom.
3. Open any page of the site (or the admin panel) **once**.
   `includes/migrate.php` sees the database is on schema 3, applies
   `config/upgrade-v5.sql`, and bumps it to 4. It runs once and costs
   nothing afterwards.
4. Open **Admin → Modules**. Everything is live by default.

Nothing is destructive: the migration only creates tables, adds columns
and inserts settings. Existing questions, players, scores and coins are
untouched.

---

## 2. What each thing you asked for became

### Hide / live for every game *and* the quiz

**Admin → Modules** is one screen with two tables.

- **Site features** — quiz, spin, chess, online chess, the arcade,
  leaderboards, blog, and the mobile API. Each has a *Take it live* /
  *Hide it* button and a separate switch for whether it shows in the menu.
- **Games** — every row in the games catalogue, including chess, with the
  same one-click toggle, plus the featured star.

Hiding is enforced on the server, not just in the menu: a player who
bookmarked `quiz.php` gets redirected, and the mobile API stops serving
that module too, so the app's tab disappears on its next launch.

### Chess

`games/chess/` — three modes, all in one game:

- **vs Computer** at Easy / Medium / Hard. The engine is a real one:
  0x88 board, full legal move generation, alpha-beta search with a
  capture-only extension at the leaves and piece-square tables. It was
  checked against the standard perft counts — 20 / 400 / 8,902 / 197,281
  from the start, 48 / 2,039 / 97,862 on Kiwipete — all exact.
- **2 Players** on one device, with a board flip.
- **Online** against another player: quick match, or a six-character code
  to share with a friend.

Castling, en passant, promotion, checkmate, stalemate, the fifty-move
rule, insufficient material and threefold repetition are all handled.

Online games are refereed by the server. `includes/chessrules.php` is a
PHP port of the same generator, and every move is re-generated from the
stored position before it is accepted — so a modified client cannot move
out of turn, make an illegal move, or claim a win it did not earn. It
passes the same perft checks as the JavaScript.

Settings and a live match list are in **Admin → Chess**.

### Bulk question upload

**Admin → Bulk Upload**. Takes `.xlsx` and `.csv`, and the layout of your
`QSTN.xlsx` works exactly as it is:

```
Question ID | Category | Difficulty | Question | Option A–D |
Correct Answer | Explanation | Time Limit (sec) | Marks
```

- Columns are matched **by heading**, so their order does not matter and
  the wording can vary (`Correct Answer`, `Answer`, `Key` all work).
- Difficulty accepts Easy / Medium / Mid / Hard, and also beginner,
  moderate, advanced and so on.
- The correct answer can be `A`–`D`, `1`–`4`, or the answer written out.
- Topics that are in the sheet but not on the site are created for you.
- **Nothing is written until you confirm.** The upload is checked first
  and shown back to you: how many are ready, which look like duplicates
  and why, which rows were rejected and on which line.
- A duplicate is a question whose text and first two options already
  exist, or whose Question ID is already used.
- Every upload is logged, and **Undo** removes exactly the questions that
  upload added.

The `.xlsx` reader is written into the site (zip + XML), so there is no
library to install. It was tested against your actual `QSTN.xlsx`.

### Levels: 300 questions = 30 levels

**Admin → Quiz Levels** sets the level size (default 10), the pass mark,
the clear bonus and the no-repeat window — globally, or per topic.

The number of levels follows the bank on its own: 300 questions at 10 a
level is 30 levels, and uploading 50 more makes it 35 without anyone
touching a setting.

**The ladder** works exactly as you described it:

- Level 2 opens when level 1 is passed, level 3 when level 2 is, and so on.
- **Mid opens only when every Easy level has been passed.** Hard opens
  only when every Mid level has been passed.
- A locked difficulty says how many levels are still left rather than
  just refusing.
- Stars: one for a pass, two at 80%, three for a perfect level. The
  clear bonus is paid once, the first time a level is beaten.

### A question does not come back for six months

Every question served is written to `quiz_question_history` the moment it
is answered — so a round abandoned halfway still counts as seen.

Anything seen inside the window is filtered out of that player's next
draw and replaced from the unseen pool. The window is **180 days by
default and set by the admin** (Quiz Levels → *Do not repeat a question
for*). Set it to 0 to switch the rule off.

If a player has worked through so much of the bank that a level cannot be
filled with unseen questions, the ones they saw **longest ago** come back
first, and the round says so rather than silently repeating.

### You and I get different sets

Every account carries its own random `quiz_seed`. Before the bank is cut
into levels it is re-ordered by `MD5(question id + that seed)` — so the
cut itself is different per player.

Two people sitting side by side on level 3 of Easy are answering
different questions from the same bank. It is not random per attempt
(which would make the map meaningless) — it is a stable, personal
shuffle, so their level 3 is always their level 3.

You can reset a player's progress or clear their history from
**Admin → Quiz Levels → Player progress**.

### Spin access assigned by the admin

**Admin → Spin Access**.

- Two modes: **Everyone** (any signed-in player gets the default spins a
  day) or **Assigned only** (nobody spins until you grant them).
- Per player: how many spins a day, one-off bonus spins, a valid-from /
  valid-until window, and a private note.
- Buttons on each row for grant, switch off, +1 spin, and reset to the
  site default. There is also *apply to all active players*.

More than one spin a day is now possible, so the old one-row-per-day
database key had to go; the allowance is counted in code instead, inside
a MySQL named lock, so two tabs clicking together cannot both slip
through.

### Marks edited by the admin

**Admin → Marks & Scores**, with tabs for quiz rounds and game scores.

- Edit the marks, the coins, the status or the result.
- **A reason is required**, and every change is written to `score_edits`
  with who made it, what it was before and what it became. Edited rows
  carry an "edited" badge, and the full log is on the same page.
- Coin differences go through the normal coin ledger, so a player's
  balance and their transaction history never drift apart.
- Changing a quiz mark **re-decides the level**: if the corrected score
  now passes, the next level unlocks exactly as it would have.

Questions also carry a **Marks** value from the upload sheet, so a round
has both a correct-answer count and a marks total.

### The Android app

`app/asviora-android/` — a real Android Studio project in Java, not a
wrapper. See the README in that folder.

The screens are native and they talk to the new `/api/v1`. The one
WebView in the project renders a game board, because chess, sudoku,
memory and the cube are canvas/WebGL and there is no sense writing them
twice.

---

## 3. The API folder

`api/v1/` — one front controller, one place to read the whole contract.

```
GET  /api/v1/config                 what is switched on, app version
POST /api/v1/auth/register          → bearer token
POST /api/v1/auth/login
POST /api/v1/auth/logout
GET  /api/v1/auth/me

GET  /api/v1/games
GET  /api/v1/leaderboard?game=&period=today|week|month|all

GET  /api/v1/quiz/topics
GET  /api/v1/quiz/overview          the three ladders + progress
GET  /api/v1/quiz/levels?difficulty=easy&cat=0
POST /api/v1/quiz/start             {difficulty, level, cat}
POST /api/v1/quiz/question          {session}
POST /api/v1/quiz/answer            {session, choice}
POST /api/v1/quiz/finish            {session}

GET  /api/v1/spin/status
POST /api/v1/spin/play

GET  /api/v1/profile
POST /api/v1/profile/update

POST /api/v1/chess/find|create|join|state|move|resign|draw
```

Every response has the same envelope:

```json
{ "ok": true,  "data": { } }
{ "ok": false, "error": { "code": "...", "message": "..." } }
```

Auth is `Authorization: Bearer <token>`. Only the sha256 of a token is
stored, tokens expire (60 days by default, in Settings) and each device
gets its own, so signing out on a lost phone cuts off only that phone.

Both URL forms work — `/api/v1/quiz/levels` when `mod_rewrite` is on, and
`/api/v1/index.php?r=quiz/levels` always. The app uses the second, so it
works on any host and in any subfolder.

---

## 4. Files in this package

**New**

```
config/upgrade-v5.sql              the migration (runs itself)
includes/modules.php               hide / live switches
includes/quizlevels.php            the level engine
includes/spinaccess.php            who may spin
includes/marks.php                 admin edits + audit trail
includes/sheet.php                 xlsx / csv reader, no library
includes/chessrules.php            chess rules, server side
includes/chesslobby.php            matchmaking, moves, results
admin/modules.php                  hide / live for everything
admin/quiz-import.php              bulk upload
admin/quiz-levels.php              level rules + player progress
admin/spin-access.php              spin allowances
admin/marks.php                    marks & scores editing
admin/chess.php                    online chess control
api/chess.php                      browser chess endpoint
api/v1/index.php                   the mobile API
api/v1/bootstrap.php               tokens, envelope, rate limiting
api/v1/.htaccess
games/chess/index.html             the game
games/chess/engine.js              the chess engine
app/asviora-android/               the Android app
```

**Replaced** — keep a copy of the originals if you have local edits:

```
includes/migrate.php     schema version → 5
includes/functions.php   loads the new libraries; quiz_on()/spin_on()
                         now also respect the module switch
quiz.php                 the level map
quiz-play.php            level-aware rounds
api/quiz.php             history, marks, level completion
spin.php                 allowance instead of once-a-day
api/spin.php             allowance + named lock
games.php                honours the arcade switch
admin/includes/layout.php  new menu entries
```

`config/config.php` is **not** touched — your database credentials stay
exactly as they are.

---

## 5. Worth knowing

- The `storage/` folder needs to be writable: uploads waiting to be
  confirmed live in `storage/imports`, and the API's rate limiter uses
  `storage/ratelimit`.
- `.xlsx` needs PHP's `zip` extension. It is on by default in XAMPP and
  on Hostinger. If it is off, the upload screen says so and CSV still
  works.
- Delete `install.php` and `reset-admin-password.php` if they are still
  there.
- Set `DEBUG_MODE` to `false` in `config/config.php` before going live —
  it already switches itself off on the real domain.
