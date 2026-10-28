package com.asviora.games.ui;

import android.annotation.SuppressLint;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ImageButton;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;

import com.asviora.games.R;
import com.asviora.games.net.Api;

import org.json.JSONObject;

/**
 * Plays one of the HTML5 games.
 *
 * The games themselves — chess, sudoku, memory, the cube — are canvas and
 * WebGL, so they run in a controlled WebView rather than being rewritten
 * twice. This screen owns everything around the board:
 *
 *   - a native top bar: Back on the left, the game's name, and Restart and
 *     Full screen on the right, so no game page has to draw its own;
 *   - a confirm sheet before leaving, so a stray back-swipe never throws a
 *     round away;
 *   - a full-screen mode that hides the bar and the system bars, with a
 *     small floating button to come back;
 *   - the branded cover that hides the white flash a WebView paints before
 *     the page is ready.
 *
 * The board is never opened by its own URL. Since the site put every
 * playable page behind a login, {@link #open} asks the API for a
 * short-lived signed ticket and opens the URL that comes back; the server
 * turns that into a session for this WebView. Cookies therefore have to be
 * on. The ticket is single-game and dies in fifteen minutes, so Restart
 * reloads the page the session is already on rather than the ticket URL.
 *
 * The WebView announces itself with " AsvioraApp/<version>" in its user
 * agent; game-sdk.js reads that to switch the page into app layout (action
 * buttons in a bottom dock, no duplicate chrome).
 */
public class GameActivity extends AppCompatActivity {

    public static final String EXTRA_URL   = "url";
    public static final String EXTRA_TITLE = "title";
    public static final String EXTRA_SLUG  = "slug";
    public static final String EXTRA_ICON  = "icon";
    public static final String EXTRA_SUB   = "sub";

    private WebView web;
    private View cover, topBar, exitFull;
    private ProgressBar bar, line;
    private TextView coverNote;
    private View coverRetry;
    private ImageButton fullBtn;

    private boolean revealed;
    private boolean fullscreen;
    private boolean failed;
    private String startUrl;

    /** The only way in. Fetches a play ticket, then opens the board. */
    public static void open(final Context ctx, final String slug, final String title) {
        open(ctx, slug, title, null, null);
    }

