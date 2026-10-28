# Asviora app — v3 PREMIUM redesign

App ta ke puro notun premium look deya holo — theme, animation, game screen er
top bar (Back / Restart / Full screen), ar protita game er bhitore notun design.
**Kono API, database ba server logic bodlano hoy ni** — sudhu look & feel.

App version: **versionCode 2 → 3**, versionName **1.1 → 1.2**

---

## 1. Ki ki bodlalo (chhoto kore)

| Jayga | Age | Ekhon |
|---|---|---|
| Theme | Flat dark, sudhu green | Midnight + neon aurora glow, glass card, gradient button, gold coin |
| Bottom bar | Screen er niche lege thakto | **Floating glass bar**, tab change e smooth fade/rise animation |
| Home | 4 ta simple card | Avatar + coin chip, aurora hero card (coin count-up, XP bar animation), **Quick play** game rail, 4 ta gradient tile |
| Games tab | Choto list | **Game Arena** — protita game er nijossho neon banner, category/featured chip, **PLAY** button |
| Game khulle | Kono back/action button chilo na | **Native top bar**: bam dike ← Back, majhe game er naam + coin, dan dike ⟳ Restart ar ⛶ Full screen |
| Back chaple | Sathe sathe beriye jeto | **"Leave this game?"** confirm sheet — bhul kore round harabe na |
| Game er bhitore (phone) | Button gulo upore stat er sathe jot pakiye thakto | Upore sudhu live stat; **sob action button niche ekta dock e** — angul diye sohoje pawa jay |
| Protita game | Ek-i rong | Chess = emerald, Sudoku = blue, Memory = pink/violet, 3D Cube = orange — nijossho glow, button, board |
| Game jitle | Sudhu modal | Trophy animation + **confetti** + vibration (app e) |
| Quiz | Plain | Hero progress card, rong-bera ladder badge (🌱 🔥 👑), khelar level ta "breathe" kore, option e A/B/C badge, thik/bhul e animation, sesh 5 second e timer lal hoye kape |
| Spin | Simple wheel | **Gold rim + marquee light**, glossy segment, "SPIN" hub, prottek ghore haptic tick, jitle 🎉 pop |
| Profile | Text list | Boro avatar, stat card (count-up), icon soho history, sign out e confirm |
| Splash / Login | Static | Logo pop, ghurte thaka neon ring, gradient naam, field/card glass look |

---

## 2. Android — NEW files

| File | Kaj |
|---|---|
| `ui/Motion.java` | Sob animation ek jaygay — stagger, pop, breathe, spin, count-up, shake, haptic |
| `ui/GameArt.java` | Protita game er gradient rong + emoji (notun game o automatic rong pabe) |
| `ui/ConfirmDialog.java` | Premium confirm sheet (leave / restart / quit / sign out) |
| `res/layout/dialog_confirm.xml`, `item_featured.xml`, `view_topbar_divider.xml` | Notun layout |
| `res/values/dimens.xml`, `ids.xml` | Spacing / radius, animation tag |
| `res/anim/*` (7 ta), `res/animator/press_scale.xml` | Screen/tab transition, list rise, tap e "press" effect |
| `res/drawable/*` (~40 ta) | Glass card, gradient button, tile, ring, icon (back, close, refresh, fullscreen, coin, trophy …) |

## 3. Android — MODIFIED files

| File | Ki bodleche |
|---|---|
| `ui/GameActivity.java` + `activity_game.xml` | Top bar (Back / Restart / Full screen), leave confirm, load hole neon progress line, load fail hole **Try again** button, screen on thake, WebView user-agent e `AsvioraApp/1.2` (game page eta dekhe app-layout e jay) |
| `ui/MainActivity.java` + `activity_main.xml` | Floating nav, tab animation, same tab e abar tap korle reload hoy na, Home chara onno tab e Back → Home |
| `ui/tabs/*Fragment.java` + layout gulo | Upore je redesign ta bola holo. **Sob purono id rakha ache**, API call same |
| `ui/QuizPlayActivity.java`, `QuizLevelsActivity.java` | Notun option style, animation, confirm sheet. `getColor(.., null)` (API 23+) → `ContextCompat` — Android 5/6 e crash er jhuki chilo |
| `ui/SplashActivity.java`, `LoginActivity.java`, `RegisterActivity.java` | Entrance animation |
| `ui/ExamActivity.java` + layout | Upore Back button (age chilo na) |
| `view/SpinWheelView.java` | Puro notun drawing (API same: `setSegments`, `spinTo`) |
| `res/values/colors.xml`, `themes.xml`, `strings.xml` | Notun palette/style/string — purono naam gulo sob ache |
| `AndroidManifest.xml` | GameActivity notun theme; login/register e keyboard uthle screen resize |
| `app/build.gradle` | versionCode 3 / versionName 1.2 |

