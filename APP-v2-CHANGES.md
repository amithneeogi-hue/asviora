# Asviora app — v2 update (site v6 + login-gate er sathe mil kore)

Website e Aug 21 (v6) ar Sep 1 (login gate) e ja ja bodleche, sei onujayi
Android app ta upgrade kora holo. Ei document e ache: kon file **notun**,
kon file **modify** hoyeche, ki keno kora holo, ar kivabe test/upload korben.

App version: **versionCode 1 → 2**, versionName **1.0 → 1.1**

---

## 1. Keno lagbe — je 3 ta problem chilo

### (a) App er Games tab puropuri bhenge chilo  ← sob theke boro

Sep 1 e `require_play_login()` ese `play.php`, `games.php`, `quiz.php`,
`spin.php` sob login er pichone chole gelo, ar `games/.htaccess` diye
`games/<slug>/index.html` 403 kore deya holo. Sathe
`api/v1/bootstrap.php` er `play_url` bodle gelo:

```
url($g['path'] . '/index.html')      →      url('play.php?game=' . $g['slug'])
```

App er WebView e kono PHP session cookie nei — app bearer token chalay.
Tai game e tap korle WebView `play.php` khulto, `require_play_login()`
seta `login.php` e pathiye dito, ar player ekta login form dekhto.
**Ekhon setar fix ache** (niche "play ticket" dekhun).

### (b) Spin er notun limit gulo app janto na

v6 e cooldown, weekly cap, monthly cap ar admin note esheche — sob
`spin_status()` er bhitore. App sudhu `allowed` ar `remaining` porto, tai
cooldown thakle button ta just "No spins right now" hoye morto, kono
countdown ba karon dekhato na. Ar `next_in` server theke sob somoy
midnight er second pathato — cooldown er khetre eta bhul number.

### (c) Govt Exam section app e chilo na

Website er navbar/footer e gold **SOON** badge soho Govt Exam esheche,
kintu app e er kono chinho chilo na.

---

## 2. Play ticket — Games tab er fix ta kivabe kaj kore

```
App                      Server
 |  GET  api/v1/games/ticket?game=sudoku   (Authorization: Bearer …)
 |------------------------------------------------->
 |  { play_url: ".../game-frame.php?game=sudoku&t=<ticket>" }
 |<-------------------------------------------------
 |  WebView oi URL khole
 |------------------------------------------------->  game-frame.php
 |                                          ticket verify → session khule dey
 |  game board (jemon browser e)
 |<-------------------------------------------------
```

**Ticket ta ki:** `<user>.<expiry>` + settings e rakha ekta secret diye kora
HMAC signature. Kono ticket table nei — kichu store hoy na.

- **15 minute** por more jay
- **ek ta game** er jonno-i valid (slug ta signature er bhitore ache) —
  sudoku er ticket diye chess kholano jabe na
- account block hole ticket thakleo dhukbe na (user row abar check hoy)
- **bearer token ta URL e kokhono jay na** — sudhu Authorization header e

Erpor `game-frame.php` oi WebView er jonno normal session khule dey, tai
game er asset, `<base href>`, sob kichu jemon chilo temon-i chole.

---

## 3. NEW files (3 ta)

| File | Ki kaj |
|---|---|
| `includes/appframe.php` | Play ticket er sob kichu — `app_frame_secret()`, `app_frame_ticket()`, `app_frame_user()`, `app_frame_url()`. Secret ta prothom bar nijei toiri hoye `settings` e boshe (`app_frame_secret`). |
| `app/.../ui/ExamActivity.java` | Govt Exam screen — countdown, admin er lekha copy, ar "notify me" form. |
| `app/.../res/layout/activity_exam.xml` | Oi screen er layout. |

## 4. MODIFIED files — server (5 ta)

| File | Ki bodleche |
|---|---|
| `config/config.php` | **SITE_URL er purono bug fix.** Auto-detect e `/admin` ar `/api` strip hoto, kintu `/api/v1` na — tai `api/v1/index.php` theke site root ta `https://asviora.in/api/v1` hoye jeto. Fole ticket er URL hoto `/api/v1/game-frame.php` (router nijei "No such endpoint: game-framephp" ferot dito), **ar app e avatar/quiz image/game thumbnail sob `/api/v1/uploads/...` e point korto** — segulo-o ekhon thik. |
| `includes/functions.php` | Sesh e `appframe.php` require kora holo (v6.1 block). |
| `game-frame.php` | `?t=<ticket>` ele verify kore oi WebView er jonno session khule dey. Ticket na thakle / bhul hole ager 403 page tai dekhabe — kichu dhila hoy ni. |
| `api/v1/index.php` | (a) Notun `games/ticket` route. (b) `config` e `govt_exam` block + module flag, ar game count ekhon `arena_count()` (6, sudhu arcade row 3 na) — admin dashboard er sathe mile. (c) `spin/status` ar `spin/play` e cooldown thakle `next_in` ekhon midnight na, **cooldown er second**; refuse korle `wait` field-o jay; spin er por pura `access` row ferot ase. (d) Notun `exam` ar `exam/notify` route. |
| `api/v1/bootstrap.php` | **Bodlano hoy ni** — `play_url` browser er jonno thik-i ache, app ekhon ticket route use kore. |

