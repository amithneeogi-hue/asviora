package com.asviora.games.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.GridLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.asviora.games.R;
import com.asviora.games.model.QuizLevel;
import com.asviora.games.net.Api;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/**
 * The level map for one difficulty.
 *
 * A tile is locked, open, or passed with one to three stars. Only the
 * first unpassed level is open — clearing it opens the next. The map is
 * reloaded on every return so a level just cleared shows its stars
 * immediately.
 */
public class QuizLevelsActivity extends AppCompatActivity {

    public static final String EXTRA_DIFF  = "difficulty";
    public static final String EXTRA_LABEL = "label";
    public static final String EXTRA_CAT   = "cat";

    private final List<QuizLevel> levels = new ArrayList<>();
    private Adapter adapter;
    private TextView subtitle, error;

    private String diff = "easy";
    private int cat;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_levels);

        diff = getIntent().getStringExtra(EXTRA_DIFF);
        if (diff == null) diff = "easy";
        cat = getIntent().getIntExtra(EXTRA_CAT, 0);

        String label = getIntent().getStringExtra(EXTRA_LABEL);
        ((TextView) findViewById(R.id.title)).setText(label == null ? "Levels" : label);
        subtitle = findViewById(R.id.subtitle);
        error    = findViewById(R.id.error);

        findViewById(R.id.back).setOnClickListener(v -> finish());

        RecyclerView rv = findViewById(R.id.grid);
        rv.setLayoutManager(new GridLayoutManager(this, 4));
        adapter = new Adapter();
        rv.setAdapter(adapter);
    }

    @Override
    protected void onResume() {
        super.onResume();
        load();
    }

    private void load() {
        Api.get("quiz/levels?difficulty=" + diff + "&cat=" + cat, new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                error.setVisibility(View.GONE);

                JSONObject cfg = data.optJSONObject("config");
                if (cfg != null) {
                    subtitle.setText(cfg.optInt("questions_per_level") + " questions each · pass at "
                            + cfg.optInt("pass_percent") + "% · "
                            + cfg.optInt("seconds") + "s per question");
                }

                levels.clear();
                JSONArray a = Api.arr(data, "levels");
                for (int i = 0; i < a.length(); i++) {
                    levels.add(QuizLevel.from(a.optJSONObject(i)));
                }
                adapter.notifyDataSetChanged();

                if (levels.isEmpty()) {
                    error.setVisibility(View.VISIBLE);
                    error.setText("There are not enough questions here to make a level yet.");
                }
            }

            @Override public void onError(String code, String message) {
                error.setVisibility(View.VISIBLE);
                error.setText(message);
            }
        });
    }

    private class Adapter extends RecyclerView.Adapter<Adapter.VH> {

        class VH extends RecyclerView.ViewHolder {
            final TextView no, stars, sub;
            VH(View v) {
                super(v);
                no    = v.findViewById(R.id.no);
                stars = v.findViewById(R.id.stars);
                sub   = v.findViewById(R.id.sub);
            }
        }

        @NonNull @Override
        public VH onCreateViewHolder(@NonNull ViewGroup parent, int type) {
            return new VH(LayoutInflater.from(parent.getContext())
                    .inflate(R.layout.item_level, parent, false));
        }

        @Override
        public void onBindViewHolder(@NonNull VH h, int i) {
            QuizLevel l = levels.get(i);

            h.no.setText(String.valueOf(l.level));

            if (l.isPassed()) {
                h.itemView.setBackgroundResource(R.drawable.bg_level_passed);
                h.stars.setText(starString(l.stars));
                h.sub.setText(l.percent + "%");
            } else if (l.isLocked()) {
                h.itemView.setBackgroundResource(R.drawable.bg_level_locked);
                h.stars.setText("🔒");
                h.sub.setText("");
            } else {
                h.itemView.setBackgroundResource(R.drawable.bg_level_open);
                h.stars.setText("▶");
                h.sub.setText(l.size + " Q");
            }

            h.itemView.setAlpha(l.isLocked() ? 0.45f : 1f);
            h.itemView.setOnClickListener(v -> {
                if (l.isLocked()) return;
                Intent it = new Intent(QuizLevelsActivity.this, QuizPlayActivity.class);
                it.putExtra(QuizPlayActivity.EXTRA_DIFF, diff);
                it.putExtra(QuizPlayActivity.EXTRA_LEVEL, l.level);
                it.putExtra(QuizPlayActivity.EXTRA_CAT, cat);
                startActivity(it);
            });
        }

        private String starString(int n) {
            StringBuilder sb = new StringBuilder();
            for (int i = 1; i <= 3; i++) sb.append(i <= n ? "★" : "☆");
            return sb.toString();
        }

        @Override public int getItemCount() { return levels.size(); }
    }
}
