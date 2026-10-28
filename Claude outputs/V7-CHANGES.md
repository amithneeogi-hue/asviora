# Asviora Games — v7 update (Payment gateway · Game subscription · Registration fee · SEO Manager · App v1.3)

Ei document e ache: ki notun holo, kon file **notun**, kon file **modify**, database e ki add holo,
ar live site e kivabe upload korben.

---

## 1. Live server e ki korte hobe (order ta mane rakhben)

1. **Database backup nin** — hPanel → phpMyAdmin → Export.
2. `asviora-v7-web-upload.zip` er sob file FTP / File Manager diye upload korun (folder structure same).
3. Browser e khulun:

   ```
   https://asviora.in/v7migration.php?key=cpps-2026
   ```
   **"Migration chalao →"** chapun. Sob `ok` / `skip` dekhale **`v7migration.php` server theke delete korun.**
   (Auto-o chole: `includes/migrate.php` e `SCHEMA_VERSION = 7` — site er je kono page ekbar khullei hoye jay.)
4. Admin → **Payments → Gateways** e Razorpay / Cashfree key bosan → **Test keys** button chapun.
5. Admin → **Game Plans** e kon game paid hobe thik korun.
6. Admin → **Payments → Registration fee** — chaile fee on korun.
7. Admin → **SEO Manager → Global** e Search Console code bosan, tarpor Google Search Console e
   `https://asviora.in/sitemap.xml` submit korun.

> Gateway key na bosano porjonto **kono game lock hoy na** — sob age-r moto free thake.
> Admin → Modules e "Payments & subscriptions" off korle-o sob free hoye jay (setting gulo thake).

---

## 2. Payment gateway (Razorpay + Cashfree)

- Duto gateway-i admin theke on/off, key, mode (Cashfree sandbox / production), ar **kon ta age dekhabe**.
- Secret key gulo password field e — blank rakhle purono key thake.
- Player pay page e gateway bechhe ney → UPI / Card / Netbanking / Wallet.
- Payment verify:
  - **Razorpay** — order server e toiri hoy, checkout er signature (HMAC) server e check hoy.
  - **Cashfree** — return er por server nijei Cashfree ke jiggesh kore order `PAID` kina, amount mile kina.
  - **Webhook** (duto-r jonno) — browser / app bondho hoye gele-o payment credit hoy. Ekbar-er beshi credit hoy na.
- Webhook URL (gateway dashboard e bosaben):
  - Razorpay: `https://asviora.in/pay-webhook.php?gw=razorpay` — events `payment.captured`, `order.paid`, `payment.failed`;
    secret = Admin e "Razorpay webhook secret" e ja diyechen.
  - Cashfree: `https://asviora.in/pay-webhook.php?gw=cashfree` (protita order er sathe auto jay). Production e domain whitelist lagbe.
- **Admin → Payments → Transactions**: sob order, filter, search, CSV download,
  "Mark paid" (cash / bank transfer hole), "Refunded" (pass off hoye jay — asol refund gateway dashboard theke korben).

## 3. Game subscription (per-game plan)

- **Admin → Game Plans**: protita game (3D Cube, Memory, Sudoku, Chess) + Quiz Contest + Daily Spin —
  **Free / Paid** tick, **dam (₹)**, **koto din** (0 = lifetime), plan er naam (e.g. "Monthly pass").
- Paid game e website o app e **🔒 ₹49 / 1 month** tag, button e **"Unlock ₹49"**.
- Pass thakle tag e **"✓ Pass till 05 Nov"**. Meyad sesh howar age abar kinle din gulo **jog** hoy.
- **Give a pass** — kono player ke free pass (prize / promotion), **Revoke** — pass bondho.
- Lock sob jaygay: `play.php`, `game-frame.php`, quiz, spin, chess page + purono AJAX API + app API.
  Tai link copy kore ba purono app diye-o paid game khola jabe na.

## 4. Registration fee

- **Admin → Payments → Registration fee**: on/off, amount, note.
- Default e sudhu fee on korar **porer** notun account der dite hobe; "Existing players must pay too" tick dile sobai.
- Register er por soja pay page e jay; fee na dile login thake, dashboard dekha jay, kintu **kono game khele na**.
- **Let a player off** — username / email diye fee waive.
- Player er Dashboard e notun **"Payments & Passes"** tab — pass gulo, receipt, history.

## 5. SEO — puro site + SEO Manager

**Automatic (sob page e):** proper title format, meta description, keywords, **canonical URL**, robots,
Open Graph + Twitter card (share korle chhobi soho), **JSON-LD schema** — Organization, WebSite, BreadcrumbList,
game page e **VideoGame**, blog e **BlogPosting**. Game chhobi te alt text + lazy loading.
Login / dashboard / pay / in-game page gulo **noindex**.
`/sitemap.xml` ar `/robots.txt` ekhon PHP theke auto toiri hoy (`.htaccess` rewrite).

**Admin → SEO Manager:**
- **Pages** — sob page, game, blog post er list + **SEO score (0-100)** + ki thik korte hobe.
  Edit e title / description / keywords / share image / robots / canonical, live **Google preview**.
- **✨ AI suggest** — Claude AI title, description, keyword likhe form e bhore dey (Save na chapa porjonto live hoy na).
  **"AI-write empty pages"** — ekbare 6 ta page.
- **Global** — title format, default description / keywords / share image, Google Search Console + Bing code,
  Google Analytics 4 / Tag Manager ID, extra robots.txt line,
  **"All Games page guest der dekhao"** (SEO er jonno recommended — khelte login lagbe-i).
