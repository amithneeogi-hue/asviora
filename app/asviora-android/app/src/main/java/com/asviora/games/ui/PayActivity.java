package com.asviora.games.ui;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;

import org.json.JSONObject;
import java.net.URISyntaxException;

/**
 * v4 — pays the registration fee or a game pass.
 *
 * The website's pay.php does the real work (Razorpay / Cashfree checkout);
 * this screen opens it with a signed one-game-style ticket from the API,
 * so the player's token never travels in a URL. It hands UPI app links
 * (upi://, intent://, PhonePe, GPay, Paytm…) to the phone, and closes
 * itself when the result page links to asviora://pay-done.
 */
public class PayActivity extends AppCompatActivity {

    public static final String EXTRA_URL = "url";
    public static final int REQ = 7301;

    private WebView web;
    private ProgressBar line;
    private boolean paid;

    /** Open with a URL the server already signed (from a 402 answer). */
    public static void open(Context ctx, String url) {
        Intent it = new Intent(ctx, PayActivity.class).putExtra(EXTRA_URL, url);
        if (ctx instanceof Activity) ((Activity) ctx).startActivityForResult(it, REQ);
        else ctx.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
    }

    /** Ask the API for a pay link first. for = "registration" | "game". */
    public static void openFor(final Context ctx, String forWhat, @Nullable String item) {
        String route = "pay/link?for=" + Uri.encode(forWhat)
                + (item != null ? "&item=" + Uri.encode(item) : "");
        Api.get(route, new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                String url = data.optString("url", "");
                if (!url.isEmpty()) open(ctx, url);
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
        setContentView(R.layout.activity_pay);

        line = findViewById(R.id.payLine);
        web  = findViewById(R.id.payWeb);
        findViewById(R.id.payBack).setOnClickListener(v -> onBackPressed());

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);   // gateway checkout frames

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setSupportMultipleWindows(false);
        s.setJavaScriptCanOpenWindowsAutomatically(true);
        s.setUserAgentString(s.getUserAgentString() + " AsvioraApp/"
                + com.asviora.games.BuildConfig.VERSION_NAME);
        web.setBackgroundColor(0xFF0B0C0E);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
                return route(req.getUrl().toString());
            }

            @SuppressWarnings("deprecation")
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return route(url);
            }

            @Override
            public void onReceivedError(WebView view, android.webkit.WebResourceRequest req,
                                        android.webkit.WebResourceError err) {
                if (req != null && req.isForMainFrame()) {
                    findViewById(R.id.payError).setVisibility(View.VISIBLE);
                }
            }
        });
        web.setWebChromeClient(new android.webkit.WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int p) {
                line.setVisibility(p >= 100 ? View.INVISIBLE : View.VISIBLE);
                line.setProgress(p);
            }
        });

        String url = getIntent().getStringExtra(EXTRA_URL);
        if (url == null || url.isEmpty()) { finish(); return; }
        web.loadUrl(url);
    }

    /** true = handled here, the WebView must not load it. */
    private boolean route(String url) {
        if (url == null) return false;
        Uri u = Uri.parse(url);
        String scheme = u.getScheme() == null ? "" : u.getScheme().toLowerCase(java.util.Locale.US);

        if ("asviora".equals(scheme)) {                   // asviora://pay-done?status=paid
            paid = "paid".equals(u.getQueryParameter("status"));
            done();
            return true;
        }
        if ("http".equals(scheme) || "https".equals(scheme)) {
            return false;                                 // site + gateway pages stay inside
        }
        // UPI and wallet apps: upi://, intent://, phonepe://, tez://, paytmmp://, gpay:// …
        try {
            Intent it = "intent".equals(scheme)
                    ? Intent.parseUri(url, Intent.URI_INTENT_SCHEME)
                    : new Intent(Intent.ACTION_VIEW, u);
            it.addCategory(Intent.CATEGORY_BROWSABLE);
            it.setComponent(null);
            startActivity(it);
        } catch (ActivityNotFoundException | URISyntaxException e) {
            if ("intent".equals(scheme)) {
                try {
                    Intent it = Intent.parseUri(url, Intent.URI_INTENT_SCHEME);
                    String fb = it.getStringExtra("browser_fallback_url");
                    if (fb != null) web.loadUrl(fb);
                } catch (URISyntaxException ignored) { }
            }
            Toast.makeText(this, "No app found to complete this payment.", Toast.LENGTH_SHORT).show();
        }
        return true;
    }

    /** Refresh the player (fee flag, coins) and close with the result. */
    private void done() {
        if (paid) Toast.makeText(this, R.string.pay_done, Toast.LENGTH_SHORT).show();
        Api.get("auth/me", new Api.Simple() {
            @Override public void onSuccess(JSONObject data) {
                Session.get().updateUser(data.optJSONObject("user"));
            }
        });
        setResult(paid ? RESULT_OK : RESULT_CANCELED);
        finish();
    }

    @Override
    public void onBackPressed() {
        if (web != null && web.canGoBack()) {
            web.goBack();
            return;
        }
        ConfirmDialog.show(this, R.drawable.ic_exit,
                getString(R.string.pay_leave_title), getString(R.string.pay_leave_body),
                getString(R.string.stay), getString(R.string.leave), this::done);
    }

    @Override
    protected void onDestroy() {
        if (web != null) {
            web.stopLoading();
            web.destroy();
            web = null;
        }
        super.onDestroy();
    }
}
