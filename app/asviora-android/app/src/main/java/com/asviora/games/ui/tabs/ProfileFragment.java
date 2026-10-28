package com.asviora.games.ui.tabs;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;

import com.asviora.games.R;
import com.asviora.games.model.User;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;
import com.asviora.games.ui.LoginActivity;

import org.json.JSONArray;
import org.json.JSONObject;

/** Your stats, your wallet, your recent rounds — and the way out. */
public class ProfileFragment extends Fragment {

    private TextView name, handle, coins, level, rounds, best, levelsPassed, avatar;
    private LinearLayout wallet, recent;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_profile, c, false);

        name   = v.findViewById(R.id.name);
        handle = v.findViewById(R.id.handle);
        coins  = v.findViewById(R.id.coins);
        level  = v.findViewById(R.id.level);
        rounds = v.findViewById(R.id.rounds);
        best   = v.findViewById(R.id.best);
        levelsPassed = v.findViewById(R.id.levelsPassed);
        wallet = v.findViewById(R.id.wallet);
        recent = v.findViewById(R.id.recent);
        avatar = v.findViewById(R.id.avatar);
        com.asviora.games.ui.Motion.pop(avatar, 60);

        v.findViewById(R.id.logout).setOnClickListener(x ->
                com.asviora.games.ui.ConfirmDialog.show(requireContext(), R.drawable.ic_logout,
                        "Sign out?", "You can sign back in any time — your coins and levels stay safe.",
                        "Stay signed in", getString(R.string.logout), () -> {
                            Session.get().clear();
                            Intent i = new Intent(getContext(), LoginActivity.class);
                            i.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
                            startActivity(i);
                            requireActivity().finish();
                        }));

        load();
        return v;
    }

    private void load() {
        paint(Session.get().user());

        Api.get("profile", new Api.Simple() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;

                Session.get().updateUser(data.optJSONObject("user"));
                paint(Session.get().user());

                JSONObject s = data.optJSONObject("stats");
                if (s != null) {
                    com.asviora.games.ui.Motion.countUp(rounds, s.optInt("rounds"));
                    best.setText("🏆 Best " + com.asviora.games.ui.Motion.format(s.optInt("best")));
                    com.asviora.games.ui.Motion.countUp(levelsPassed, s.optInt("quiz_levels"));
                }

                fill(recent, Api.arr(data, "recent"), true);
                fill(wallet, Api.arr(data, "wallet"), false);
            }
        });
    }

    private void paint(User u) {
        if (u == null) return;
        name.setText(u.name.isEmpty() ? u.username : u.name);
        handle.setText("@" + u.username);
        com.asviora.games.ui.Motion.countUp(coins, u.coins);
        level.setText("Level " + u.level);
        String n = u.name.isEmpty() ? u.username : u.name;
        avatar.setText(n.isEmpty() ? "A" : n.substring(0, 1).toUpperCase(java.util.Locale.ROOT));
    }

    private void fill(LinearLayout box, JSONArray rows, boolean isGames) {
        box.removeAllViews();

        if (rows.length() == 0) {
            TextView none = new TextView(getContext());
            none.setText(isGames ? "No rounds yet — go play one!" : "No coin activity yet.");
            none.setTextColor(androidx.core.content.ContextCompat.getColor(requireContext(), R.color.muted));
            none.setPadding(0, 12, 0, 12);
            box.addView(none);
            return;
        }

        for (int i = 0; i < rows.length() && i < 12; i++) {
            JSONObject r = rows.optJSONObject(i);
            if (r == null) continue;

            View row = LayoutInflater.from(getContext())
                    .inflate(R.layout.item_row, box, false);

            TextView a = row.findViewById(R.id.left);
            TextView b = row.findViewById(R.id.right);
            TextView c = row.findViewById(R.id.sub);
            TextView dot = row.findViewById(R.id.dot);

            if (isGames) {
                a.setText(r.optString("title"));
                b.setText(String.valueOf(r.optInt("score")));
                c.setText(r.optString("result") + " · +" + r.optInt("coins_earned") + " coins");
                String res = r.optString("result");
                dot.setText("win".equals(res) ? "🏆" : ("loss".equals(res) ? "💥" : "🎮"));
            } else {
                int amt = r.optInt("amount");
                a.setText(r.optString("note", r.optString("type")));
                b.setText((amt >= 0 ? "+" : "") + amt);
                b.setTextColor(androidx.core.content.ContextCompat.getColor(
                        requireContext(), amt >= 0 ? R.color.green : R.color.danger));
                c.setText("Balance " + com.asviora.games.ui.Motion.format(r.optInt("balance")));
                dot.setText(amt >= 0 ? "🪙" : "💸");
            }

            box.addView(row);
        }
    }
}