## 5. MODIFIED files — app (9 ta)

| File | Ki bodleche |
|---|---|
| `app/build.gradle` | versionCode 2 / versionName 1.1. |
| `net/Api.java` | `Callback` e notun (optional) `onError(code, message, error)` — error envelope er baki field gulo (jemon spin er `wait`) ekhon caller dekhte pare. Purono 2-argument version jara use kore, tader kichu bodlate hobe na. |
| `ui/GameActivity.java` | Notun static `open(ctx, slug, title)` — age ticket ane, tarpor board khole. Cookie on kora holo (ticket → session). |
| `ui/tabs/GamesFragment.java` | Tap e `g.playUrl` na, `GameActivity.open(...)`. |
| `ui/tabs/SpinFragment.java` | Cooldown thakle button e **live countdown** ("Next spin in 4m 20s"), weekly/monthly line, admin er note. Countdown sesh hole nijei `spin/status` abar dake (weekly cap thakle mithye kore button khule dey na). |
| `res/layout/fragment_spin.xml` | Notun `limits` ar `adminNote` TextView. |
| `ui/tabs/HomeFragment.java` + `res/layout/fragment_home.xml` | Govt Exam tile — website er motoi gold **SOON** badge, launch date dile "Opens in N days". Module off thakle tile-i thake na. Status `live` hole badge chole jay — notun build lage na. |
| `ui/SplashActivity.java` + `res/layout/activity_splash.xml` | `app_min_version` = deyal (Update button soho), `app_latest_version` = sudhu ekta note ("Not now" diye egiye jawa jay). Update link `app_update_url` theke ase. |
| `AndroidManifest.xml`, `res/values/strings.xml` | ExamActivity + notun string gulo. |

---

## 6. Test korar order

1. **XAMPP e (debug build):** app e login → **Games** tab → je kono game e tap.
   Board asa uchit, login form na. Na ele: `includes/appframe.php` upload
   hoyeche kina ar `settings` e `app_frame_secret` toiri hoyeche kina dekhun.
2. **Spin:** Admin → Daily Spin → Spin limits e cooldown 2 minute korun.
   App e ekbar spin korun → button e "Next spin in 1m 58s" countdown cholbe,
   niche weekly/monthly line dekhabe.
3. **Govt Exam:** Admin → Modules e `govt_exam` on ache kina dekhun. Home tab e
   tile asbe → tap → form fill → Admin → Govt Exam e row ta dekhun.
4. **Update prompt:** Admin → Site Settings e `app_latest_version` 3 kore dekhun —
   splash e "A newer version is out" + Update / Not now asbe.

## 7. Live e upload

Server side 5 ta file: `includes/appframe.php` (notun),
`config/config.php`, `includes/functions.php`, `game-frame.php`,
`api/v1/index.php`. Ready folder + zip: `_update/app-v2/` ar
`_update/app-v2-upload.zip`.
Database e **kichu add korte hobe na** — sudhu ekta setting row
(`app_frame_secret`) nijei toiri hobe. Migration lage na.

App side: Android Studio e project khule `versionCode 2` soho
`./gradlew assembleRelease` → notun APK. Erpor Admin → Site Settings e
`app_latest_version` = **2** kore din (purono build ke jor kore update
korate chaile `app_min_version` = 2)।

## 8. Rollback

`_update/backup-before-app-v2/` e ager sob file ache — server er PHP gulo
(`config-config.php` soho) ar app er pura `src/main` + `build.gradle`. Firiye dilei age jemon chilo
temon (mane Games tab abar bhanga, seta mone rakhben)।

---

## 9. Ja ekhono kora hoy ni (jene rakhben)

- **Game er score app theke save hoy na.** Eta age theke-i emon — `games/`
  folder er kono game-i score post kore na (`assets/js/main.js` er
  `asviora:score` listener ta kono game use kore na). Website eo same. Game
  gulo te score post add korle app ar site duto tei ek sathe kaj korbe.
- **Online chess er alada screen app e nei.** API te `chess/*` route ache,
  kintu app er native screen nei — chess ekhon game board hisebe khole.
