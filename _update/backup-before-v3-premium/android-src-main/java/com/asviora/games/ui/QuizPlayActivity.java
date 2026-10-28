package com.asviora.games.ui;

import android.os.Bundle;
import android.os.CountDownTimer;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * One level, one question at a time.
 *
 * The app never holds the answer key: a question arrives with its text and
 * options only, and which option was right comes back after the choice has
 * been sent. The countdown here is a courtesy — the server keeps its own
 * clock, so a paused app cannot buy extra thinking time.
 */
public class QuizPlayActivity extends AppCompatActivity {

    public static final String EXTRA_DIFF  = "difficulty";
    public static final String EXTRA_LEVEL = "level";
    public static final String EXTRA_CAT   = "cat";

    private TextView step, timer, questionText, feedback, coinsView;
    private LinearLayout options;
    private ProgressBar progress;
    private Button next;
    private View resultBox;

    private long session;
    private int levelNo, cat, answered, correct;
    private String diff = "easy";
    private CountDownTimer clock;
    private boolean locked;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_quiz_play);

        diff    = getIntent().getStringExtra(EXTRA_DIFF);
        if (diff == null) diff = "easy";
        levelNo = getIntent().getIntExtra(EXTRA_LEVEL, 0);
        cat     = getIntent().getIntExtra(EXTRA_CAT, 0);

        step         = findViewById(R.id.step);
        timer        = findViewById(R.id.timer);
        questionText = findViewById(R.id.question);
        options      = findViewById(R.id.options);
        feedback     = findViewById(R.id.feedback);
        coinsView    = findViewById(R.id.coins);
        progress     = findViewById(R.id.progress);
        next         = findViewById(R.id.next);
        resultBox    = findViewById(R.id.resultBox);

        next.setOnClickListener(v -> loadQuestion());
        findViewById(R.id.quit).setOnClickListener(v -> confirmQuit());

        start();
    }

    // ---------------------------------------------------------------
    private void start() {
        questionText.setText(R.string.loading);

        Api.post("quiz/start",
                Api.body("difficulty", diff, "level", levelNo, "cat", cat),
                new Api.Callback() {
                    @Override public void onSuccess(JSONObject data) {
                        session = data.optLong("session");
                        levelNo = data.optInt("level", levelNo);
                        progress.setMax(Math.max(1, data.optInt("total")));
                        setTitle("Level " + levelNo);

                        if (data.optBoolean("recycled")) {
                            feedback.setVisibility(View.VISIBLE);
                            feedback.setText("You have seen most of this bank — a few older "
                                    + "questions are back so the level could be filled.");
                        }
                        loadQuestion();
                    }
                    @Override public void onError(String code, String message) {
                        fatal(message);
                    }
                });
    }

    private void loadQuestion() {
        stopClock();
        locked = false;
        next.setVisibility(View.GONE);
        feedback.setVisibility(View.GONE);
        options.removeAllViews();
        questionText.setText(R.string.loading);

        Api.post("quiz/question", Api.body("session", session), new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (data.optBoolean("retry")) { loadQuestion(); return; }
                if (data.optBoolean("done"))  { finishRound(); return; }

                int index = data.optInt("index");
                int total = data.optInt("total");
                step.setText("Question " + index + " of " + total);
                progress.setProgress(index - 1);

                questionText.setText(data.optString("question"));
                buildOptions(Api.arr(data, "options"));
                runClock(data.optInt("seconds", 30));
            }
            @Override public void onError(String code, String message) {
                fatal(message);
            }
        });
    }

    private void buildOptions(JSONArray opts) {
        for (int i = 0; i < opts.length(); i++) {
            final int choice = i + 1;

            TextView b = new TextView(this);
            b.setText(((char) ('A' + i)) + ".   " + opts.optString(i));
            b.setTextSize(15f);
            b.setPadding(dp(16), dp(15), dp(16), dp(15));
            b.setBackgroundResource(R.drawable.bg_option);
            b.setTextColor(getResources().getColor(R.color.text, null));

            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.MATCH_PARENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT);
            lp.bottomMargin = dp(10);
            b.setLayoutParams(lp);

            b.setOnClickListener(v -> answer(choice));
            options.addView(b);
        }
    }

    private void answer(int choice) {
        if (locked) return;
        locked = true;
        stopClock();

        Api.post("quiz/answer", Api.body("session", session, "choice", choice),
                new Api.Callback() {
                    @Override public void onSuccess(JSONObject data) {
                        answered++;
                        boolean right = data.optBoolean("correct");
                        if (right) correct++;

                        int answer = data.optInt("answer");
                        for (int i = 0; i < options.getChildCount(); i++) {
                            View child = options.getChildAt(i);
                            if (i + 1 == answer) {
                                child.setBackgroundResource(R.drawable.bg_option_right);
                            } else if (i + 1 == choice) {
                                child.setBackgroundResource(R.drawable.bg_option_wrong);
                            }
                        }

                        String note = right ? "Correct" :
                                (data.optBoolean("expired") ? "Time ran out" : "Not quite");
                        String why = data.optString("explanation", "");
                        feedback.setVisibility(View.VISIBLE);
                        feedback.setText(why.isEmpty() || "null".equals(why)
                                ? note : note + " — " + why);

                        int c = data.optInt("coins");
                        if (c > 0) coinsView.setText("+" + c + " coins");

                        next.setVisibility(View.VISIBLE);
                        next.setText(data.optBoolean("done") ? "See result" : "Next question");
                        if (data.optBoolean("done")) {
                            next.setOnClickListener(v -> finishRound());
                        }
                    }

                    @Override public void onError(String code, String message) {
                        locked = false;
                        feedback.setVisibility(View.VISIBLE);
                        feedback.setText(message);
                    }
                });
    }

    private void finishRound() {
        stopClock();
        Api.post("quiz/finish", Api.body("session", session), new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                Session.get().setCoins(data.optInt("balance"));
                showResult(data);
            }
            @Override public void onError(String code, String message) {
                fatal(message);
            }
        });
    }

    private void showResult(JSONObject r) {
        findViewById(R.id.playBox).setVisibility(View.GONE);
        resultBox.setVisibility(View.VISIBLE);

        boolean passed = r.optBoolean("level_passed");
        int percent = r.optInt("percent");
        int stars   = r.optInt("stars");

        ((TextView) findViewById(R.id.rTitle)).setText(
                r.isNull("level_passed")
                        ? "Round complete"
                        : (passed ? "Level " + r.optInt("level") + " cleared"
                                  : "Level " + r.optInt("level") + " not cleared"));

        StringBuilder st = new StringBuilder();
        for (int i = 1; i <= 3; i++) st.append(i <= stars ? "★" : "☆");
        ((TextView) findViewById(R.id.rStars)).setText(st.toString());

        ((TextView) findViewById(R.id.rScore)).setText(
                r.optInt("correct") + " / " + r.optInt("total"));

        ((TextView) findViewById(R.id.rSub)).setText(
                percent + "% · " + r.optInt("coins") + " coins · " + r.optInt("xp") + " XP"
                        + (r.isNull("pass_mark") ? "" : "  (pass at " + r.optInt("pass_mark") + "%)"));

        TextView bonus = findViewById(R.id.rBonus);
        if (r.optBoolean("first_clear") && r.optInt("bonus") > 0) {
            bonus.setVisibility(View.VISIBLE);
            bonus.setText("First clear bonus: +" + r.optInt("bonus") + " coins");
        } else {
            bonus.setVisibility(View.GONE);
        }

        Button again = findViewById(R.id.rAgain);
        Button done  = findViewById(R.id.rDone);

        if (r.optBoolean("next_unlocked") && r.optInt("next_level") > 0) {
            again.setText("Next level →");
            final int nextLevel = r.optInt("next_level");
            again.setOnClickListener(v -> {
                android.content.Intent i = new android.content.Intent(this, QuizPlayActivity.class);
                i.putExtra(EXTRA_DIFF, diff);
                i.putExtra(EXTRA_LEVEL, nextLevel);
                i.putExtra(EXTRA_CAT, cat);
                startActivity(i);
                finish();
            });
        } else {
            again.setText("Try again");
            again.setOnClickListener(v -> {
                android.content.Intent i = new android.content.Intent(this, QuizPlayActivity.class);
                i.putExtra(EXTRA_DIFF, diff);
                i.putExtra(EXTRA_LEVEL, r.optInt("level", levelNo));
                i.putExtra(EXTRA_CAT, cat);
                startActivity(i);
                finish();
            });
        }

        done.setOnClickListener(v -> finish());
    }

    // ---------------------------------------------------------------
    private void runClock(int seconds) {
        stopClock();
        timer.setText(String.valueOf(seconds));

        clock = new CountDownTimer(seconds * 1000L, 250) {
            @Override public void onTick(long left) {
                timer.setText(String.valueOf((int) Math.ceil(left / 1000d)));
            }
            @Override public void onFinish() {
                timer.setText("0");
                // 0 means "ran out of time"; the server checks its own clock too
                if (!locked) answer(0);
            }
        }.start();
    }

    private void stopClock() {
        if (clock != null) { clock.cancel(); clock = null; }
    }

    private void confirmQuit() {
        new AlertDialog.Builder(this)
                .setTitle("Leave the level?")
                .setMessage("The round will not be saved and the questions you have already "
                        + "seen will not come back for a while.")
                .setPositiveButton("Leave", (d, w) -> finish())
                .setNegativeButton("Stay", null)
                .show();
    }

    private void fatal(String msg) {
        stopClock();
        questionText.setText(msg);
        options.removeAllViews();
        next.setVisibility(View.GONE);
    }

    private int dp(int v) {
        return Math.round(v * getResources().getDisplayMetrics().density);
    }

    @Override protected void onDestroy() {
        stopClock();
        super.onDestroy();
    }

    @Override public void onBackPressed() {
        if (resultBox.getVisibility() == View.VISIBLE) super.onBackPressed();
        else confirmQuit();
    }
}
