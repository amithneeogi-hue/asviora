package com.asviora.games.ui.tabs;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;

import com.asviora.games.R;
import com.asviora.games.net.Api;
import com.asviora.games.net.Session;
import com.asviora.games.view.SpinWheelView;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * The prize wheel.
 *
 * The winning segment is chosen by the server before the wheel is told
 * where to stop, so the animation is decoration over an answer that
 * already exists. How many spins you get is your allowance — set per
 * player by an admin — and the wheel reports what is left after each one.
 */
public class SpinFragment extends Fragment {

    private SpinWheelView wheel;
    private Button btn;
    private TextView note, result;
    private boolean busy;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_spin, c, false);
        wheel  = v.findViewById(R.id.wheel);
        btn    = v.findViewById(R.id.spin);
        note   = v.findViewById(R.id.note);
        result = v.findViewById(R.id.result);

        btn.setOnClickListener(x -> spin());
        return v;
    }

    @Override public void onResume() {
        super.onResume();
        loadStatus();
    }

    private void loadStatus() {
        Api.get("spin/status", new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) return;

                JSONArray segs = Api.arr(data, "segments");
                int[] coins = new int[segs.length()];
                String[] colors = new String[segs.length()];
                for (int i = 0; i < segs.length(); i++) {
                    JSONObject s = segs.optJSONObject(i);
                    coins[i]  = s == null ? 0 : s.optInt("coins");
                    colors[i] = s == null ? "#8FCE3F" : s.optString("color", "#8FCE3F");
                }
                wheel.setSegments(coins, colors);

                JSONObject a = data.optJSONObject("access");
                boolean allowed = a != null && a.optBoolean("allowed");
                btn.setEnabled(allowed && !busy);
                btn.setText(allowed ? "Spin the wheel" : "No spins right now");
                note.setText(allowed
                        ? (a.optInt("remaining") + " spin(s) left today")
                        : (a == null ? "" : a.optString("reason", "")));
            }

            @Override public void onError(String code, String message) {
                if (!isAdded()) return;
                note.setText(message);
                btn.setEnabled(false);
            }
        });
    }

    private void spin() {
        if (busy) return;
        busy = true;
        btn.setEnabled(false);
        result.setText("");

        Api.post("spin/play", new JSONObject(), new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) { busy = false; return; }

                final int coins = data.optInt("coins");
                final int left  = data.optInt("remaining");
                Session.get().setCoins(data.optInt("balance"));

                wheel.spinTo(data.optInt("segment"), () -> {
                    if (!isAdded()) return;
                    busy = false;
                    result.setText(coins > 0 ? "+" + coins + " coins" : "No coins this time");
                    note.setText(left > 0 ? left + " spin(s) left today" : "Come back tomorrow");
                    btn.setEnabled(left > 0);
                });
            }

            @Override public void onError(String code, String message) {
                busy = false;
                if (!isAdded()) return;
                note.setText(message);
                btn.setEnabled(false);
            }
        });
    }
}
