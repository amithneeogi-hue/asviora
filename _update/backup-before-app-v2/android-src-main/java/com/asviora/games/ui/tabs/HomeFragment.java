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
import com.asviora.games.ui.SplashActivity;

import org.json.JSONObject;

/**
 * The landing tab: who you are, what you have, and the two things most
 * people open the app to do — carry on with the quiz, or take today's spin.
 */
public class HomeFragment extends Fragment {

    private TextView greet, coins, level, xpLine, quizLine, spinLine;

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

        v.findViewById(R.id.goQuiz).setOnClickListener(x -> select(R.id.nav_quiz));
        v.findViewById(R.id.goSpin).setOnClickListener(x -> select(R.id.nav_spin));
        v.findViewById(R.id.goGames).setOnClickListener(x -> select(R.id.nav_games));

        paintUser();
        loadSummary();
        return v;
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
        greet.setText("Hello, " + (u.name.isEmpty() ? u.username : u.name.split(" ")[0]));
        coins.setText(String.valueOf(u.coins));
        level.setText("Level " + u.level);
        xpLine.setText(u.xpIntoLevel() + " / " + u.xpForLevel() + " XP to the next level");
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
