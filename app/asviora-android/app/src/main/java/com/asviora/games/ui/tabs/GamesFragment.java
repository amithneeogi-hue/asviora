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
import com.asviora.games.ui.Paywall;

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

    @Override
    public void onResume() {
        super.onResume();
        // back from the pay screen: a pass may have just been bought
        if (adapter != null && !items.isEmpty()) load();
    }

    /**
     * v4 — the whole arena (games + quiz + spin), the same tiles the website
     * shows, each with its picture and price. Falls back to the plain games
     * list on a server that is not on v7 yet.
     */
    private void load() {
        load("arena", "items");
    }

    private void load(final String route, final String key) {
        Api.get(route, new Api.Callback() {
            @Override public void onError(String code, String message, JSONObject err) {
                if ("arena".equals(route) && "not_found".equals(code)) {
                    load("games", "games");
                    return;
                }
                onError(code, message);
            }
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;
                items.clear();
                JSONArray a = Api.arr(data, key);
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
            final TextView icon, title, sub, meta, category, featured, plays, priceTag, playTxt;
            final android.widget.ImageView artImg, playIco;
            VH(View v) {
                super(v);
                artImg   = v.findViewById(R.id.artImg);
                priceTag = v.findViewById(R.id.priceTag);
                playTxt  = v.findViewById(R.id.playTxt);
                playIco  = v.findViewById(R.id.playIco);
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
            // v4: real game pictures instead of an emoji
            boolean pic = GameArt.image(h.artImg, g.slug, g.thumbnail);
            h.icon.setVisibility(pic ? View.GONE : View.VISIBLE);
            h.icon.setText(emoji);
            h.title.setText(g.title);
            h.sub.setText(g.tagline);
            h.sub.setVisibility(g.tagline.isEmpty() ? View.GONE : View.VISIBLE);
            h.meta.setText("quiz".equals(g.kind) ? "+" + g.reward + " coins per answer"
                    : "spin".equals(g.kind) ? "Up to +" + g.reward + " coins"
                    : "+" + g.reward + " coins per win");

            // v4: paid plans
            if (g.paid && g.locked) {
                h.priceTag.setVisibility(View.VISIBLE);
                h.priceTag.setText("🔒 " + g.priceLabel());
                h.playTxt.setText(R.string.unlock);
                h.playIco.setImageResource(R.drawable.ic_star);
            } else if (g.paid && g.subTill != null) {
                h.priceTag.setVisibility(View.VISIBLE);
                h.priceTag.setText("✓ Pass till " + shortDate(g.subTill));
                h.playTxt.setText(R.string.play);
                h.playIco.setImageResource(R.drawable.ic_play);
            } else {
                h.priceTag.setVisibility(View.GONE);
                h.playTxt.setText(R.string.play);
                h.playIco.setImageResource(R.drawable.ic_play);
            }
            h.category.setText(g.category.isEmpty() ? "Arcade" : g.category);
            h.featured.setVisibility(g.featured ? View.VISIBLE : View.GONE);
            h.plays.setText(g.plays > 0 ? "▶ " + Motion.format(g.plays) + " plays" : "");

            // Not g.playUrl any more: that address needs a browser session.
            // GameActivity.open() collects a signed ticket first.
            View.OnClickListener go = v -> {
                Motion.tick(v);
                if (g.paid && g.locked) {
                    Paywall.forGame(requireContext(), g.slug, g.title, g.priceLabel());
                    return;
                }
                if ("quiz".equals(g.kind)) { selectTab(R.id.nav_quiz); return; }
                if ("spin".equals(g.kind)) { selectTab(R.id.nav_spin); return; }
                GameActivity.open(requireContext(), g.slug, g.title, emoji,
                        "+" + g.reward + " coins · "
                                + (g.category.isEmpty() ? "Arcade" : g.category));
            };
            h.itemView.setOnClickListener(go);
            h.play.setOnClickListener(go);
        }

        @Override public int getItemCount() { return items.size(); }
    }

    private void selectTab(int navId) {
        View nav = requireActivity().findViewById(R.id.nav);
        if (nav instanceof com.google.android.material.bottomnavigation.BottomNavigationView) {
            ((com.google.android.material.bottomnavigation.BottomNavigationView) nav)
                    .setSelectedItemId(navId);
        }
    }

    /** "2026-11-05 14:16:23" -> "05 Nov" */
    static String shortDate(String dt) {
        try {
            java.util.Date d = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US).parse(dt);
            return new java.text.SimpleDateFormat("dd MMM", java.util.Locale.US).format(d);
        } catch (Exception e) {
            return dt.length() >= 10 ? dt.substring(0, 10) : dt;
        }
    }
}
