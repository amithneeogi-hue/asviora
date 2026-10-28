package com.asviora.games.model;

import org.json.JSONObject;

/** One difficulty's progress: Easy, Mid or Hard. */
public class Ladder {
    public String key = "easy";
    public String label = "Easy";
    public String blurb = "";
    public int levels;
    public int passed;
    public int percent;
    public boolean unlocked = true;
    public String lockNote = "";
    public int nextLevel;
    public int perLevel;
    public int passMark;
    public int seconds;
    public int reward;
    public int bank;

    public static Ladder from(String key, JSONObject o) {
        Ladder d = new Ladder();
        d.key       = key;
        d.label     = o.optString("label", key);
        d.blurb     = o.optString("blurb", "");
        d.levels    = o.optInt("levels");
        d.passed    = o.optInt("passed");
        d.percent   = o.optInt("percent");
        d.unlocked  = o.optBoolean("unlocked", true);
        d.lockNote  = o.optString("lock_note", "");
        d.nextLevel = o.optInt("next_level");
        d.perLevel  = o.optInt("per_level");
        d.passMark  = o.optInt("pass_mark");
        d.seconds   = o.optInt("seconds");
        d.reward    = o.optInt("reward");
        d.bank      = o.optInt("bank");
        return d;
    }
}
