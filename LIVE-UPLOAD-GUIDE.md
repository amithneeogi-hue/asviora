# Live server update — Asviora Games schema v4

Ei guide-e: **kon kon file notun**, **kon kon file modify** hoyeche, **database-e ki ki
add hoyeche**, ar **kivabe live-e update korben** (asviora.in / Hostinger hPanel ba FTP).

Database-er kaj-er jonno ekta-i file: **`gamssmigration.php`** — root-e upload kore
ekbar browser-e khulben, byas.

---

## 0. TL;DR — 5 ta step

1. Live database-er **backup** nin (hPanel → phpMyAdmin → Export).
2. Niche-r **15 ta notun file** upload korun.
3. Niche-r **14 ta modified file** upload korun (purono gulo replace hobe).
4. Browser-e khulun: `https://asviora.in/gamssmigration.php?key=gamss-2026` → **"Migration chalao"**
   → sob ✔ ACHE dekhle kaj sesh.
5. **`gamssmigration.php` file ta server theke delete kore din.**

---

## 1. NOTUN FILE (15) — ei gulo age server-e chilo na

| # | File | Ki kaj |
|---|------|--------|
| 1 | `gamssmigration.php` | **Database migration + verification.** Ekbar chaliye delete korben |
| 2 | `chess.php` | Chess lobby — computer / challenge / open challenges |
| 3 | `chess-play.php` | Chess board page (AI mode ar staked match, dutoi) |
| 4 | `api/chess.php` | Chess API — move, join, resign, draw, payout |
| 5 | `includes/chess.php` | Server-side chess rules engine + match helper |
| 6 | `includes/quiz.php` | Level engine — level count, unlock, deal, 6-month repeat block |
| 7 | `includes/sheet.php` | .xlsx / .csv reader (bulk question upload) |
| 8 | `admin/modules.php` | Sob game + quiz + spin + chess live/hide switchboard |
| 9 | `admin/quiz-import.php` | Bulk question upload + CSV template |
| 10 | `admin/quiz-levels.php` | Level size, pass mark, repeat window, bulk marks, player progress |
| 11 | `admin/chess.php` | Chess settings + match list + void & refund |
| 12 | `assets/js/chess.js` | Chess rules + AI engine (browser side) |
| 13 | `assets/js/chess-play.js` | Board page controller (poll, clock, move send) |
| 14 | `assets/css/chess.css` | Board, clock, move list style |
| 15 | `config/upgrade-v4.sql` | v4 SQL (auto-migration ei file ta pore) |

> `config/upgrade-v4.sql` na upload korleo cholbe — `gamssmigration.php` er bhitorei
> puro SQL ta lekha ache. Kintu upload kore rakhai bhalo, karon
> `includes/migrate.php` bhobisyote oi file tai khoje.

---

## 2. MODIFIED FILE (14) — purono ta replace korte hobe

| # | File | Ki poriborton |
|---|------|----------------|
| 1 | `config/config.php` | 2 ta notun line: `includes/quiz.php` ar `includes/chess.php` require |
| 2 | `config/upgrade-quiz-proctor.sql` | **Bugfix** — settings-er column nam `key/value` chilo, ekhon `skey/svalue` |
| 3 | `includes/migrate.php` | `SCHEMA_VERSION = 4` + v4 migration map |
| 4 | `includes/header.php` | Menu-te Chess link (chess on thakle) |
| 5 | `includes/arena.php` | Games grid-e Chess card |
| 6 | `quiz.php` | Notun level map lobby (level mode off thakle purono card gulo dekhabe) |
| 7 | `quiz-play.php` | Level round, random deal, seen ledger, marks, result screen |
| 8 | `spin.php` | Spin access check (assign kora na thakle button off) |
| 9 | `api/quiz.php` | Marks scoring, level pass/fail, next-level button, per-question clock |
| 10 | `api/spin.php` | Spin access check (403 jodi allowed na hoy) |
| 11 | `admin/quiz.php` | Question form-e Marks + Seconds, list-e Marks column |
| 12 | `admin/users.php` | Prottek player-er pashe Spin access dropdown |
| 13 | `admin/spin.php` | Access mode (everyone / assigned) + player assign panel |
| 14 | `admin/includes/layout.php` | Sidebar-e Modules, Import Questions, Quiz Levels, Chess |

