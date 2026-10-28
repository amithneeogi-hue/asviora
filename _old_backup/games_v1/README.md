# Asviora Games — *Where Skill Wins*

A PHP + MySQL skill-gaming platform with three playable browser games, a blog, and a full
admin panel. Dark premium theme built from the logo palette (gold + lime on charcoal),
mobile-responsive throughout.

---

## Setup (XAMPP)

1. **Start Apache and MySQL** from the XAMPP control panel.

2. **Run the installer:** <http://localhost/games/install.php> → click **Install now**.
   It creates the `asviora_games` database, all 10 tables and the seed content, and shows a
   step-by-step log. Localhost only.

   *Manual alternatives if you prefer:* phpMyAdmin → *Import* → `config/database.sql`, or
   ```
   C:\xampp\mysql\bin\mysql -u root -p < D:\xampp\htdocs\games\config\database.sql
   ```

3. **Check the credentials** in `config/config.php` if the installer cannot connect.
   XAMPP defaults (`root`, empty password) are already set, so usually nothing to change.

4. **Open the site:** <http://localhost/games/>

5. **Open the admin panel:** <http://localhost/games/admin/>

   | | |
   |---|---|
   | Username | `admin` |
   | Password | `Admin@123` |

   Change this immediately from **Admin Users → Edit**.

6. **Delete `install.php` and `reset-admin-password.php`.** Both are localhost-only helpers.
   Once the site is running and you can sign in, remove them.

---

## What's included

### Games (all HTML5, no plugins, touch-friendly)

| Game | What it is |
|---|---|
| **3D Cube Solver** | Real WebGL Rubik's cube (Three.js). Drag to orbit, keyboard `U D L R F B` (Shift = reverse), four scramble depths, step-by-step **hint** system and an animated **auto-solver**. Auto-solved rounds are deliberately unscored. |
| **Memory Match** | Four grid sizes, combo streak multipliers, move counter, one-per-game "peek" that costs 20% of the score. |
| **Sudoku** | Generator produces puzzles with a **guaranteed unique solution**. Four difficulties, pencil-mark notes, 3 hints, 3-mistake limit, live validity checker. |

Scores flow: game iframe → `postMessage` → `assets/js/main.js` → `api/submit_score.php`,
which validates server-side (one-time play token, value ceilings, rate limit, minimum
plausible duration) before awarding points, coins and XP.

### Public site

`index.php` home · `games.php` catalogue · `play.php` game player with side leaderboard ·
`leaderboard.php` (per-game + all-games, today/week/month/all-time, podium) ·
`blog.php` + `post.php` · `about.php` · `contact.php` · `register.php` / `login.php` /
`dashboard.php` (overview, match history, coin wallet, profile editing, referral link) ·
`404.php`.

### Admin panel (`/admin`)

- **Dashboard** — headline stats, 14-day activity chart, game performance, latest rounds,
  newest players, inbox preview
- **Blog** — list with filters, and a rich-text editor (headings, lists, links, images,
  quotes, code, HTML source toggle, word count, auto-slug, cover upload, draft/publish,
  featured flag, SEO meta fields)
- **Categories** — create, rename, delete
- **Games** — add/edit/hide/feature/reorder titles, set coin rewards, upload thumbnails
- **Players** — search, filter, sort, suspend/ban, adjust coin balance, reset password, delete
- **Scores** — full history with heuristic fair-play flags
- **Messages** — contact-form inbox with reply/read/delete
- **Subscribers** — newsletter list with CSV export
- **Settings** — site name, tagline, meta description, contact details,
  **Facebook / Instagram / YouTube / WhatsApp / X links**, and the coin economy values
- **Admin Users** — three roles: `super` (everything), `manager` (all but admin users),
  `editor` (blog + inbox only)

---

## Social links

Facebook and Instagram are seeded as placeholders
(`facebook.com/asvioragames`, `instagram.com/asvioragames`) and appear in the footer, on the
contact page and in the player dashboard.

