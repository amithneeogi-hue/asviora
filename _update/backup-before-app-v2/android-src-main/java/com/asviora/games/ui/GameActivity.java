package com.asviora.games.ui;

import android.annotation.SuppressLint;
import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;

/**
 * Plays one of the HTML5 games.
 *
 * The games themselves — chess, sudoku, memory, the cube — are canvas and
 * WebGL, so they run in a controlled WebView rather than being rewritten
 * twice. Everything else in the app is native: this screen exists only for
 * the game surface, and it is locked down to the site's own origin.
 */
public class GameActivity extends AppCompatActivity {

    public static final String EXTRA_URL   = "url";
    public static final String EXTRA_TITLE = "title";

    private WebView web;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_game);

        String url   = getIntent().getStringExtra(EXTRA_URL);
        String title = getIntent().getStringExtra(EXTRA_TITLE);
        setTitle(title == null ? "" : title);

        web = findViewById(R.id.web);
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
        });

        if (url != null) web.loadUrl(url);
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
