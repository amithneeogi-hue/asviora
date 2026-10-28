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
import com.asviora.games.net.Api;
import com.asviora.games.ui.GameActivity;
import com.asviora.games.ui.GameArt;
import com.asviora.games.ui.Motion;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/**
 * The catalogue ("Game Arena"). Hidden games never arrive here — the server
 * filters them. Each game gets its own neon banner from {@link GameArt}.
 */
public class GamesFragment extends Fragment {

    private final List<Game> items = new ArrayList<>();
    private Adapter adapter;
    private TextView empty, count;
    private RecyclerView rv;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_list, c, false);
        empty = v.findViewById(R.id.empty);
        count = v.findViewById(R.id.count);

        rv = v.findViewById(R.id.list);
        rv.setLayoutManager(new LinearLayoutManager(getContext()));
        adapter = new Adapter();
        rv.setAdapter(adapter);

        ((TextView) v.findViewById(R.id.title)).setText(R.string.game_arena);
        load();
        return v;
    }

    private void load() {
        Api.get("games", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;
                items.clear();
                JSONArray a = Api.arr(data, "games");
                for (int i = 0; i < a.length(); i++) {
                    JSONObject o = a.optJSONObject(i);
                    if (o != null) items.add(Game.from(o));
                }
                adapter.notifyDataSetChanged();
                rv.scheduleLayoutAnimation();

                empty.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
                empty.setText("No games are live right now.");

                count.setVisibility(items.isEmpty() ? View.GONE : View.VISIBLE);
                count.setText(items.size() + (items.size() == 1 ? " GAME" : " GAMES"));
                Motion.pop(count, 100);
            }
            @Override public void onError(String code, String message) {
                if (!isAdded()) return;
                empty.setVisibility(View.VISIBLE);
                empty.setText(message);
            }
        });
    }

    private class Adapter extends RecyclerView.Adapter<Adapter.VH> {

        class VH extends RecyclerView.ViewHolder {
            final View art, play;
            final TextView icon, title, sub, meta, category, featured, plays;
            VH(View v) {
                super(v);
                art      = v.findViewById(R.id.art);
                play     = v.findViewById(R.id.play);
                icon     = v.findViewById(R.id.icon);
                title    = v.findViewById(R.id.title);
                sub      = v.findViewById(R.id.sub);
                meta     = v.findViewById(R.id.meta);
                category = v.findViewById(R.id.category);
                featured = v.findViewById(R.id.featuredTag);
                plays    = v.findViewById(R.id.plays);
            }
        }

        @NonNull @Override
        public VH onCreateViewHolder(@NonNull ViewGroup parent, int type) {
            return new VH(LayoutInflater.from(parent.getContext())
                    .inflate(R.layout.item_game, parent, false));
        }

        @Override
        public void onBindViewHolder(@NonNull VH h, int i) {
            Game g = items.get(i);
            final String emoji = GameArt.emoji(g.slug, g.icon);

            GameArt.paint(h.art, g.slug, 18);
            h.icon.setText(emoji);
            h.title.setText(g.title);
            h.sub.setText(g.tagline);
            h.sub.setVisibility(g.tagline.isEmpty() ? View.GONE : View.VISIBLE);
            h.meta.setText("+" + g.reward + " coins per win");
            h.category.setText(g.category.isEmpty() ? "Arcade" : g.category);
            h.featured.setVisibility(g.featured ? View.VISIBLE : View.GONE);
            h.plays.setText(g.plays > 0 ? "▶ " + Motion.format(g.plays) + " plays" : "");

            // Not g.playUrl any more: that address needs a browser session.
            // GameActivity.open() collects a signed ticket first.
            View.OnClickListener go = v -> {
                Motion.tick(v);
                GameActivity.open(requireContext(), g.slug, g.title, emoji,
                        "+" + g.reward + " coins · "
                                + (g.category.isEmpty() ? "Arcade" : g.category));
            };
            h.itemView.setOnClickListener(go);
            h.play.setOnClickListener(go);
        }

        @Override public int getItemCount() { return items.size(); }
    }
}
