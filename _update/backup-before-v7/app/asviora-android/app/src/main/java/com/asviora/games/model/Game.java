package com.asviora.games.model;

import org.json.JSONObject;

/** One entry in the games catalogue. */
public class Game {
    public int id;
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

    public static Game from(JSONObject o) {
        Game g = new Game();
        g.id       = o.optInt("id");
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
        return g;
    }
}
