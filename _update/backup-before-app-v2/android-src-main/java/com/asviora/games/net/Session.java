package com.asviora.games.net;

import android.content.Context;
import android.content.SharedPreferences;

import com.asviora.games.model.User;

import org.json.JSONObject;

/**
 * The signed-in player, kept between launches.
 *
 * Only the bearer token and a cached copy of the player row are stored.
 * The password never touches the device. Signing out clears both and tells
 * the server to revoke that token, so a lost phone can be cut off from the
 * website's device list.
 */
public final class Session {

    private static final String PREFS = "asviora_session";
    private static final String K_TOKEN = "token";
    private static final String K_USER  = "user";
    private static final String K_EXPIRY = "expires";

    private static Session instance;

    private final SharedPreferences prefs;
    private User user;

    private Session(Context ctx) {
        prefs = ctx.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String raw = prefs.getString(K_USER, null);
        if (raw != null) {
            try { user = User.from(new JSONObject(raw)); } catch (Exception ignored) { }
        }
    }

    public static void init(Context ctx) {
        if (instance == null) instance = new Session(ctx);
    }

    public static Session get() {
        if (instance == null) {
            throw new IllegalStateException("Session.init() must run first — see AsvioraApp.");
        }
        return instance;
    }

    public String token()      { return prefs.getString(K_TOKEN, ""); }
    public boolean signedIn()  { return !token().isEmpty(); }
    public User user()         { return user; }

    public void save(String token, String expires, JSONObject userJson) {
        prefs.edit()
             .putString(K_TOKEN, token)
             .putString(K_EXPIRY, expires == null ? "" : expires)
             .putString(K_USER, userJson == null ? "" : userJson.toString())
             .apply();
        if (userJson != null) user = User.from(userJson);
    }

    /** Refresh the cached player row after coins or XP change. */
    public void updateUser(JSONObject userJson) {
        if (userJson == null) return;
        prefs.edit().putString(K_USER, userJson.toString()).apply();
        user = User.from(userJson);
    }

    public void setCoins(int coins) {
        if (user != null) {
            user.coins = coins;
            try {
                JSONObject o = new JSONObject(prefs.getString(K_USER, "{}"));
                o.put("coins", coins);
                prefs.edit().putString(K_USER, o.toString()).apply();
            } catch (Exception ignored) { }
        }
    }

    /**
     * Sign out. The token is sent along with the revoke call rather than
     * read from storage inside it — the request runs on a background
     * thread, and by the time it got there the preferences would already
     * be empty, so the server would never hear about the logout.
     */
    public void clear() {
        String tok = token();
        prefs.edit().clear().apply();
        user = null;

        if (!tok.isEmpty()) {
            try {
                JSONObject o = new JSONObject();
                o.put("token", tok);
                Api.postQuiet("auth/logout", o);
            } catch (Exception ignored) { }
        }
    }
}
