package com.asviora.games.ui.tabs;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;

import com.asviora.games.R;
import com.asviora.games.model.Ladder;
import com.asviora.games.net.Api;
import com.asviora.games.ui.QuizLevelsActivity;

import org.json.JSONObject;

/**
 * The three ladders.
 *
 * Easy is always open; Mid opens once every Easy level is cleared, Hard
 * once every Mid level is. A locked card says what is still missing rather
 * than just refusing to open.
 */
public class QuizFragment extends Fragment {

    private LinearLayout holder;
    private TextView overall, error;
    private ProgressBar bar;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_quiz, c, false);
        holder  = v.findViewById(R.id.ladders);
        overall = v.findViewById(R.id.overall);
        error   = v.findViewById(R.id.error);
        bar     = v.findViewById(R.id.bar);
        return v;
    }

    @Override public void onResume() {
        super.onResume();
        load();                       // refresh after a level was played
    }

    private void load() {
        Api.get("quiz/overview", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;
                error.setVisibility(View.GONE);

                JSONObject o = data.optJSONObject("overview");
                if (o == null) return;

                int passed = o.optInt("passed"), total = o.optInt("total");
                overall.setText(passed + " of " + total + " levels cleared");
                bar.setMax(Math.max(1, total));
                com.asviora.games.ui.Motion.fill(bar, passed);

                JSONObject diffs = o.optJSONObject("difficulties");
                holder.removeAllViews();
                if (diffs == null) return;

                for (String key : new String[]{"easy", "mid", "hard"}) {
                    JSONObject d = diffs.optJSONObject(key);
                    if (d != null) addCard(Ladder.from(key, d));
                }
                holder.scheduleLayoutAnimation();
            }

            @Override public void onError(String code, String message) {
                if (!isAdded()) return;
                error.setVisibility(View.VISIBLE);
                error.setText(message);
            }
        });
    }

    private void addCard(Ladder d) {
        View card = LayoutInflater.from(getContext())
                .inflate(R.layout.item_ladder, holder, false);

        ((TextView) card.findViewById(R.id.label)).setText(d.label);
        ((TextView) card.findViewById(R.id.blurb)).setText(
                d.unlocked ? d.blurb : d.lockNote);
        ((TextView) card.findViewById(R.id.count)).setText(
                d.passed + " / " + d.levels + " levels");
        ((TextView) card.findViewById(R.id.rules)).setText(
                d.perLevel + " questions · " + d.seconds + "s each · pass at " + d.passMark + "%");

        ProgressBar p = card.findViewById(R.id.bar);
        p.setMax(Math.max(1, d.levels));
        p.setProgress(d.passed);

        TextView lock = card.findViewById(R.id.lock);
        lock.setVisibility(d.unlocked ? View.GONE : View.VISIBLE);
        card.findViewById(R.id.chev).setVisibility(d.unlocked ? View.VISIBLE : View.GONE);
        card.setAlpha(d.unlocked ? 1f : 0.55f);

        // a coloured badge per rung of the ladder
        TextView badge = card.findViewById(R.id.badge);
        String art;
        String emoji;
        switch (d.key) {
            case "easy": art = "sudoku"; emoji = "🌱"; break;
            case "mid":  art = "spin";   emoji = "🔥"; break;
            case "hard": art = "memory"; emoji = "👑"; break;
            default:     art = d.key;    emoji = "⭐"; break;
        }
        badge.setText(emoji);
        badge.setBackground(com.asviora.games.ui.GameArt.chip(badge, art, 16));

        if (d.unlocked && d.levels > 0) {
            card.setOnClickListener(v -> {
                Intent i = new Intent(getContext(), QuizLevelsActivity.class);
                i.putExtra(QuizLevelsActivity.EXTRA_DIFF, d.key);
                i.putExtra(QuizLevelsActivity.EXTRA_LABEL, d.label);
                startActivity(i);
            });
        }

        holder.addView(card);
    }
}
