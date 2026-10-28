# Asviora Games — v6 update (Spin limit · Visitor analytics · Active games fix · Govt Exam)

Ei document e ache: kon file **notun**, kon file **modify** hoyeche, database e ki
**add** hoyeche, ar live site e kivabe upload korben.

---

## 1. Live server e ki korte hobe (order ta mane rakhben)

1. **Database backup nin** — hPanel → phpMyAdmin → Export (ekbar).
2. Niche-r **NEW** ar **MODIFIED** file gulo FTP/File Manager diye upload korun
   (folder structure same rakhben).
3. Browser e ekbar khulun:

   ```
   https://asviora.in/cppsmigration.php?key=cpps-2026
   ```

   Page ta age dekhabe ki ki ache / nei. Tarpor **"Migration chalao →"** button e click korun.
4. Sob `done` / `ok` dekhale — **`cppsmigration.php` file ta server theke DELETE kore din.**
5. Admin panel e giye dekhun: `Visitors`, `Govt Exam`, ar `Daily Spin → Spin limits`.

> Migration ta auto-o chole: `includes/migrate.php` e `SCHEMA_VERSION` 6 kora ache, tai
> site er je kono page ekbar khullei `config/upgrade-v6.sql` nijei apply hoye jabe.
> `cppsmigration.php` ta holo **manual + diagnostic** option — jodi auto migration kono
> karone attke jay, ei page ta bole dey kothay attkeche. Duto-i idempotent, dubar chalale
> kichu nosto hoy na.

---

## 2. NEW files (7 ta)

| File | Ki kaj |
|---|---|
| `cppsmigration.php` | **Standalone migration runner.** Site er kono file include kore na — sudhu `config/config.php` theke DB credential pore ney. Server diagnostic + step-by-step report dekhay. Kaj sese delete korben. |
| `config/upgrade-v6.sql` | v6 schema — auto-migration ei file ta chalay. |
| `includes/visitors.php` | Visitor tracking library — page view log, device/browser detect, IP → country/region/city (cache soho). |
| `includes/govtexam.php` | Govt Exam module er logic — on/off, "soon" status, launch date, interest list. |
| `admin/visitors.php` | **Admin → Visitors** — full analytics report (day / month / year / location / page / device). |
| `admin/govt-exam.php` | **Admin → Govt Exam** — nav label, status, launch date, copy edit + interest list + CSV export. |
| `govt-exam.php` | Public coming-soon landing page + "notify me" form. |

## 3. MODIFIED files (12 ta)

| File | Ki bodleche |
|---|---|
| `includes/migrate.php` | `SCHEMA_VERSION` 5 → **6**, ar map e `6 => upgrade-v6.sql` add. |
| `includes/functions.php` | Sesh e `visitors.php` ar `govtexam.php` require kora hoyeche. |
| `includes/header.php` | (a) `track_visit()` call — protita page view log hoy. (b) Navbar e **Govt Exam** item + gold **SOON** badge. (c) Badge er CSS. |
| `includes/footer.php` | Footer "Explore" list e Govt Exam link + soon badge. |
| `includes/arena.php` | (a) Notun `arena_count()` / `arena_breakdown()`. (b) Chess games-table row duplicate hoye jeto — skip kora holo. (c) "Spins per day" ekhon hardcoded `1` na, admin setting theke ase. |
| `includes/spinaccess.php` | **Boro change.** Cooldown, weekly cap, monthly cap — sob `spin_status()` er bhitor enforce hoy, tai spin page ar API duto-i automatic mene chole. Notun helper: `spin_default_cooldown()`, `spin_default_weekly_cap()`, `spin_default_monthly_cap()`, `spin_limits_installed()`, `spin_used_since()`, `spin_cooldown_left()`, `spin_wait_text()`. |
| `admin/spin.php` | Notun **"Spin limits"** panel — per day / cooldown / weekly cap / monthly cap / player-ke dekhanor note. Purono bhul hint ("once per calendar day") thik kora holo. |
| `admin/spin-access.php` | Per-player override — cooldown, weekly cap, monthly cap. Table e notun "Limits" column. Blank rakhle site rule follow korbe. |
| `admin/index.php` | (a) **Active games fix** — ekhon `arena_count()` (6) dekhabe, sudhu games table (3) na. Alada line e "Arcade games" ar koyta hidden. (b) Notun **Visitors** section — today / 7 days / this month / countries + location ar top page list. |
| `admin/includes/layout.php` | Sidebar e **Visitors** (aajker view count badge soho) ar **Govt Exam** (signup count badge) menu add. |
| `spin.php` | Cooldown countdown button e, weekly/monthly limit line, admin er note dekhay. |
| `api/spin.php` | Response e `wait` field — cooldown thakle midnight er bodole cooldown er second return kore. |

---

## 4. Database e ki add holo

### Notun table (4 ta)

