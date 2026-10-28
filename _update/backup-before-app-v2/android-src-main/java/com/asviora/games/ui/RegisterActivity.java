package com.asviora.games.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;

import org.json.JSONObject;

/** Create an account. The welcome coins are credited by the server. */
public class RegisterActivity extends AppCompatActivity {

    private EditText fName, fUser, fMail, fPass, fRef;
    private Button btn;
    private TextView error;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_register);

        fName = findViewById(R.id.name);
        fUser = findViewById(R.id.username);
        fMail = findViewById(R.id.email);
        fPass = findViewById(R.id.password);
        fRef  = findViewById(R.id.referral);
        btn   = findViewById(R.id.submit);
        error = findViewById(R.id.error);

        btn.setOnClickListener(v -> submit());
        findViewById(R.id.toLogin).setOnClickListener(v -> finish());
    }

    private void submit() {
        busy(true);
        error.setVisibility(View.GONE);

        Api.post("auth/register",
                Api.body("name", fName.getText().toString().trim(),
                         "username", fUser.getText().toString().trim(),
                         "email", fMail.getText().toString().trim(),
                         "password", fPass.getText().toString(),
                         "referral", fRef.getText().toString().trim(),
                         "device", android.os.Build.MODEL,
                         "platform", "android",
                         "app_version", String.valueOf(com.asviora.games.BuildConfig.VERSION_CODE)),
                new Api.Callback() {
                    @Override public void onSuccess(JSONObject data) {
                        Session.get().save(data.optString("token"),
                                           data.optString("expires_at"),
                                           data.optJSONObject("user"));
                        Intent i = new Intent(RegisterActivity.this, MainActivity.class);
                        i.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
                        startActivity(i);
                        finish();
                    }
                    @Override public void onError(String code, String message) {
                        busy(false);
                        error.setText(message);
                        error.setVisibility(View.VISIBLE);
                    }
                });
    }

    private void busy(boolean b) {
        btn.setEnabled(!b);
        btn.setText(b ? "Creating…" : getString(R.string.register));
    }
}
