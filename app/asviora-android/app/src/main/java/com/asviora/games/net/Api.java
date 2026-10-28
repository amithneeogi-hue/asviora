package com.asviora.games.net;

import android.os.Handler;
import android.os.Looper;

import com.asviora.games.BuildConfig;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * The whole network layer.
 *
 * Deliberately small: HttpURLConnection and org.json are both part of the
 * platform, so the app carries no HTTP or JSON library at all. Requests run
 * on a small thread pool and callbacks are posted back to the main thread,
 * which is the only threading rule the rest of the app has to know.
 *
 * Every response from api/v1 has the same envelope —
 *   { "ok": true,  "data": {...} }
 *   { "ok": false, "error": { "code": "...", "message": "..." } }
 * — so this class can unwrap it once and hand the caller either the data
 * object or a message worth showing a human.
 */
public final class Api {

    public interface Callback {
        void onSuccess(JSONObject data);
        void onError(String code, String message);

        /**
         * The same failure, with the rest of the envelope.
         *
         * Some errors carry more than a sentence — the wheel, for one, sends
         * back how many seconds are left on the cooldown. Callers that care
         * override this; everyone else keeps the two-argument version and
         * never notices.
         */
        default void onError(String code, String message, JSONObject error) {
            onError(code, message);
        }
    }

    /** Convenience for callers that only care that it worked. */
    public abstract static class Simple implements Callback {
        @Override public void onError(String code, String message) { /* override to handle */ }
    }

    private static final ExecutorService POOL = Executors.newFixedThreadPool(3);
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final int TIMEOUT_MS = 15000;

    private Api() { }

    public static String base() {
        return BuildConfig.API_BASE;
    }

    // ---------------------------------------------------------------
    //  Public entry points
    // ---------------------------------------------------------------

    public static void get(String route, Callback cb) {
        send("GET", route, null, cb);
    }

    public static void post(String route, JSONObject body, Callback cb) {
        send("POST", route, body, cb);
    }

    /** Fire and forget — used for logout, where the answer does not matter. */
    public static void postQuiet(String route, JSONObject body) {
        send("POST", route, body, new Simple() {
            @Override public void onSuccess(JSONObject data) { }
        });
    }

    // ---------------------------------------------------------------
    //  Worker
    // ---------------------------------------------------------------
    /**
     * Build the request URL.
     *
     * The API answers both /api/v1/quiz/levels and
     * /api/v1/index.php?r=quiz/levels. The app always uses the second
     * form: it needs no URL rewriting, so the same build works on a
     * shared host, on XAMPP, and in a subfolder like /gamesv/.
     */
    static String urlFor(String route) {
        String path = route, query = "";
        int q = route.indexOf('?');
        if (q >= 0) {
            path  = route.substring(0, q);
            query = route.substring(q + 1);
        }
        String u = base() + "index.php?r=" + path;
        return query.isEmpty() ? u : u + "&" + query;
    }

    private static void send(final String method, final String route,
                             final JSONObject body, final Callback cb) {
        POOL.execute(() -> {
            HttpURLConnection conn = null;
            try {
                URL url = new URL(urlFor(route));
                conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod(method);
                conn.setConnectTimeout(TIMEOUT_MS);
                conn.setReadTimeout(TIMEOUT_MS);
                conn.setRequestProperty("Accept", "application/json");
                conn.setRequestProperty("X-App-Version", String.valueOf(BuildConfig.VERSION_CODE));

                String token = Session.get().token();
                if (token != null && !token.isEmpty()) {
                    conn.setRequestProperty("Authorization", "Bearer " + token);
                }

                if (body != null) {
                    conn.setDoOutput(true);
                    conn.setRequestProperty("Content-Type", "application/json; charset=utf-8");
                    byte[] out = body.toString().getBytes(StandardCharsets.UTF_8);
                    conn.setFixedLengthStreamingMode(out.length);
                    try (OutputStream os = conn.getOutputStream()) {
                        os.write(out);
                    }
                }

                int status = conn.getResponseCode();
                InputStream in = (status >= 200 && status < 300)
                        ? conn.getInputStream() : conn.getErrorStream();

                String raw = readAll(in);
                JSONObject json = raw.isEmpty() ? new JSONObject() : new JSONObject(raw);

                if (json.optBoolean("ok", false)) {
                    final JSONObject data = json.optJSONObject("data") != null
                            ? json.optJSONObject("data") : new JSONObject();
                    MAIN.post(() -> cb.onSuccess(data));
                } else {
                    JSONObject err = json.optJSONObject("error");
                    final String code = err != null ? err.optString("code", "error") : "http_" + status;
                    final String msg  = err != null
                            ? err.optString("message", "Something went wrong.")
                            : "The server returned " + status + ".";
                    final JSONObject envelope = err != null ? err : new JSONObject();
                    MAIN.post(() -> cb.onError(code, msg, envelope));
                }

            } catch (Exception e) {
                final String msg = e.getMessage() == null
                        ? "No connection." : "Network problem: " + e.getMessage();
                MAIN.post(() -> cb.onError("network", msg, new JSONObject()));
            } finally {
                if (conn != null) conn.disconnect();
            }
        });
    }

    private static String readAll(InputStream in) throws Exception {
        if (in == null) return "";
        StringBuilder sb = new StringBuilder();
        try (BufferedReader r = new BufferedReader(
                new InputStreamReader(in, StandardCharsets.UTF_8))) {
            String line;
            while ((line = r.readLine()) != null) sb.append(line);
        }
        return sb.toString();
    }

    // ---------------------------------------------------------------
    //  Tiny JSON helpers, so callers stay readable
    // ---------------------------------------------------------------

    public static JSONObject body(Object... pairs) {
        JSONObject o = new JSONObject();
        try {
            for (int i = 0; i + 1 < pairs.length; i += 2) {
                o.put(String.valueOf(pairs[i]), pairs[i + 1]);
            }
        } catch (Exception ignored) { }
        return o;
    }

    public static JSONArray arr(JSONObject o, String key) {
        JSONArray a = o.optJSONArray(key);
        return a == null ? new JSONArray() : a;
    }
}
