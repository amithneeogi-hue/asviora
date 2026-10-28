package com.asviora.games.model;

import org.json.JSONObject;

/** One tile on the level map. */
public class QuizLevel {
    public int level;
    public int size;
    public String state = "locked";   // locked | open | passed
    public int attempts;
    public int best;
    public int bestOf;
    public int percent;
    public int stars;

    public boolean isLocked() { return "locked".equals(state); }
    public boolean isPassed() { return "passed".equals(state); }

    public static QuizLevel from(JSONObject o) {
        QuizLevel l = new QuizLevel();
        l.level    = o.optInt("level");
        l.size     = o.optInt("size");
        l.state    = o.optString("state", "locked");
        l.attempts = o.optInt("attempts");
        l.best     = o.optInt("best");
        l.bestOf   = o.optInt("best_of");
        l.percent  = o.optInt("percent");
        l.stars    = o.optInt("stars");
        return l;
    }
}
