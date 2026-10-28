package com.asviora.games.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;

import org.json.JSONObject;

/** Sign in with a username or an email address. */
public class LoginActivity extends AppCompatActivity {

    private EditText fLogin, fPass;
    private Button btn;
    private TextView error;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_login);

        fLogin = findViewById(R.id.login);
        fPass  = findViewById(R.id.password);
        btn    = findViewById(R.id.submit);
        error  = findViewById(R.id.error);

        btn.setOnClickListener(v -> submit());
        Motion.stagger(findViewById(R.id.authRoot));
        findViewById(R.id.toRegister).setOnClickListener(v ->
                startActivity(new Intent(this, RegisterActivity.class)));
    }

    private void submit() {
        String id = fLogin.getText().toString().trim();
        String pw = fPass.getText().toString();

        if (id.isEmpty() || pw.isEmpty()) {
            show("Enter your username and password.");
            return;
        }

        busy(true);
        Api.post("auth/login",
                Api.body("login", id, "password", pw,
                         "device", android.os.Build.MODEL,
                         "platform", "android",
                         "app_version", String.valueOf(com.asviora.games.BuildConfig.VERSION_CODE)),
                new Api.Callback() {
                    @Override public void onSuccess(JSONObject data) {
                        Session.get().save(data.optString("token"),
                                           data.optString("expires_at"),
                                           data.optJSONObject("user"));
                        startActivity(new Intent(LoginActivity.this, MainActivity.class));
                        finish();
                    }
                    @Override public void onError(String code, String message) {
                        busy(false);
                        show(message);
                    }
                });
    }

    private void busy(boolean b) {
        btn.setEnabled(!b);
        btn.setText(b ? "Signing in…" : getString(R.string.login));
    }

    private void show(String msg) {
        error.setText(msg);
        error.setVisibility(View.VISIBLE);
    }
}
