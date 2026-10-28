package com.asviora.games.model;

import org.json.JSONObject;

/** One player, as api/v1 describes them. */
public class User {
    public int id;
    public String name = "";
    public String username = "";
    public String email = "";
    public String avatar = "";
    public String bio = "";
    public String referral = "";
    public int coins;
    public int xp;
    public int level = 1;
    public boolean regFeeDue;   // v4 — one-time registration fee still to pay
    public String regFee;       // "₹99"

    public static User from(JSONObject o) {
        User u = new User();
        if (o == null) return u;
        u.id       = o.optInt("id");
        u.name     = o.optString("name", "");
        u.username = o.optString("username", "");
        u.email    = o.optString("email", "");
        u.avatar   = o.optString("avatar", "");
        u.bio      = o.optString("bio", "");
        u.referral = o.optString("referral", "");
        u.coins    = o.optInt("coins");
        u.xp       = o.optInt("xp");
        u.level    = o.optInt("level", 1);
        u.regFeeDue = o.optBoolean("reg_fee_due", false);
        u.regFee    = o.isNull("reg_fee") ? null : o.optString("reg_fee", null);
        return u;
    }

    /** 250 XP per level, matching the website. */
    public int xpIntoLevel() { return xp % 250; }
    public int xpForLevel()  { return 250; }
}