- **AI assistant** — Claude API key (console.anthropic.com theke), model, focus topic, "Test the key".

---

## 6. Android app — v1.3 (versionCode 4)

| Ki | Ekhon |
|---|---|
| Theme | Website er **hubohu rong** (`style.css` er `:root` palette) — background, card, green, gold, danger sob same |
| Game icon | Sob jaygay **asol game er chhobi** — Games tab, Home "Quick play", Home tile (Quiz / Spin / Games), game er top bar. Chhobi app er bhitore-o deya ache (offline e-o dekhay); admin notun thumbnail dile server theke ase |
| Games tab | Website er moto **Arena** — games + Quiz + Spin ek list e |
| Back option | Games / Quiz / Spin / Profile — protita tab er upore **"← Home"** button. Game, Quiz level, Exam, Register, Pay screen e upore ← Back (age theke + notun Pay screen) |
| Paid game | 🔒 price tag, **UNLOCK** button → sheet → **Secure payment** screen (Razorpay / Cashfree, UPI app khule) → pay hole nijei fire ase, game unlock |
| Registration fee | Login er por ekbar sheet + Home e banner "Complete your registration" |

**NEW files (app):** `ui/PayActivity.java`, `ui/Paywall.java`, `ui/ImageLoader.java`,
`res/layout/activity_pay.xml`, `res/layout/view_tab_back.xml`, `res/drawable-nodpi/game_*.webp` (6 ta).
**MODIFIED (app):** `AndroidManifest.xml` (PayActivity, UPI `<queries>`), `app/build.gradle` (4 / 1.3),
`model/Game.java`, `model/User.java`, `ui/GameActivity.java`, `ui/GameArt.java`, `ui/MainActivity.java`,
`ui/QuizPlayActivity.java`, `ui/tabs/GamesFragment.java`, `HomeFragment.java`, `SpinFragment.java`,
`view/SpinWheelView.java` (rong), layout: `activity_game`, `fragment_home`, `fragment_list`, `fragment_quiz`,
`fragment_spin`, `fragment_profile`, `item_game`, `item_featured`; values: `colors`, `strings`, `ids`;
~35 ta drawable e sudhu rong er hex.

Build: Android Studio → `app/asviora-android` → Sync → Run. Release er por Admin → Site Settings e `app_latest_version` = **4**.
Purono app (v1.2) chalbe — kintu paid game khulle "Unlock" na dekhiye error message dekhabe; tai notun APK din.

> Ei cloud workspace e Android SDK nei, tai APK ekhane build kora jay ni. Sob XML parse + resource/id reference
> check kora hoyeche, Java syntax check hoyeche. Build e kono error ele error ta pathan.
> Website er font (Plus Jakarta Sans) app e bosano jay ni — ekhane font download block chilo.

---

## 7. Website — NEW files (12)

| File | Kaj |
|---|---|
| `includes/payments.php` | Gateway (Razorpay/Cashfree), plan, subscription, reg fee, `game_access()` — ek jaygay sob niyom |
| `includes/seo.php` | SEO engine — meta, canonical, schema, analytics, audit score, Claude AI |
| `pay.php` | Pay page (website + app) |
| `pay-callback.php` | Gateway theke fire asa + verify |
| `pay-result.php` | Success / pending / failed + receipt |
| `pay-webhook.php` | Razorpay + Cashfree webhook |
| `sitemap.php`, `robots.php` | `/sitemap.xml`, `/robots.txt` |
| `admin/payments.php` | Admin → Payments (Transactions / Gateways / Registration fee) |
| `admin/game-plans.php` | Admin → Game Plans |
| `admin/seo.php` | Admin → SEO Manager |
| `config/upgrade-v7.sql` | v7 database |
| `v7migration.php` | Manual migration (kaj sese delete) |

## 8. Website — MODIFIED files

`.htaccess` (sitemap/robots rewrite) · `assets/css/style.css` (sesh e v7 block) ·
`includes/functions.php` (reg fee gate + v7 require) · `includes/migrate.php` (SCHEMA 7) ·
`includes/header.php` (SEO head, in-app mode) · `includes/footer.php` (game chhobi) ·
`includes/arena.php` (price tag / lock / pass, `game_thumb()`) · `admin/includes/layout.php` (menu) ·
`games.php` · `play.php` · `game-frame.php` · `quiz.php` · `quiz-play.php` · `spin.php` · `chess.php` · `chess-play.php` ·
`api/quiz.php` · `api/spin.php` · `api/chess.php` · `api/v1/bootstrap.php` · `api/v1/index.php`
(notun `arena`, `pay/link`, `payments`; 402 `payment_required`) ·
`register.php` · `login.php` · `dashboard.php` · `leaderboard.php` · `post.php`.

## 9. Database (v7)

Notun table: `payments`, `game_plans`, `subscriptions`, `seo_pages`.
Notun settings: `pay_*`, `reg_fee_*`, `seo_*`. Notun module row: `payments`.
Kono purono table e ALTER nei — tai rollback e kichu bhange na.

## 10. Rollback

`_update/backup-before-v7/` e modify howa sob file er ager copy ache (same folder structure).
Firiye din ar `includes/migrate.php` e `SCHEMA_VERSION` 6 korun. Notun table thakleo purono code er kono khoti nei.

> Note: Coin ekhono virtual — taka diye coin kena / tola jay na. Registration fee ar game pass sudhu
> **access fee**. Real-money gaming er niyom (India) mathay rekhe eta-i rakha holo.