### `config/config.php` niye ekta kotha

Ei file-e apnar **live database password** ache. Amar deya version-e apnar
existing credentials-i ache (hostname dekhe local/live nije bujhe ney), tai
direct upload korle o problem nei.

Kintu jodi live-er config.php ta apni alada kore edit kore thaken, tahole
puro file replace na kore sudhu **2 ta line** add korun —
`includes/functions.php` require korar por:

```php
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/quiz.php';    // <-- notun
require_once ROOT_PATH . '/includes/chess.php';   // <-- notun
require_once ROOT_PATH . '/includes/arena.php';
```

---

## 3. DATABASE-e ki ki add hoyeche

Kichu **delete / drop hoy na**. Sudhu ADD ar CREATE. Purono data jemon ache temon-i thake.

### Notun table (3)

| Table | Ki rakhe |
|-------|----------|
| `quiz_level_progress` | Kon player kon difficulty-r kon level clear korlo, koto attempt, best % |
| `quiz_seen` | Kon player kon question kobe dekheche — **6 mash repeat block** ei table diye |
| `chess_matches` | Prottek chess match — stake, pot, FEN, moves, dujon-er ghori, result |

### Notun column (8)

| Table | Column | Kaj |
|-------|--------|-----|
| `quiz_questions` | `qcode` | Sheet-er Question ID (duplicate import atkay) |
| `quiz_questions` | `marks` | Ei question koto marks (default 10) |
| `quiz_questions` | `time_limit` | Ei question-er nijer second (NULL = level-er default) |
| `quiz_sessions` | `level_no` | Kon level khela hocche |
| `quiz_sessions` | `marks_total` | Round-e mot koto marks chilo |
| `quiz_sessions` | `marks_scored` | Koto marks pelo |
| `quiz_sessions` | `passed` | Level clear holo kina |
| `users` | `spin_access` | `default` / `allowed` / `blocked` — admin assign kore |

### Notun index (2)

- `quiz_questions.uq_qq_code` — same Question ID duibar dhukbe na
- `quiz_sessions.idx_qs_level` — level history query fast

### Notun settings (19)

`quiz_level_mode`, `quiz_questions_per_level`, `quiz_pass_percent`,
`quiz_levels_easy`, `quiz_levels_mid`, `quiz_levels_hard`, `quiz_repeat_months`,
`quiz_level_bonus`, `quiz_default_marks`, `spin_access_mode`,
`chess_enabled`, `chess_ai_enabled`, `chess_pvp_enabled`, `chess_min_stake`,
`chess_max_stake`, `chess_house_cut`, `chess_ai_reward`,
`quiz_strike_limit`, `quiz_require_fullscreen`

Sesh-e `schema_version` = **4** hoye jay.

---

## 4. Upload korar niyom

### Option A — hPanel File Manager (sohoj)

1. hPanel → **File Manager** → `public_html` (ba je folder-e site ache).
2. Upar-er 29 ta file (15 notun + 14 modified) same folder structure mene upload korun.
   Folder gulo age thekei ache — sudhu bhitore file ta rakhben:
   - root-e: `gamssmigration.php`, `chess.php`, `chess-play.php`, `quiz.php`, `quiz-play.php`, `spin.php`
   - `api/` te: `chess.php`, `quiz.php`, `spin.php`
   - `includes/` te: `chess.php`, `quiz.php`, `sheet.php`, `migrate.php`, `header.php`, `arena.php`
   - `admin/` te: `modules.php`, `quiz-import.php`, `quiz-levels.php`, `chess.php`, `quiz.php`, `users.php`, `spin.php`
   - `admin/includes/` te: `layout.php`
   - `assets/js/` te: `chess.js`, `chess-play.js`
   - `assets/css/` te: `chess.css`
   - `config/` te: `config.php`, `upgrade-v4.sql`, `upgrade-quiz-proctor.sql`
3. Overwrite / Replace chaile **Yes** din.

### Option B — zip diye (fast)

1. `asviora-v4-update.zip` ta `public_html`-e upload korun (29 ta file-i bhitore ache,
   `gamssmigration.php` soho).
2. File Manager → zip-er upor right click → **Extract** → destination `public_html`.
3. "Overwrite existing files" tick din.
4. Zip file ta delete kore din.

