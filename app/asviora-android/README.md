# Asviora Games — Android app

A native Java app for the Asviora Games site. Not a wrapper: the screens
are real Android views and they talk to `/api/v1` over JSON with a bearer
token. The only WebView in the whole project is the one that renders a
game board — chess, sudoku, memory and the 3D cube are canvas/WebGL, so
they run as they are instead of being written a second time.

---

## Opening it

1. Android Studio → **Open** → pick this folder (`asviora-android`).
2. Let it sync. Gradle 8.7 / AGP 8.5.2, `compileSdk 34`, `minSdk 21`.
3. Run on an emulator or a phone.

The Gradle wrapper jar is not committed. Android Studio adds it on the
first sync; from a terminal, run `gradle wrapper` once first.

---

## Pointing it at your server

One file — `app/build.gradle`. The release build goes to the live site:

```gradle
buildConfigField "String", "API_BASE",  "\"https://asviora.in/api/v1/\""
buildConfigField "String", "SITE_BASE", "\"https://asviora.in/\""
```

A debug build goes to the same place unless you say otherwise, because
`10.0.2.2` — the emulator's name for the host machine — does not exist on a
real handset, and a debug APK side-loaded onto a phone would just sit there
timing out. To point one at XAMPP, set `devHost` in `gradle.properties`:

```properties
# the machine running XAMPP, as the phone sees it (ipconfig / ifconfig)
devHost=http://192.168.1.20/gamesv/

# or, for the emulator
devHost=http://10.0.2.2/gamesv/
```

or pass it once: `./gradlew assembleDebug -PdevHost=http://192.168.1.20/gamesv/`.
It has to end with a slash. Nothing else hardcodes a host. Cleartext HTTP is allowed only for
`10.0.2.2`, `localhost` and private ranges (see
`res/xml/network_security_config.xml`); the release build is HTTPS-only.

Requests always go to `index.php?r=<route>`, so the app works whether or
not `mod_rewrite` is switched on, and whether the site sits at the domain
root or in a subfolder.

---

## What is in it

| Screen | What it does |
|---|---|
| **Splash** | Asks `/config` what is switched on, checks the stored token, then routes to login or home. A module hidden in Admin → Modules loses its tab here too. |
| **Login / Register** | Username or email. Register credits the welcome coins server-side. |
| **Home** | Coins, level, XP, and shortcuts into the quiz and the wheel. |
| **Games** | The live catalogue. Tapping one asks the API for a signed play ticket, then opens the game surface with it. |
| **Quiz** | The three ladders with progress bars, and the lock reason when Mid or Hard is not open yet. |
| **Levels** | The level map: a grid of tiles, locked / open / passed with one to three stars. |
| **Play** | One question at a time with a countdown, then a result card with stars, coins and a **Next level** button when the level was cleared. |
| **Spin** | A wheel drawn on canvas from whatever segments the admin configured. The server picks the winner before the wheel is told where to stop. |
| **Profile** | Stats, coin history, recent rounds, sign out. |
| **Govt Exam** | The section announced before it opens: countdown, the admin's own copy, and the interest form. A tile on Home rather than a sixth tab, and gone entirely when the module is off. |

---

## How it is put together

```
com.asviora.games
├── AsvioraApp             gives the session store a context
├── net/
│   ├── Api                HttpURLConnection + org.json, thread pool,
│   │                      callbacks posted back to the main thread
│   └── Session            token + cached player row in SharedPreferences
├── model/                 User, Game, Ladder, QuizLevel
├── ui/                    Splash, Login, Register, Main, QuizLevels,
│   │                      QuizPlay, Game, Exam
│   └── tabs/              Home, Games, Quiz, Spin, Profile
└── view/SpinWheelView     the wheel, drawn rather than shipped as images
```

No Retrofit, no Gson, no image library — `HttpURLConnection` and
`org.json` are both part of Android, so the APK carries no networking or
JSON dependency at all.

---

## The rules the app cannot bend

Everything that decides an outcome lives on the server, and the app is
written on the assumption that a modified client will try:

- The answer key is never sent with a question. Which option was right
  comes back **after** the choice has been submitted.
- The countdown on screen is a courtesy. The server stamps when it served
  the question and measures the answer against its own clock, so pausing
  the app buys no thinking time.
- Level unlocks, the pass mark, the clear bonus and the six-month
  no-repeat window are all decided server-side.
- Spin results are chosen by the server before the wheel animates, and the
  cooldown, the weekly cap and the monthly cap are all enforced inside
  `spin_status()`. The countdown on the button is what the server said is
  left, not a timer the app runs on its own — when it reaches zero the app
  asks again rather than unlocking itself.
- The arcade is behind a login. The app holds a bearer token, not a cookie,
  so it never opens a game URL directly: it asks `/api/v1/games/ticket` for a
  short-lived signed URL and opens that. The ticket names one game, expires
  in fifteen minutes, and is verified by `game-frame.php`, which then opens a
  session for that WebView. The token itself never travels in a URL.
- Online chess moves are re-generated from the stored position and
  rejected if they are not legal.

---

## Signing a release

```
./gradlew assembleRelease
```

Add your keystore to `app/build.gradle` under `signingConfigs` before
shipping. `minifyEnabled` is already on for release; the app uses no
reflection-based mapping, so the default ProGuard rules are enough.

Bump `versionCode` when you publish, and set **app_latest_version** (and
**app_min_version** if you want to force an upgrade) in
Admin → Site Settings. The splash screen reads them from `/config` and
tells the player when their build is too old.
