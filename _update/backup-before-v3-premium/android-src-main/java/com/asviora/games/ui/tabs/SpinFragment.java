package com.asviora.games.ui.tabs;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
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
 * already exists.
 *
 * Since v6 the allowance is more than "how many spins today". An admin can
 * also set a wait between spins and a rolling weekly or monthly cap, and
 * can give one player a different rule from everyone else. All of that is
 * decided inside spin_status() on the server; this screen only reports it,
 * and counts the wait down so the player is not left tapping a dead button.
 */
public class SpinFragment extends Fragment {

    private SpinWheelView wheel;
    private Button btn;
    private TextView note, limits, adminNote, result;
    private boolean busy;

    /** Seconds left on the cooldown, and the ticker that spends them. */
    private final Handler tick = new Handler(Looper.getMainLooper());
    private int wait;
    private Runnable ticker;

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup c,
                             @Nullable Bundle saved) {
        View v = inf.inflate(R.layout.fragment_spin, c, false);
        wheel     = v.findViewById(R.id.wheel);
        btn       = v.findViewById(R.id.spin);
        note      = v.findViewById(R.id.note);
        limits    = v.findViewById(R.id.limits);
        adminNote = v.findViewById(R.id.adminNote);
        result    = v.findViewById(R.id.result);

        btn.setOnClickListener(x -> spin());
        return v;
    }

    @Override public void onResume() {
        super.onResume();
        loadStatus();
    }

    @Override public void onPause() {
        super.onPause();
        stopTicker();
    }

    // ---------------------------------------------------------------
    //  Status
    // ---------------------------------------------------------------

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

                paint(data.optJSONObject("access"), data.optString("note", ""));
            }

            @Override public void onError(String code, String message) {
                if (!isAdded()) return;
                note.setText(message);
                btn.setEnabled(false);
            }
        });
    }

    /** One place that turns an access row into what the screen says. */
    private void paint(@Nullable JSONObject a, String siteNote) {
        stopTicker();

        if (a == null) {
            btn.setEnabled(false);
            return;
        }

        boolean allowed = a.optBoolean("allowed");
        wait = Math.max(0, a.optInt("wait"));

        // The admin's message to players, if there is one.
        String msg = a.optString("note", "");
        if (msg.isEmpty()) msg = siteNote;
        adminNote.setText(msg);
        adminNote.setVisibility(msg.isEmpty() ? View.GONE : View.VISIBLE);

        limits.setText(limitLine(a));
        limits.setVisibility(limits.getText().length() == 0 ? View.GONE : View.VISIBLE);

        if (allowed && wait > 0) {
            // Allowed on paper, but the cooldown has not run out yet.
            note.setText(a.optInt("remaining") + " spin(s) left today");
            startTicker();
            return;
        }

        btn.setEnabled(allowed && !busy);
        btn.setText(allowed ? "Spin the wheel" : "No spins right now");
        note.setText(allowed
                ? (a.optInt("remaining") + " spin(s) left today")
                : a.optString("reason", ""));
    }

    /** "3 of 5 this week · 12 of 30 this month" — only the caps that exist. */
    private String limitLine(JSONObject a) {
        StringBuilder sb = new StringBuilder();

        int wCap = a.optInt("weekly_cap");
        if (wCap > 0) {
            sb.append(a.optInt("weekly_used")).append(" of ").append(wCap).append(" this week");
        }
        int mCap = a.optInt("monthly_cap");
        if (mCap > 0) {
            if (sb.length() > 0) sb.append("  ·  ");
            sb.append(a.optInt("monthly_used")).append(" of ").append(mCap).append(" this month");
        }
        int cool = a.optInt("cooldown");
        if (cool > 0) {
            if (sb.length() > 0) sb.append("  ·  ");
            sb.append(cool).append(" min between spins");
        }
        return sb.toString();
    }

    // ---------------------------------------------------------------
    //  Cooldown countdown
    // ---------------------------------------------------------------

    /** "1h 05m" / "4m 20s" / "9s" — the same shapes the website uses. */
    static String waitText(int seconds) {
        if (seconds >= 3600) {
            return String.format(java.util.Locale.US, "%dh %02dm",
                    seconds / 3600, (seconds % 3600) / 60);
        }
        if (seconds >= 60) {
            return String.format(java.util.Locale.US, "%dm %02ds",
                    seconds / 60, seconds % 60);
        }
        return seconds + "s";
    }

    private void startTicker() {
        btn.setEnabled(false);
        btn.setText("Next spin in " + waitText(wait));

        ticker = new Runnable() {
            @Override public void run() {
                if (!isAdded()) return;
                wait--;
                if (wait <= 0) {
                    // Do not just unlock the button — ask the server, because
                    // a weekly or monthly cap may be what stops the next spin.
                    stopTicker();
                    loadStatus();
                    return;
                }
                btn.setText("Next spin in " + waitText(wait));
                tick.postDelayed(this, 1000);
            }
        };
        tick.postDelayed(ticker, 1000);
    }

    private void stopTicker() {
        if (ticker != null) {
            tick.removeCallbacks(ticker);
            ticker = null;
        }
    }

    // ---------------------------------------------------------------
    //  Playing
    // ---------------------------------------------------------------

    private void spin() {
        if (busy) return;
        busy = true;
        stopTicker();
        btn.setEnabled(false);
        result.setText("");

        Api.post("spin/play", new JSONObject(), new Api.Callback() {
            @Override public void onSuccess(JSONObject data) {
                if (!isAdded()) { busy = false; return; }

                final int coins = data.optInt("coins");
                Session.get().setCoins(data.optInt("balance"));

                wheel.spinTo(data.optInt("segment"), () -> {
                    if (!isAdded()) return;
                    busy = false;
                    result.setText(coins > 0 ? "+" + coins + " coins" : "No coins this time");
                    // The server has already re-read the allowance for us,
                    // cooldown and caps included.
                    paint(data.optJSONObject("access"), "");
                });
            }

            @Override public void onError(String code, String message) {
                onError(code, message, new JSONObject());
            }

            @Override public void onError(String code, String message, JSONObject error) {
                busy = false;
                if (!isAdded()) return;
                note.setText(message);
                btn.setEnabled(false);

                // A refusal from the wheel says how long the wait is, so the
                // button can count down instead of just going dead.
                wait = Math.max(0, error.optInt("wait"));
                if (wait > 0) startTicker();
                else btn.setText("No spins right now");
            }
        });
    }
}