| Table | Ki rakhe |
|---|---|
| `visits` | Protita page view — date, hour, page, referrer, country/region/city, device, browser, OS, new visitor kina. IP er bodole ekta hash (`visitor_key`) rakha hoy. |
| `ip_geo` | IP → location cache. Ekta IP ekbar-i lookup hoy, tarpor cache theke. |
| `visit_daily` | Din-wise roll-up (views / visitors / members) — raw data purge korar poreo month & year total thik thake. |
| `exam_notify` | Govt Exam interest list (name, email, phone, kon exam, note). Email unique. |

### Notun column (3 ta — `spin_access` table e)

| Column | Type | Mane |
|---|---|---|
| `cooldown_minutes` | `INT UNSIGNED NULL` | NULL = site setting follow korbe |
| `weekly_cap` | `INT UNSIGNED NULL` | NULL = site setting, 0 = no cap |
| `monthly_cap` | `INT UNSIGNED NULL` | NULL = site setting, 0 = no cap |

### Index

- `spin_results` → `idx_spin_user_time (user_id, created_at)` — cooldown check er jonno.
- `spin_results` → purono `uq_spin_day` unique key thakle **drop** kora hoy
  (na sorale per-day limit 1 er beshi kaj korto na). v5 e already sorano ache,
  tobu safety hisebe abar check kora hoy.

### Notun settings row

**Spin:** `spin_default_per_day`, `spin_cooldown_minutes`, `spin_weekly_cap`,
`spin_monthly_cap`, `spin_limit_note`

**Analytics:** `analytics_enabled`, `analytics_geo_mode`, `analytics_ignore_admin`,
`analytics_ignore_bots`, `analytics_retention_days`, `analytics_salt`

**Govt Exam:** `govt_exam_enabled`, `govt_exam_status`, `govt_exam_label`,
`govt_exam_launch`, `govt_exam_tagline`, `govt_exam_blurb`

### Notun modules row

`govt_exam` ar `analytics` — Admin → Modules theke pura section on/off kora jabe.

---

## 5. Feature gulo kivabe kaj kore

### Daily spin limit — Admin → Daily Spin → *Spin limits*

- **Spins per player per day** — 0 dile keu spin korte parbe na (page ta lukono lage na).
- **Wait between spins (minutes)** — 0 = no wait. Per-day 3 dile ar 60 min gap dile,
  ekjon player din e 3 bar-i spin korbe kintu ek ghonta por por.
- **Weekly cap / Monthly cap** — rolling 7 / 30 din. 0 = no cap.
- **Note** — spin page e player ke ja dekhate chan (e.g. "Festival week — 3 spins a day").
- Ekjon player ke alada rule dite chaile: **Admin → Spin Access** e oi player er row e
  cooldown / weekly / monthly bhorun. Blank rakhle site rule follow korbe.

Sob check `spin_status()` er bhitore, ar spin ta database lock er bhitor likha hoy —
tai refresh kore ba dui tab khule limit bhanga jabe na.

### Visitor analytics — Admin → Visitors

- Range tab: Today / Yesterday / 7 / 30 / 90 days / This month / This year / All time.
- Per day bar chart, per month table, per year table, busiest hours.
- **Location in detail:** country table + country/region/city breakdown (last seen soho).
- Top pages, device, browser, referrer, ar latest views er live feed.
- **Location kivabe ber hoy:** age hosting header (Cloudflare `CF-IPCountry` — free, kono
  outside call na), na pele `ip_geo` cache, na pele ip-api.com e **ekbar** lookup
  (1-2 second hard timeout, request e sudhu ekta). Slow hole page atke thake na —
  oi view ta `pending` thake ar pore "Resolve pending locations" button e / porer request e
  fill hoye jay.
- Admin er nijer view by default count hoy na, bot-o na. Duto-i settings e off kora jay.
- Raw data retention default 365 din; purge + full wipe button ache.

### Active games fix

Dashboard `SELECT COUNT(*) FROM games WHERE is_active = 1` korto — mane sudhu arcade game
row (3 ta). Quiz, Spin ar Chess ognona thakto. Ekhon `arena_count()` use kore, jeta
`games.php` e ja tile dekhay thik seta-i gone — **6**. Sathe chess er duplicate row-o
skip kora holo, ar dashboard e alada line e arcade game count + koyta hidden dekhay.

### Govt Competitive Exam — coming soon

- Navbar e `Govt Exam` + gold **SOON** badge (mobile menu ar footer eo).
- Click korle `govt-exam.php` — ki ki asche, kon kon exam, ar "notify me" form.
- Launch date dile page e countdown dekhabe.
- Signup gulo **Admin → Govt Exam** e — kon exam koyjon chay tar breakdown + CSV download.
- Kaj sese status `soon` → `live` kore dile badge chole jabe.

---

## 6. Rollback

Kono karone firte chaile:

1. Purono file gulo (backup theke) firiye din.
2. `includes/migrate.php` e `SCHEMA_VERSION` abar `5` kore dilei notun migration ar chalbe na.
3. Notun table gulo (`visits`, `ip_geo`, `visit_daily`, `exam_notify`) thakleo purono code
   er kono khoti kore na — chaile drop korte paren.