- **Visitor analytics** puropuri admin-side, app e kichu lage na.

---

# Part 2 — look & feel (v2.1)

## A. Chess board notun rong

`games/chess/index.html` (website ar app duto jaygatei ek-i file).

**Ki problem chilo:** last-move highlight ta gold overlay (`background-image:
linear-gradient(rgba(229,184,75,.42)...)`) hisebe halka ghor er upor bosto —
gold + pale grey mile muddy beige hoye jeto. Ar `#board` er row gulo auto-height
chilo, tai jei rank e piece nei seta chapa hoye jeto — ghor gulo puropuri
square-i chilo na.

**Ekhon:**

- Ivory (`#EAEFD9`) ar olive-green (`#6A8E3C`) board — Asviora er green er poribar.
- Protita state (last move, selected, check, legal target) er **nijer rong**
  ache light ar dark ghor er jonno alada — kono translucent wash nei, tai
  ar kokhono ghola hobe na.
- Last move: halka gold inset rule. Selected: gold ring + bhitore soft glow.
- `grid-template-rows: repeat(8, 1fr)` — ghor gulo ekhon nikhut square.
- Piece gulo te opposite tone er rim — sada ivory ghor e ar kalo olive ghor e
  duto-i porishkar.

## B. App er logo

Android Studio er Image Asset Studio default Android robot boshiye diyechilo.
Setar bodole apnar asol logo (`assets/img/icon-512.png`) theke ber kora holo:

| File | Ki |
|---|---|
| `res/mipmap-<dpi>/ic_launcher_foreground.webp` | Emblem (crown + AS + green ring), background transparent kore kata. Canvas er 58% — sob launcher shape (circle / squircle / teardrop) e safe zone er bhitore thake. |
| `res/mipmap-<dpi>/ic_launcher_background.webp` | Brand dark ground + emblem er pichone soft green glow. |
| `res/mipmap-<dpi>/ic_launcher.webp` + `_round.webp` | Android 7 ba tar age er jonno legacy icon. |
| `res/mipmap-anydpi-v26/ic_launcher.xml` + `_round.xml` | Duto layer ke jorে; monochrome (Android 13 themed icon) er jonno simple "A" vector. |
| `src/main/ic_launcher-playstore.png` | Play Console er 512x512 listing icon. |
| `res/drawable-nodpi/logo_mark.png` | Splash ar game loading screen er logo. |

`res/drawable-v24/ic_launcher_foreground.xml` (robot ta) ekhon ar kothao use
hoy na — Android Studio theke delete kore dite paren.

## C. Game khullei branded loading screen

`ui/GameActivity.java` + `res/layout/activity_game.xml`

WebView page load howar age ek muhurto **sada** flash kore — dark app e oita-i
ekmatro jinis ja dhoriye dey je screen ta web page. Ekhon suru theke-i uporе
ekta cover thake: logo, game er naam, ar gradient progress bar. `onPageFinished`
hole 320ms e fade out. 12 second e-o na ele nijei sore jay, ar load fail korle
cover ei bole dey.

## D. Theme

- **Palette** (`values/colors.xml`) — ground gulo ghono holo (`bg` `#080B11`),
  notun `surface2`, `line_soft`, `dim`, `green_bright`, `green_ghost`,
  `gold_ghost`. Niyom ta simple: **green = ja click kora jay, gold = ja jitecho**,
  baki sob ground.
- **Card** ekhon shadow er bodole gradient e bhase (near-black screen e shadow
  dekha jay na, kintu upore ek shade halka surface uthe ase dekhay). Notun
  `Card.Hero` — screen e ekta-i, coin balance er jonno.
- Notun type style: `Label` (small caps, spaced), `Stat` (boro number).
- Button gulo pill (52dp, 26dp radius), proper ripple.
- Bottom nav: selected tab-i ekmatro green, sathe halka green pill indicator ar
  ekta hairline divider.
- **Home** notun kore saja holo — hero card e coins + rank + XP progress bar,
  tarpor "Jump back in" er tile gulo. Uporer dike choto logo.
- **Splash** e ekhon logo — icon theke screen e phote otha.

## E. Ki upload / rebuild korte hobe

- **Live server:** `_update/games-folder-upload.zip` abar upload korun — chess
  er notun CSS oi `index.html` er bhitore. (`app-v2-upload.zip` er PHP gulo
  bodlay ni, age uthiye thakle abar lagbe na.)
- **App:** Android Studio e Build → Clean Project, tarpor Rebuild. Icon bodlale
  launcher kokhono kokhono cache dhore rakhe — phone theke app uninstall kore
  abar install korle icon ta sathe sathe dekhben.
