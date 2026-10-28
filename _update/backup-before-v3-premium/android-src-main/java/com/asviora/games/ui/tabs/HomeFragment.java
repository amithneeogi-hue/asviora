package com.asviora.games.ui.tabs;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;

import com.asviora.games.R;
import com.asviora.games.model.User;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;
import com.asviora.games.ui.ExamActivity;
import com.asviora.games.ui.SplashActivity;

import org.json.JSONObject;

/**
 * The landing tab: who you are, what you have, and the two things most
 * people open the app to do — carry on with the quiz, or take today's spin.
 */
public class HomeFragment extends Fragment {

    private TextView greet, coins, level, xpLine, quizLine, spinLine, examLine;
    private View examCard;
    private android.widget.ProgressBar xpBar;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_home, c, false);

        greet    = v.findViewById(R.id.greet);
        coins    = v.findViewById(R.id.coins);
        level    = v.findViewById(R.id.level);
        xpLine   = v.findViewById(R.id.xp);
        quizLine = v.findViewById(R.id.quizLine);
        spinLine = v.findViewById(R.id.spinLine);
        xpBar    = v.findViewById(R.id.xpBar);

        examCard = v.findViewById(R.id.goExam);
        examLine = v.findViewById(R.id.examLine);

        v.findViewById(R.id.goQuiz).setOnClickListener(x -> select(R.id.nav_quiz));
        v.findViewById(R.id.goSpin).setOnClickListener(x -> select(R.id.nav_spin));
        v.findViewById(R.id.goGames).setOnClickListener(x -> select(R.id.nav_games));
        examCard.setOnClickListener(x ->
                startActivity(new android.content.Intent(getContext(), ExamActivity.class)));

        paintExam(v);
        paintUser();
        loadSummary();
        return v;
    }

    /**
     * The Govt Exam tile.
     *
     * It is a bottom-bar tab on nobody's phone: the section is announced
     * before it opens, so it belongs on the landing screen with the same gold
     * SOON badge the website's navbar carries, and it disappears entirely
     * when the module is switched off in Admin → Modules. Everything shown
     * here — the label, the badge, the launch date — comes from /config, so
     * the day it goes live no new build is needed.
     */
    private void paintExam(View v) {
        JSONObject modules = SplashActivity.config.optJSONObject("modules");
        boolean on = modules != null && modules.optBoolean("govt_exam", false);
        if (!on) {
            examCard.setVisibility(View.GONE);
            return;
        }
        examCard.setVisibility(View.VISIBLE);

        JSONObject exam = SplashActivity.config.optJSONObject("govt_exam");
        if (exam == null) return;

        ((TextView) v.findViewById(R.id.examTitle))
                .setText(exam.optString("label", getString(R.string.govt_exam)));

        boolean live = "live".equals(exam.optString("status"));
        v.findViewById(R.id.examBadge).setVisibility(live ? View.GONE : View.VISIBLE);

        String tagline = exam.optString("tagline", "");
        if (!live && !exam.isNull("days")) {
            int days = exam.optInt("days");
            if (days > 1)       tagline = "Opens in " + days + " days";
            else if (days == 1) tagline = "Opens tomorrow";
            else if (days == 0) tagline = "Opens today";
        }
        if (!tagline.isEmpty()) examLine.setText(tagline);
    }

    private void select(int navId) {
        View nav = requireActivity().findViewById(R.id.nav);
        if (nav instanceof com.google.android.material.bottomnavigation.BottomNavigationView) {
            ((com.google.android.material.bottomnavigation.BottomNavigationView) nav)
                    .setSelectedItemId(navId);
        }
    }

    private void paintUser() {
        User u = Session.get().user();
        if (u == null) return;
        greet.setText(u.name.isEmpty() ? u.username : u.name.split(" ")[0]);
        coins.setText(String.format(java.util.Locale.US, "%,d", u.coins));
        level.setText("Level " + u.level);

        int into = u.xpIntoLevel(), need = Math.max(1, u.xpForLevel());
        xpBar.setProgress(Math.max(2, into * 100 / need));
        xpLine.setText((need - into) + " XP to level " + (u.level + 1));
    }

    /** One call fills both cards: quiz progress and today's spin allowance. */
    private void loadSummary() {
        if (SplashActivity.config.optJSONObject("modules") != null
                && SplashActivity.config.optJSONObject("modules").optBoolean("quiz", true)) {

            Api.get("quiz/overview", new Api.Simple() {
                @Override public void onSuccess(JSONObject data) {
                    JSONObject o = data.optJSONObject("overview");
                    if (o == null || !isAdded()) return;
                    quizLine.setText(o.optInt("passed") + " of " + o.optInt("total")
                            + " levels cleared");
                }
            });
        }

        Api.get("spin/status", new Api.Simple() {
            @Override public void onSuccess(JSONObject data) {
                JSONObject a = data.optJSONObject("access");
                if (a == null || !isAdded()) return;
                spinLine.setText(a.optBoolean("allowed")
                        ? a.optInt("remaining") + " spin(s) waiting for you"
                        : a.optString("reason", "No spins right now"));
            }
        });

        Api.get("auth/me", new Api.Simple() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;
                Session.get().updateUser(data.optJSONObject("user"));
                paintUser();
            }
        });
    }
}