## 4. Website / game files (server e upload lagbe)

| File | Ki bodleche |
|---|---|
| `assets/css/game.css` | Sesh e **v3 premium layer** jog kora — per-game accent, glass bar, glow button, dock, modal. Upore-r purono CSS hat deya hoy ni |
| `assets/js/game-sdk.js` | Sesh e notun block — app detect, action dock, button ripple + vibration, stat bump, confetti. `Asviora.submit/toast/Timer/best` same ache |
| `games/chess|sudoku|memory|cube3d/index.html` | Sudhu `<body>` e `data-game="..."` jog kora (rong er jonno) |

Website e (desktop) dock hoy na — sudhu phone width (≤720px) ba app er bhitore.
Ready zip: `_update/v3-premium-web-upload.zip` (folder structure soho).

---

## 5. Build ar test

1. Android Studio te `app/asviora-android` khulun → **Sync** → **Run**.
2. Check list:
   - Splash e logo pop + ring ghore
   - Home e coin count-up, Quick play rail e game tap → game khule
   - Game screen: upore ← / ⟳ / ⛶ — Back chaple confirm sheet, ⛶ te full screen (dan-upore chhoto button e ferot)
   - Game er bhitore niche dock e New Game / level select
   - Spin e wheel er light ghore, jitle 🎉
3. Server e zip er 6 ta file upload korun (na korle-o app chalbe — sudhu game er bhitorer notun design ta asbe na).
4. Release: `./gradlew assembleRelease` → Admin → Site Settings e `app_latest_version` = **3**.

> Ei cloud workspace e Android SDK nei, tai APK ekhane build kora jay ni.
> XML/Java syntax, sob resource/id reference, ar game page gulo phone size e
> render kore check kora hoyeche. Android Studio te build e kono error ele
> error ta pathale fix kore debo.

## 6. Rollback

`_update/backup-before-v3-premium/` e ager sob file ache (same folder structure) —
ogulo firiye dilei age jemon chilo. Notun file gulo (Motion.java, GameArt.java,
ConfirmDialog.java, notun drawable/anim) rekhe dileo kono khoti nei.


---

# v3.1 — puro theme LOGO theke

Apnar Asviora logo (charcoal background, sonali **A**, lime-sobuj **S** / ring / crown)
theke rong mepe neya holo — app ar game e ekhon ar kono neel/beguni/golapi rong nei.

| Logo te | App e kothay |
|---|---|
| Charcoal, upore alo | Sob screen er background (upore halka alo, niche gaarho) |
| Lime sobuj (S, ring, crown) | Primary button, active tab, progress, "PLAY", thik uttor |
| Sonali (A) | Coin, rim/border, Spin button, level badge, highlight |
| Gold → lime ring | Splash/loading er ghurte thaka ring, avatar ring |

**Logo nijei bosano holo**
- `res/drawable-nodpi/logo_wordmark.png` (notun) — logo theke kete ber kora "ASVIORA / GAMES / WHERE SKILL WINS" (transparent). Splash, Login, Games tab, game loading screen e.
- Home er upore avatar er jaygay crest (emblem); coin card er pichone halka crest watermark.
- Game page: upore stat bar er suru te crest (desktop), background e khub halka crest, Memory game er protita tile er pichone crest, result modal e watermark.
- `assets/img/logo-emblem.png` (notun, server e upload lagbe) — web er jonno transparent crest.

**Game er rong (sob logo-r family theke)**: Chess = sobuj, Sudoku = sonali (charcoal grid, sonali box line),
Memory = bright lime + crest tile, 3D Cube = bronze.

**Back button**: Register screen eo ekhon upore ← Back ache. Sob bhitorer screen e (Game, Quiz levels,
Quiz play = ✕, Govt Exam, Register) upore-bame back ache; tab e phone er Back chaple Home e jay.

Preview (mockup, asol screenshot na): `Claude outputs/app-logo-theme-preview.png`

Server e upload: `_update/v3-premium-web-upload.zip` (7 ta file — notun `assets/img/logo-emblem.png` soho).
Ager (v3) file gulor copy: `_update/backup-v3-before-logo-theme/`.
