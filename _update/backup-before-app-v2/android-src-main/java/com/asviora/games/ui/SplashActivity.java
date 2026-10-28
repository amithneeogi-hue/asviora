package com.asviora.games.ui;

import android.content.Intent;
import android.os.Bundle;
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
 */
public class SplashActivity extends AppCompatActivity {

    /** What the server says is live. Read by the rest of the app. */
    public static JSONObject config = new JSONObject();

    private TextView status;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_splash);
        status = findViewById(R.id.status);

        loadConfig();
    }

    private void loadConfig() {
        status.setText(R.string.loading);

        Api.get("config", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                config = data;

                JSONObject app = data.optJSONObject("app");
                int min = app != null ? app.optInt("min_version", 1) : 1;
                if (com.asviora.games.BuildConfig.VERSION_CODE < min) {
                    status.setText("This version is too old. Please update the app.");
                    return;
                }

                if (Session.get().signedIn()) {
                    verifyToken();
                } else {
                    go(LoginActivity.class);
                }
            }

            @Override public void onError(String code, String message) {
                status.setText(message);
                findViewById(R.id.retry).setVisibility(android.view.View.VISIBLE);
                findViewById(R.id.retry).setOnClickListener(v -> {
                    v.setVisibility(android.view.View.GONE);
                    loadConfig();
                });
            }
        });
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
