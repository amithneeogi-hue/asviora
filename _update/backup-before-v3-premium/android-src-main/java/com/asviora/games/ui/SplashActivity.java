package com.asviora.games.ui;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.TextView;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;

import org.json.JSONObject;

/**
 * First screen. It does two things before deciding where to send you:
 * asks the server what is switched on (so a hidden module never appears in
 * the app either), and checks whether the stored token is still good.
 *
 * It is also where a build finds out it is behind. Two settings decide that,
 * both in Admin → Site Settings: app_min_version stops a build that can no
 * longer talk to the server, and app_latest_version only mentions that a
 * newer one exists. The first is a wall with an update button; the second is
 * a note the player can walk past.
 */
public class SplashActivity extends AppCompatActivity {

    /** What the server says is live. Read by the rest of the app. */
    public static JSONObject config = new JSONObject();

    private TextView status;
    private Button update, later, retry;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_splash);
        status = findViewById(R.id.status);
        update = findViewById(R.id.update);
        later  = findViewById(R.id.later);
        retry  = findViewById(R.id.retry);

        loadConfig();
    }

    private void loadConfig() {
        status.setText(R.string.loading);

        Api.get("config", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                config = data;

                JSONObject app = data.optJSONObject("app");
                int mine   = com.asviora.games.BuildConfig.VERSION_CODE;
                int min    = app != null ? app.optInt("min_version", 1) : 1;
                int latest = app != null ? app.optInt("latest_version", 1) : 1;
                String url = app != null ? app.optString("update_url", "") : "";

                if (mine < min) {
                    status.setText("This version of the app is too old to keep working. "
                            + "Please install the new one.");
                    offerUpdate(url, false);
                    return;
                }

                if (mine < latest) {
                    status.setText("A newer version of the app is out.");
                    offerUpdate(url, true);
                    return;
                }

                carryOn();
            }

            @Override public void onError(String code, String message) {
                status.setText(message);
                retry.setVisibility(View.VISIBLE);
                retry.setOnClickListener(v -> {
                    v.setVisibility(View.GONE);
                    loadConfig();
                });
            }
        });
    }

    /** Send the player to the download, and let them past when it is optional. */
    private void offerUpdate(final String url, boolean optional) {
        update.setVisibility(View.VISIBLE);
        update.setOnClickListener(v -> {
            if (url == null || url.isEmpty()) {
                status.setText("Ask the site for the new build — no download link is set.");
                return;
            }
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
            } catch (Exception ignored) {
                status.setText("Could not open the download link.");
            }
        });

        if (optional) {
            later.setVisibility(View.VISIBLE);
            later.setOnClickListener(v -> {
                update.setVisibility(View.GONE);
                later.setVisibility(View.GONE);
                carryOn();
            });
        }
    }

    /** Signed in or not — that is the last thing left to decide. */
    private void carryOn() {
        if (Session.get().signedIn()) {
            verifyToken();
        } else {
            go(LoginActivity.class);
        }
    }

    /** A stored token can be expired or revoked; find out before trusting it. */
    private void verifyToken() {
        Api.get("auth/me", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                Session.get().updateUser(data.optJSONObject("user"));
                go(MainActivity.class);
            }
            @Override public void onError(String code, String message) {
                Session.get().clear();
                go(LoginActivity.class);
            }
        });
    }

    private void go(Class<?> target) {
        startActivity(new Intent(this, target));
        finish();
    }
}
