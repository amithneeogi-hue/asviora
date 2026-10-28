package com.asviora.games.ui.tabs;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.asviora.games.R;
import com.asviora.games.model.Game;
import com.asviora.games.model.User;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;
import com.asviora.games.ui.ExamActivity;
import com.asviora.games.ui.GameActivity;
import com.asviora.games.ui.GameArt;
import com.asviora.games.ui.Motion;
import com.asviora.games.ui.SplashActivity;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;

/**
 * The landing tab: who you are, what you have, a rail of games to jump
 * straight into, and the ways in to quiz, spin, games and the exam section.
 */
public class HomeFragment extends Fragment {

    private TextView greet, hello, avatar, coins, coinChip, level, xpLine,
                     quizLine, spinLine, examLine, featuredEmpty;
    private View examCard, quickHead;
    private android.widget.ProgressBar xpBar;

    private final List<Game> games = new ArrayList<>();
    private RailAdapter rail;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_home, c, false);

        greet    = v.findViewById(R.id.greet);
        hello    = v.findViewById(R.id.hello);
        avatar   = v.findViewById(R.id.avatarInitial);
        coins    = v.findViewById(R.id.coins);
        coinChip = v.findViewById(R.id.coinChip);
        level    = v.findViewById(R.id.level);
        xpLine   = v.findViewById(R.id.xp);
        quizLine = v.findViewById(R.id.quizLine);
        spinLine = v.findViewById(R.id.spinLine);
        xpBar    = v.findViewById(R.id.xpBar);
        featuredEmpty = v.findViewById(R.id.featuredEmpty);
        quickHead     = v.findViewById(R.id.quickHead);

        examCard = v.findViewById(R.id.goExam);
        examLine = v.findViewById(R.id.examLine);

        v.findViewById(R.id.goQuiz).setOnClickListener(x -> select(R.id.nav_quiz));
        v.findViewById(R.id.goSpin).setOnClickListener(x -> select(R.id.nav_spin));
        v.findViewById(R.id.goGames).setOnClickListener(x -> select(R.id.nav_games));
        v.findViewById(R.id.seeAll).setOnClickListener(x -> select(R.id.nav_games));
        examCard.setOnClickListener(x ->
                startActivity(new android.content.Intent(getContext(), ExamActivity.class)));

        // quick-play rail
        RecyclerView rv = v.findViewById(R.id.featured);
        rv.setLayoutManager(new LinearLayoutManager(getContext(), RecyclerView.HORIZONTAL, false));
        rv.setNestedScrollingEnabled(false);
        rail = new RailAdapter();
        rv.setAdapter(rail);

        hello.setText(partOfDay());
        paintExam(v);
        paintUser(false);
        loadSummary();
        loadGames();

        Motion.stagger(v.findViewById(R.id.homeRoot));
        Motion.spin(v.findViewById(R.id.spinIcon), 6000);
        Motion.breathe(v.findViewById(R.id.heroCoin), 0.08f, 1200);
        return v;
    }

    private static String partOfDay() {
        int h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY);
        if (h < 5)  return "Late night grind";
        if (h < 12) return "Good morning";
        if (h < 17) return "Good afternoon";
        return "Good evening";
    }

    /**
     * The Govt Exam tile.
     *
     * The section is announced before it opens, so it carries the same gold
     * SOON badge the website's navbar does, and it disappears entirely when
     * the module is switched off in Admin → Modules. Everything shown here —
     * the label, the badge, the launch date — comes from /config.
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

    private void paintUser(boolean animate) {
        User u = Session.get().user();
        if (u == null) return;
        String first = u.name.isEmpty() ? u.username : u.name.split(" ")[0];
        greet.setText(first);
        avatar.setText(first.isEmpty() ? "A" : first.substring(0, 1).toUpperCase(java.util.Locale.ROOT));

        if (animate) Motion.countUp(coins, u.coins);
        else coins.setText(Motion.format(u.coins));
        coinChip.setText(Motion.format(u.coins));
        level.setText("Level " + u.level);

        int into = u.xpIntoLevel(), need = Math.max(1, u.xpForLevel());
        int pct = Math.max(2, into * 100 / need);
        if (animate) Motion.fill(xpBar, pct);
        else xpBar.setProgress(pct);
        xpLine.setText((need - into) + " XP to level " + (u.level + 1));
    }

    /** Quiz progress, today's spin allowance, and a fresh player row. */
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
                        ? a.optInt("remaining") + " spin(s) waiting"
                        : a.optString("reason", "No spins right now"));
            }
        });

        Api.get("auth/me", new Api.Simple() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;
                Session.get().updateUser(data.optJSONObject("user"));
                paintUser(true);
            }
        });
    }

    /** The quick-play rail uses the same catalogue as the Games tab. */
    private void loadGames() {
        JSONObject modules = SplashActivity.config.optJSONObject("modules");
        if (modules != null && !modules.optBoolean("games", true)) {
            featuredEmpty.setVisibility(View.GONE);
            if (quickHead != null) quickHead.setVisibility(View.GONE);
            return;
        }
        Api.get("games", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;
                games.clear();
                JSONArray a = Api.arr(data, "games");
                for (int i = 0; i < a.length(); i++) {
                    JSONObject o = a.optJSONObject(i);
                    if (o != null) games.add(Game.from(o));
                }
                // featured first
                java.util.Collections.sort(games, (x, y) ->
                        Boolean.compare(y.featured, x.featured));
                rail.notifyDataSetChanged();
                featuredEmpty.setVisibility(games.isEmpty() ? View.VISIBLE : View.GONE);
                featuredEmpty.setText("No games are live right now.");
            }

            @Override public void onError(String code, String message) {
                if (!isAdded()) return;
                featuredEmpty.setVisibility(View.VISIBLE);
                featuredEmpty.setText(message);
            }
        });
    }

    private class RailAdapter extends RecyclerView.Adapter<RailAdapter.VH> {

        class VH extends RecyclerView.ViewHolder {
            final View art;
            final TextView icon, title, meta, badge;
            VH(View v) {
                super(v);
                art   = v.findViewById(R.id.fArt);
                icon  = v.findViewById(R.id.fIcon);
                title = v.findViewById(R.id.fTitle);
                meta  = v.findViewById(R.id.fMeta);
                badge = v.findViewById(R.id.fBadge);
            }
        }

        @NonNull @Override
        public VH onCreateViewHolder(@NonNull ViewGroup parent, int type) {
            return new VH(LayoutInflater.from(parent.getContext())
                    .inflate(R.layout.item_featured, parent, false));
        }

        @Override
        public void onBindViewHolder(@NonNull VH h, int i) {
            Game g = games.get(i);
            GameArt.paint(h.art, g.slug, 16);
            h.icon.setText(GameArt.emoji(g.slug, g.icon));
            h.title.setText(g.title);
            h.meta.setText("+" + g.reward + " coins");
            h.badge.setVisibility(g.featured ? View.VISIBLE : View.GONE);
            h.itemView.setOnClickListener(v -> {
                Motion.tick(v);
                GameActivity.open(requireContext(), g.slug, g.title,
                        GameArt.emoji(g.slug, g.icon),
                        "+" + g.reward + " coins · " + g.category);
            });
        }

        @Override public int getItemCount() { return games.size(); }
    }
}
