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

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** The catalogue. Hidden games never arrive here — the server filters them. */
public class GamesFragment extends Fragment {

    private final List<Game> items = new ArrayList<>();
    private Adapter adapter;
    private TextView empty;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_list, c, false);
        empty = v.findViewById(R.id.empty);

        RecyclerView rv = v.findViewById(R.id.list);
        rv.setLayoutManager(new LinearLayoutManager(getContext()));
        adapter = new Adapter();
        rv.setAdapter(adapter);

        ((TextView) v.findViewById(R.id.title)).setText(R.string.games);
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
                    items.add(Game.from(a.optJSONObject(i)));
                }
                adapter.notifyDataSetChanged();
                empty.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
                empty.setText("No games are live right now.");
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
            final TextView icon, title, sub, meta;
            VH(View v) {
                super(v);
                icon  = v.findViewById(R.id.icon);
                title = v.findViewById(R.id.title);
                sub   = v.findViewById(R.id.sub);
                meta  = v.findViewById(R.id.meta);
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
            h.icon.setText(g.icon);
            h.title.setText(g.title);
            h.sub.setText(g.tagline);
            h.meta.setText(g.category + " · +" + g.reward + " coins");
            // Not g.playUrl any more: that address needs a browser session.
            // GameActivity.open() collects a signed ticket first.
            h.itemView.setOnClickListener(v -> GameActivity.open(requireContext(), g.slug, g.title));
        }

        @Override public int getItemCount() { return items.size(); }
    }
}