Replace them with your real URLs in **Admin → Site Settings → Social links**. Clearing a
field hides that icon everywhere — no code changes needed.

---

## Virtual coins, not real money

The platform uses **virtual coins only**. There is no deposit, no withdrawal and no
wagering anywhere in the code or database. Coins come from the signup bonus, daily login
bonus, referrals and game rewards, and are spent on entry fees.

This is deliberate: India's Promotion and Regulation of Online Gaming Act, 2025 prohibits
real-money online games. If you ever intend to add real-money play, get legal advice for
your jurisdiction first — it is not a matter of dropping in a payment gateway.

---

## Project layout

```
games/
├── index.php  games.php  play.php  leaderboard.php
├── blog.php  post.php  about.php  contact.php  404.php
├── register.php  login.php  logout.php  dashboard.php
├── reset-admin-password.php      ← delete after first use
├── .htaccess                    ← security headers, caching, error pages
├── config/
│   ├── config.php               ← DB credentials + settings
│   ├── database.sql             ← import this
│   └── .htaccess                ← blocks web access to this folder
├── includes/
│   ├── db.php                   ← PDO connection + query helpers
│   ├── functions.php            ← escaping, CSRF, flash, coins, uploads
│   ├── header.php  footer.php
├── api/
│   └── submit_score.php         ← validates and stores game results
├── assets/
│   ├── css/style.css  game.css  admin.css
│   ├── js/main.js  game-sdk.js
│   └── img/logo.jpg
├── games/
│   ├── cube3d/index.html
│   ├── memory/index.html
│   └── sudoku/index.html
├── admin/
│   ├── index.php  blog.php  blog-edit.php  categories.php
│   ├── games.php  users.php  scores.php  messages.php
│   ├── subscribers.php  settings.php  admins.php
│   ├── login.php  logout.php  .htaccess
│   └── includes/auth.php  layout.php  layout_end.php
└── uploads/
    ├── blog/  avatars/  games/
    └── .htaccess                ← PHP execution disabled here
```

---

## Adding a fourth game

1. Create `games/<yourgame>/index.html`.
2. Load the SDK: `<script src="../../assets/js/game-sdk.js"></script>`
   (and `../../assets/css/game.css` for the matching shell styling).
3. When a round ends, call:
   ```js
   Asviora.submit({
     score: 1250, time_taken: 94, moves: 38,
     difficulty: 'medium', result: 'win', meta: 'optional note'
   });
   ```
4. Register it in **Admin → Games** with the folder path `games/<yourgame>`.

That is all — leaderboards, coin rewards, XP, match history and the play page come for free.

---

## Security notes

- Prepared statements everywhere (PDO, emulation off)
- CSRF tokens on every state-changing form
- `password_hash` / `password_verify` with bcrypt
- Login throttling on both player and admin login
- Admin-authored HTML is sanitised (`safe_html`) before it is stored and rendered
- Uploads: extension + MIME + `getimagesize` validation, randomised filenames,
  PHP execution disabled in `uploads/`
- Score submissions require a one-time play token and pass through value ceilings,
  a 40-per-hour rate limit and a minimum-duration check
- Set `DEBUG_MODE` to `false` in `config/config.php` before going live

---

## Verified before delivery

- All 36 PHP files pass a delimiter/string/heredoc structural check
- All JavaScript (standalone, inline and heredoc-embedded) passes `node --check`
- Schema checked: 10 tables, foreign keys resolve, creation order correct,
  every seed INSERT matches its table's columns and arity
- Cube engine: **24/24** unit tests — face turns, `X⁴ = identity`, no cubelet collisions
  over 200 random moves, 400 random scrambles all solved correctly by the auto-solver,
  hint-following reaches a solved cube, deviate-and-undo keeps the solution valid
  *(this run found and fixed a real bug where hints oscillated instead of converging)*
- Sudoku generator: **20/20** tests — valid completed grids, givens always consistent
  with the solution, **uniqueness verified** at all four difficulties, worst-case
  generation 174 ms
- Seeded admin password hash verified against `Admin@123`
