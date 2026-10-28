package com.asviora.games.ui;

import android.os.Bundle;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Spinner;
import android.widget.TextView;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;
import com.asviora.games.model.User;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/**
 * Government competitive exam — the section that is announced before it opens.
 *
 * Everything on this screen is written by an admin (Admin → Govt Exam): the
 * heading, the two blocks of copy, the launch date and whether it still reads
 * "soon". Nothing is hardcoded, so the section can go live without shipping a
 * new build — the day the status flips to 'live' this screen stops calling it
 * a countdown, and the tile on Home loses its badge.
 *
 * The form is the same interest list the website keeps, so a player who signed
 * up in the browser and then signs up here updates one row rather than making
 * a second.
 */
public class ExamActivity extends AppCompatActivity {

    private TextView heading, tagline, blurb, count, message;
    private EditText name, email, phone, note;
    private Spinner stream;
    private Button submit;

    private final List<String> streams = new ArrayList<>();

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_exam);

        heading = findViewById(R.id.heading);
        tagline = findViewById(R.id.tagline);
        blurb   = findViewById(R.id.blurb);
        count   = findViewById(R.id.count);
        message = findViewById(R.id.message);
        name    = findViewById(R.id.name);
        email   = findViewById(R.id.email);
        phone   = findViewById(R.id.phone);
        note    = findViewById(R.id.note);
        stream  = findViewById(R.id.stream);
        submit  = findViewById(R.id.submit);

        User u = Session.get().user();
        if (u != null) {
            name.setText(u.name);
            email.setText(u.email == null ? "" : u.email);
        }

        submit.setOnClickListener(v -> join());
        load();
    }

    private void load() {
        Api.get("exam", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                heading.setText(data.optString("label", "Govt Exam"));
                setTitle(data.optString("label", "Govt Exam"));

                tagline.setText(data.optString("tagline", ""));
                tagline.setVisibility(tagline.getText().length() == 0 ? View.GONE : View.VISIBLE);

                blurb.setText(data.optString("blurb", ""));
                blurb.setVisibility(blurb.getText().length() == 0 ? View.GONE : View.VISIBLE);

                count.setText(countdown(data));

                streams.clear();
                JSONArray a = Api.arr(data, "streams");
                for (int i = 0; i < a.length(); i++) streams.add(a.optString(i));
                if (streams.isEmpty()) streams.add("Other");

                ArrayAdapter<String> ad = new ArrayAdapter<>(ExamActivity.this,
                        android.R.layout.simple_spinner_item, streams);
                ad.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
                stream.setAdapter(ad);
            }

            @Override public void onError(String code, String message2) {
                message.setText(message2);
                message.setVisibility(View.VISIBLE);
                submit.setEnabled(false);
            }
        });
    }

    /** "Opens in 24 days" / "Opening soon" / "Now open". */
    private String countdown(JSONObject d) {
        if ("live".equals(d.optString("status"))) {
            return "Now open";
        }
        if (d.isNull("days")) {
            return "Opening soon";
        }
        int days = d.optInt("days");
        if (days > 1)  return "Opens in " + days + " days";
        if (days == 1) return "Opens tomorrow";
        if (days == 0) return "Opens today";
        return "Opening soon";
    }

    private void join() {
        String mail = email.getText().toString().trim();
        if (!android.util.Patterns.EMAIL_ADDRESS.matcher(mail).matches()) {
            message.setText("Please give an email address we can reach you on.");
            message.setVisibility(View.VISIBLE);
            return;
        }

        submit.setEnabled(false);
        message.setVisibility(View.GONE);

        Api.post("exam/notify", Api.body(
                "name",      name.getText().toString().trim(),
                "email",     mail,
                "phone",     phone.getText().toString().trim(),
                "exam_pref", stream.getSelectedItem() == null
                                 ? "" : stream.getSelectedItem().toString(),
                "note",      note.getText().toString().trim()
        ), new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                message.setText(data.optString("message", "You are on the list."));
                message.setTextColor(ContextCompat.getColor(ExamActivity.this, R.color.green));
                message.setVisibility(View.VISIBLE);
                submit.setText("On the list");
            }

            @Override public void onError(String code, String msg) {
                message.setText(msg);
                message.setTextColor(ContextCompat.getColor(ExamActivity.this, R.color.danger));
                message.setVisibility(View.VISIBLE);
                submit.setEnabled(true);
            }
        });
    }
}
