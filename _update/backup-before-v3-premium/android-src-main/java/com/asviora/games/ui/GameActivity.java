package com.asviora.games.ui;

import android.animation.Animator;
import android.animation.AnimatorListenerAdapter;
import android.annotation.SuppressLint;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.view.animation.AccelerateDecelerateInterpolator;
import android.webkit.CookieManager;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;
import com.asviora.games.net.Api;

import org.json.JSONObject;

/**
 * Plays one of the HTML5 games.
 *
 * The games themselves — chess, sudoku, memory, the cube — are canvas and
 * WebGL, so they run in a controlled WebView rather than being rewritten
 * twice. Everything else in the app is native: this screen exists only for
 * the game surface, and it is locked down to the site's own origin.
 *
 * Two things are worth knowing about it.
 *
 * The board is never opened by its own URL. Since the site put every
 * playable page behind a login, a WebView carrying a bearer token instead of
 * a cookie would land on login.php, so {@link #open} asks the API for a
 * short-lived signed ticket and opens the URL that comes back; the server
 * turns that into a session for this WebView. Cookies therefore have to be
 * on.
 *
 * And the screen wears the brand while it waits. A WebView paints white
 * before the page it is loading takes over, which on a dark app is the one
 * frame that gives away that a screen is a web page. The cover is on top
 * from the start and fades out when the game is actually there.
 */
public class GameActivity extends AppCompatActivity {

    public static final String EXTRA_URL   = "url";
    public static final String EXTRA_TITLE = "title";

    private WebView web;
    private View cover;
    private ProgressBar bar;
    private boolean revealed;

    /**
     * The only way in. Fetches a play ticket, then opens the board.
     *
     * The ticket names one game, dies in fifteen minutes and is signed by
     * the server, so nothing worth stealing travels in the URL — the bearer
     * token itself never leaves the Authorization header.
     */
    public static void open(final Context ctx, final String slug, final String title) {
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

        String url   = getIntent().getStringExtra(EXTRA_URL);
        String title = getIntent().getStringExtra(EXTRA_TITLE);
        setTitle(title == null ? "" : title);

        cover = findViewById(R.id.cover);
        bar   = findViewById(R.id.coverBar);
        ((TextView) findViewById(R.id.coverTitle))
                .setText(title == null || title.isEmpty() ? getString(R.string.app_name) : title);

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

        final String host = android.net.Uri.parse(
                com.asviora.games.BuildConfig.SITE_BASE).getHost();

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, android.webkit.WebResourceRequest req) {
                // never let a game navigate the WebView somewhere else
                String h = req.getUrl().getHost();
                return h == null || host == null || !h.equalsIgnoreCase(host);
            }

            @Override
            public void onPageFinished(WebView view, String u) {
                // One frame for the game to paint, then hand the screen over.
                view.postDelayed(GameActivity.this::reveal, 220);
            }

            @Override
            public void onReceivedError(WebView view, android.webkit.WebResourceRequest req,
                                        android.webkit.WebResourceError err) {
                if (req != null && req.isForMainFrame()) {
                    ((TextView) findViewById(R.id.coverNote))
                            .setText("Could not load the game. Check your connection.");
                    bar.setVisibility(View.INVISIBLE);
                }
            }
        });

        web.setWebChromeClient(new android.webkit.WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int p) {
                bar.setProgress(Math.max(8, p));
            }
        });

        if (url != null) {
            web.loadUrl(url);
            // A game that never answers should not leave the player staring
            // at a logo for ever.
            cover.postDelayed(this::reveal, 12000);
        } else {
            reveal();
        }
    }

    /** Fade the brand out, once. */
    private void reveal() {
        if (revealed || cover == null) return;
        revealed = true;

        cover.animate()
             .alpha(0f)
             .setDuration(320)
             .setInterpolator(new AccelerateDecelerateInterpolator())
             .setListener(new AnimatorListenerAdapter() {
                 @Override public void onAnimationEnd(Animator a) {
                     cover.setVisibility(View.GONE);
                 }
             });
    }

    @Override
    public void onBackPressed() {
        if (web != null && web.canGoBack()) web.goBack();
        else super.onBackPressed();
    }

    @Override
    protected void onDestroy() {
        if (web != null) {
            web.loadUrl("about:blank");
            web.destroy();
            web = null;
        }
        super.onDestroy();
    }
}