    /**
     * Same, with the catalogue's emoji and a subtitle for the top bar.
     *
     * The ticket names one game, dies in fifteen minutes and is signed by
     * the server, so nothing worth stealing travels in the URL — the bearer
     * token itself never leaves the Authorization header.
     */
    public static void open(final Context ctx, final String slug, final String title,
                            @Nullable final String icon, @Nullable final String sub) {
        Api.get("games/ticket?game=" + Uri.encode(slug), new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                String url = data.optString("play_url", "");
                if (url.isEmpty()) {
                    Toast.makeText(ctx, "That game could not be opened.",
                            Toast.LENGTH_SHORT).show();
                    return;
                }
                Intent it = new Intent(ctx, GameActivity.class);
                it.putExtra(EXTRA_URL, url);
                it.putExtra(EXTRA_TITLE, title);
                it.putExtra(EXTRA_SLUG, slug);
                it.putExtra(EXTRA_ICON, icon);
                it.putExtra(EXTRA_SUB, sub);
                ctx.startActivity(it);
            }

            @Override public void onError(String code, String message) {
                Toast.makeText(ctx, message, Toast.LENGTH_LONG).show();
            }
        });
    }

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_game);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        startUrl     = getIntent().getStringExtra(EXTRA_URL);
        String title = getIntent().getStringExtra(EXTRA_TITLE);
        String slug  = getIntent().getStringExtra(EXTRA_SLUG);
        String icon  = getIntent().getStringExtra(EXTRA_ICON);
        String sub   = getIntent().getStringExtra(EXTRA_SUB);
        if (title == null || title.isEmpty()) title = getString(R.string.app_name);
        setTitle(title);

        // ---- top bar ----
        topBar   = findViewById(R.id.topBar);
        line     = findViewById(R.id.loadLine);
        fullBtn  = findViewById(R.id.btnFull);
        exitFull = findViewById(R.id.btnExitFull);

        ((TextView) findViewById(R.id.gameTitle)).setText(title);
        TextView subView = findViewById(R.id.gameSub);
        if (sub != null && !sub.isEmpty()) subView.setText(sub);

        TextView iconView = findViewById(R.id.gameIcon);
        iconView.setText(GameArt.emoji(slug, icon));
        iconView.setBackground(GameArt.chip(iconView, slug, 12));

        findViewById(R.id.btnBack).setOnClickListener(v -> askToLeave());
        findViewById(R.id.btnRestart).setOnClickListener(v -> askToRestart());
        fullBtn.setOnClickListener(v -> setFullscreen(!fullscreen));
        exitFull.setOnClickListener(v -> setFullscreen(false));

        // ---- cover ----
        cover      = findViewById(R.id.cover);
        bar        = findViewById(R.id.coverBar);
        coverNote  = findViewById(R.id.coverNote);
        coverRetry = findViewById(R.id.coverRetry);
        ((TextView) findViewById(R.id.coverTitle)).setText(title);

        Motion.spin(findViewById(R.id.coverRing), 2600);
        Motion.breathe(findViewById(R.id.coverLogo), 0.06f, 900);
        Motion.glow(findViewById(R.id.coverGlow), 0.55f, 1f, 1100);
        Motion.pop(findViewById(R.id.coverTitle), 120);

        coverRetry.setOnClickListener(v -> retry());

        // ---- web view ----
        web = findViewById(R.id.web);

        // The play ticket is exchanged for a session cookie by the server,
        // and every asset the game asks for afterwards rides on it.
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);            // the games are JavaScript
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setDisplayZoomControls(false);
        s.setUserAgentString(s.getUserAgentString() + " AsvioraApp/"
                + com.asviora.games.BuildConfig.VERSION_NAME);

        web.setOverScrollMode(View.OVER_SCROLL_NEVER);
        web.setVerticalScrollBarEnabled(false);
        web.setHorizontalScrollBarEnabled(false);
        web.setBackgroundColor(0xFF06080E);

        final String host = Uri.parse(com.asviora.games.BuildConfig.SITE_BASE).getHost();

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, android.webkit.WebResourceRequest req) {
                // never let a game navigate the WebView somewhere else
                String h = req.getUrl().getHost();
                return h == null || host == null || !h.equalsIgnoreCase(host);
            }

            @Override
            public void onPageFinished(WebView view, String u) {
                line.setVisibility(View.INVISIBLE);
                if (failed) return;
                // One frame for the game to paint, then hand the screen over.
                view.postDelayed(GameActivity.this::reveal, 260);
            }

            @Override
            public void onReceivedError(WebView view, android.webkit.WebResourceRequest req,
                                        android.webkit.WebResourceError err) {
                if (req != null && req.isForMainFrame()) showFailure();
            }

            @SuppressWarnings("deprecation")
            @Override
            public void onReceivedError(WebView view, int code, String desc, String url) {
                // Android 5.x only; newer versions use the method above.
                if (android.os.Build.VERSION.SDK_INT < 23) showFailure();
            }
        });

        web.setWebChromeClient(new android.webkit.WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int p) {
                bar.setProgress(Math.max(8, p));
                line.setVisibility(p >= 100 ? View.INVISIBLE : View.VISIBLE);
                line.setProgress(p);
            }
        });

        if (startUrl != null) {
            web.loadUrl(startUrl);
            // A game that never answers should not leave the player staring
            // at a logo for ever.
            cover.postDelayed(() -> { if (!failed) reveal(); }, 12000);
        } else {
            reveal();
        }
    }

    // ---------------------------------------------------------------
    //  Cover
    // ---------------------------------------------------------------

    /** Fade the brand out, once. */
    private void reveal() {
        if (revealed || cover == null) return;
        revealed = true;
        Motion.fadeOut(cover, 380);
    }

    private void showFailure() {
        failed = true;
        revealed = false;
        cover.animate().cancel();
        cover.setAlpha(1f);
        cover.setScaleX(1f);
        cover.setScaleY(1f);
        cover.setVisibility(View.VISIBLE);
        coverNote.setText("Could not load the game. Check your connection.");
        bar.setVisibility(View.INVISIBLE);
        coverRetry.setVisibility(View.VISIBLE);
    }

    private void retry() {
        failed = false;
        coverRetry.setVisibility(View.GONE);
        coverNote.setText(R.string.loading);
        bar.setVisibility(View.VISIBLE);
        bar.setProgress(8);
        if (web != null) {
            String now = web.getUrl();
            if (now == null || now.startsWith("about:")) web.loadUrl(startUrl);
            else web.reload();
        }
    }

    // ---------------------------------------------------------------
    //  Actions
    // ---------------------------------------------------------------

    private void askToLeave() {
        Motion.tick(topBar);
        ConfirmDialog.show(this, R.drawable.ic_exit,
                getString(R.string.leave_title), getString(R.string.leave_body),
                getString(R.string.keep_playing), getString(R.string.leave_yes),
                this::finish);
    }

    private void askToRestart() {
        Motion.tick(topBar);
        ConfirmDialog.show(this, R.drawable.ic_refresh,
                getString(R.string.restart_title), getString(R.string.restart_body),
                getString(R.string.keep_playing), getString(R.string.restart_yes),
                this::restart);
    }

    /**
     * Reload the board. The session cookie from the first load is still
     * good, so the page the WebView is on reloads cleanly; the ticket URL
     * is only used again if nothing has loaded yet.
     */
    private void restart() {
        if (web == null) return;
        revealed = false;
        failed = false;
        cover.animate().cancel();
        cover.setAlpha(1f);
        cover.setScaleX(1f);
        cover.setScaleY(1f);
        cover.setVisibility(View.VISIBLE);
        coverRetry.setVisibility(View.GONE);
        coverNote.setText(R.string.loading);
        bar.setVisibility(View.VISIBLE);
        bar.setProgress(8);

        String now = web.getUrl();
        if (now == null || now.startsWith("about:")) web.loadUrl(startUrl);
        else web.reload();
        cover.postDelayed(() -> { if (!failed) reveal(); }, 12000);
    }

    /** Hide or show the bar and the system bars. */
    private void setFullscreen(boolean on) {
        fullscreen = on;
        WindowInsetsControllerCompat c =
                WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView());

        if (on) {
            topBar.animate().translationY(-topBar.getHeight()).alpha(0f)
                  .setDuration(220)
                  .withEndAction(() -> topBar.setVisibility(View.GONE))
                  .start();
            c.setSystemBarsBehavior(
                    WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
            c.hide(WindowInsetsCompat.Type.systemBars());
            exitFull.setVisibility(View.VISIBLE);
            Motion.pop(exitFull, 200);
            fullBtn.setImageResource(R.drawable.ic_fullscreen_exit);
        } else {
            topBar.setVisibility(View.VISIBLE);
            topBar.setTranslationY(-topBar.getHeight());
            topBar.setAlpha(0f);
            topBar.animate().translationY(0f).alpha(1f).setDuration(260).start();
            c.show(WindowInsetsCompat.Type.systemBars());
            exitFull.setVisibility(View.GONE);
            fullBtn.setImageResource(R.drawable.ic_fullscreen);
        }
        // the page reflows to its new height
        if (web != null) web.postDelayed(() -> {
            if (web != null) web.evaluateJavascript(
                    "window.dispatchEvent(new Event('resize'))", null);
        }, 300);
    }

    // ---------------------------------------------------------------
    //  Lifecycle
    // ---------------------------------------------------------------

    @Override
    public void onBackPressed() {
        if (fullscreen) { setFullscreen(false); return; }
        if (failed || !revealed) { super.onBackPressed(); return; }
        askToLeave();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (web != null) web.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (web != null) web.onResume();
        if (fullscreen) {
            WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView())
                        .hide(WindowInsetsCompat.Type.systemBars());
        }
    }

    @Override
    protected void onDestroy() {
        if (web != null) {
            web.stopLoading();
            web.loadUrl("about:blank");
            web.destroy();
            web = null;
        }
        super.onDestroy();
    }
}
