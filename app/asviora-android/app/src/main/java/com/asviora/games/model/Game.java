package com.asviora.games.model;

import org.json.JSONObject;

/** One entry in the games catalogue (the "arena" — games, quiz, spin). */
public class Game {
    public int id;
    public String kind = "game";          // v4: game | quiz | spin
    public String slug = "";
    public String title = "";
    public String tagline = "";
    public String category = "";
    public String icon = "";
    public String thumbnail;
    public String playUrl = "";
    public int entry;
    public int reward;
    public boolean featured;
    public int plays;

    // v4 — paid plans
    public boolean paid;
    public boolean locked;
    public String price;                  // "₹49"
    public int days;
    public String plan;
    public String subTill;

    public static Game from(JSONObject o) {
        Game g = new Game();
        g.id       = o.optInt("id");
        g.kind     = o.optString("kind", "game");
        g.slug     = o.optString("slug", "");
        g.title    = o.optString("title", "");
        g.tagline  = o.optString("tagline", "");
        g.category = o.optString("category", "");
        g.icon     = o.optString("icon", "🎮");
        g.thumbnail= o.isNull("thumbnail") ? null : o.optString("thumbnail");
        g.playUrl  = o.optString("play_url", "");
        g.entry    = o.optInt("entry");
        g.reward   = o.optInt("reward");
        g.featured = o.optBoolean("featured");
        g.plays    = o.optInt("plays");
        g.paid     = o.optBoolean("paid");
        g.locked   = o.optBoolean("locked");
        g.price    = o.isNull("price") ? null : o.optString("price", null);
        g.days     = o.optInt("days");
        g.plan     = o.isNull("plan") ? null : o.optString("plan", null);
        g.subTill  = o.isNull("sub_till") ? null : o.optString("sub_till", null);
        return g;
    }

    /** "₹49 / 30 days" */
    public String priceLabel() {
        if (price == null) return "";
        if (days <= 0) return price + " · lifetime";
        if (days % 365 == 0) return price + " / " + (days / 365 == 1 ? "year" : (days / 365) + " years");
        if (days % 30 == 0) return price + " / " + (days / 30 == 1 ? "month" : (days / 30) + " months");
        return price + " / " + days + " days";
    }
}
