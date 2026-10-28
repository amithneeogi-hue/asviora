# Asviora Games — Phase 1 (website) install notes

Schema v4. `includes/migrate.php` nije `config/upgrade-v4.sql` chaliye debe — site-er
je kono ekta page ekbar khullei migration hoye jabe. Alada kore SQL import korte hobe na.

Backup: `_update/backup-before-v4/` — 14 ta purono file er copy sekhane ache.

---

## 1. Admin → Modules (notun page)

Ekta screen theke sob kichu live / hide:

- prottek game (Cube3D, Memory, Sudoku, ba notun ja add korben)
- Quiz Contest
- Quiz level mode (on/off)
- Daily Spin
- Chess (puro), Chess vs computer, Chess challenges — alada alada switch

"Everything live" / "Hide everything" button diye ek click e sob.

## 2. Admin → Import Questions (bulk upload)

`QSTN.xlsx` jemon ache temon i upload kora jabe — .xlsx, .csv, .tsv.

- Column gulo nam dekhe nije bujhe ney: Question ID, Category, Difficulty, Question,
  Option A–D, Correct Answer, Explanation, Time Limit, Marks
- Correct Answer letter (`B`), number (`2`) ba puro option text — tinta i chole
- Sheet number box: 0 = first sheet, 2 = apnar GK sheet
- Notun Category automatic toiri hoye jay
- Duplicate: Question ID (ba question text) mile gele skip ba update
- Test kora hoyeche: apnar GK sheet er **999/999 row** parse hoyeche

Template chaile: Import page → "Download CSV template".

## 3. Levels (Easy → Mid → Hard)

- Ekta level = N ta question (Admin → Quiz Levels → "Questions in one level", default 10)
- **300 question ÷ 10 = 30 level** — bank er size theke level count automatic
- Chaile difficulty-wise fix kore dite paren (Easy = 5 level, etc.)
- Pass mark **marks-e** mapa hoy (default 60%) — question-er marks alada alada hote pare
- Easy-r sob level clear na hole Mid khulbe na; Mid sob clear na hole Hard khulbe na
- Clear kora level abar khela jabe (practice), kintu bonus ekbar-i
- Level clear korle bonus coin (default 50)

## 4. Random set + 6 mash repeat block

- Level-er question **fixed na** — round shuru howar somoy random draw hoy
- Tai ami ar apni same level khullei **alada question** pabo (test-e overlap = 0)
- Ekbar dekha question oi player-er kache **6 mash** (admin set kore) ar asbe na
- Question "burn" hoy deal-er somoy — mane round chere diye palanor niyom nei
- Admin → Quiz Levels → prottek player-er progress + "Levels" / "History" reset button

## 5. Daily Spin access

- Admin → Daily Spin → mode: **Everyone** ba **Only players I assign**
- Username / email diye allow ba block
- Admin → Players tabil-e prottek player-er pashe "Spin" dropdown (Site rule / Allowed / Blocked)
- Block kora player api/spin.php theke o 403 pabe

## 6. Marks (creator marks edit)

- Prottek question-er nijer **marks** ar nijer **seconds** (0 = level-er default)
- Admin → Quiz Questions form-e edit
- Admin → Quiz Levels → "Marks in bulk": ek difficulty ba ek topic-er sob question e ek click e marks

## 7. Chess

`chess.php` (lobby) · `chess-play.php` (board) · `api/chess.php` · Admin → Chess

**Computer-er sathe:** Easy / Medium / Hard. Jite coin (default 25 × level), dine 3 bar porjonto.

**Player vs player (staked):**
1. Stake (coin) + clock (3/5/10/15/30 min) choose kore challenge toiri
2. Invite **link** copy kore pathanoi jay — ba lobby-r "Open challenges" theke keu accept korbe
3. Dujon-i signup kora thakte hobe, dujon-er theke-i stake kete ney
4. Rong (white/black) random
5. Je jete **puro pot** pay. Draw hole dujon-er stake ferot
6. Time sesh hole flag fall — server-er ghori, browser-er na

Admin → Chess: min/max stake, house cut %, AI reward, ar prottek match — stuck match
"Void & refund" korle dujon-er stake ferot chole jay.

**Cheating protection:** prottek move server-e re-validate hoy (PHP chess engine).
Illegal move = 422, onner turn-e move = 409. Engine perft-test kora
(start/kiwipete/endgame position — sob standard number mileche), tai castling,
en passant, promotion, stalemate, insufficient material sob thik ache.

---

## Test kore dekhun (local XAMPP)

1. `http://localhost/gamesv/admin/` → admin / Admin@123
2. Import Questions → QSTN.xlsx → sheet number **2** → Import
3. Quiz Levels → level size / pass mark dekhun
4. Site-e Quiz → level map dekhben, Level 1 khelun
5. Modules → kichu ekta hide kore site-e refresh korun
6. Chess → computer-er sathe ek game, tarpor 2 ta account diye stake match

## Phase 2 (next)

Android Java app (native, wrapper na):
- `api/` folder-e token-based auth + REST endpoints (login, profile, games, quiz levels,
  spin, chess, leaderboard)
- Android Studio project: Java + Gradle, Retrofit/OkHttp, screen gulo native