### Option C — FTP (FileZilla)

Local `D:\xamp\htdocs\gamesv` theke oi 29 ta file drag kore live-er same folder-e
felun, "Overwrite" bechhe nin.

---

## 5. Migration chalanor niyom

Browser-e khulun:

```
https://asviora.in/gamssmigration.php?key=gamss-2026
```

- Prothome ekta **preview** dekhabe — ki ki hobe.
- **"Migration chalao"** e click korun.
- Result table-e prottek step: `HOYECHE` / `AGE CHILO` / `FAIL`.
- Niche **Database verification** — 24 ta jinis, sob `✔ ACHE` hote hobe.
- Tar niche **File verification** — kono file miss ba purono thakle nam soho bole debe.
- Baar baar chalale kono khoti nei.

Key ta palte chaile `gamssmigration.php`-er sururi line-e:

```php
const MIGRATION_KEY = 'gamss-2026';
```

Admin panel-e login kora thakle key chharai chole.

**Kaj sesh hole file ta delete korun.**

> Note: file upload-er por site-er je kono page khullei `includes/migrate.php`
> nije-o v4 migration ta chalie dey. `gamssmigration.php` ta holo niyontrito
> ar verify-kora rasta — ki holo ki holo na, chokhe dekha jay.

---

## 6. Upload-er por checklist

- [ ] `https://asviora.in/admin/` → sidebar-e **Modules**, **Import Questions**, **Quiz Levels**, **Chess** dekha jacche
- [ ] Admin → **Modules** → sob mode-er pashe "live" badge
- [ ] Site-er menu-te **Chess** dekha jacche
- [ ] Admin → **Import Questions** → QSTN.xlsx → sheet number **2** → Import
- [ ] Admin → **Quiz Levels** → level size 10, pass mark 60, repeat 6 mash
- [ ] Site → **Quiz** → level map (chip) dekha jacche, Level 1 khela jacche
- [ ] Site → **Chess** → computer-er sathe ek game
- [ ] 2 ta test account diye ek ta stake match (link share kore)
- [ ] Admin → **Daily Spin** → access mode dekhe nin
- [ ] `gamssmigration.php` delete kora hoyeche

---

## 7. Kichu bhul hole

| Somossa | Ki korben |
|--------|-----------|
| Sada page / 500 error | `config/config.php`-e oi 2 ta require line ache kina dekhun; `includes/quiz.php` ar `includes/chess.php` upload hoyeche kina dekhun |
| "Chess is not available" | Admin → Modules → Chess "Go live" korun. Table na thakle migration chalan |
| Quiz-e purono card, level map nei | Admin → Quiz Levels → **Level mode** on korun |
| Import-e "No Question column found" | Sheet number thik din (apnar GK sheet = **2**), header row prothome ache kina dekhun |
| Import-e boro file atke jacche | hPanel → PHP Config → `upload_max_filesize` ar `post_max_size` barie nin |
| Chess-e prothom move-eই "time out" | Purono `api/chess.php` royeche — notun ta upload korun (timezone fix oitatei ache) |
| Migration FAIL dekhacche | Error message ta niye janan; `schema_version` bump hoy na, tai abar chalate paren |
| `gamssmigration.php` khullei **500** | Notun standalone version ta upload korun — seta kono file-er upor depend kore na ar error ta chokhe dekhay. Sob theke common karon: `config/config.php` notun ta upload hoyeche kintu `includes/quiz.php` / `includes/chess.php` upload hoyni |
| Puro site-i 500 | Same karon. `includes/quiz.php` ar `includes/chess.php` upload korun. Nahole hPanel → **Error log** (ba `public_html/error_log` file) khule sesh line ta dekhun |
| PHP version kom | Ei code-er jonno **PHP 8.0+** lagbe. hPanel → **PHP Configuration** → 8.1 ba 8.2. `gamssmigration.php` khullei upor-e version ta dekhabe |

---

## 8. Local (XAMPP) ar live — dutor moddhe pother tofat

`D:\xamp\htdocs\gamesv` te sob kichu **age thekei install kora ache** (ami kore diyechi),
ar local database-e migration nijer theke chole geche. Live-e sudhu file upload +
`gamssmigration.php` — ei duto kaj.

Purono file-er backup local-e ache: `_update\backup-before-v4\`
