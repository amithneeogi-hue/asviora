package com.asviora.games.ui;

import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.view.View;

/**
 * The look of each game: a two-colour neon gradient and a fallback emoji.
 * Known games get hand-picked colours; anything new the admin adds gets a
 * stable pick from the palette, so the catalogue never looks unfinished.
 */
public final class GameArt {

    private GameArt() { }

    // Every colour here comes from the logo: lime, gold, bronze, olive.
    private static final int[][] PALETTE = {
            {0xFFA6D83E, 0xFF22380E},   // lime
            {0xFFE8BE55, 0xFF4A3414},   // gold
            {0xFFC98A3E, 0xFF3A2410},   // bronze
            {0xFF7FA33A, 0xFF2A3212},   // olive
            {0xFFF7DC7C, 0xFF6B5020},   // champagne
            {0xFF5E8F22, 0xFF16240A},   // moss
    };

    /** [start, end] colours for a game slug. */
    public static int[] colors(String slug) {
        String s = slug == null ? "" : slug.toLowerCase(java.util.Locale.US);
        if (s.contains("chess"))  return new int[]{0xFF7FB02E, 0xFF16260B};   // the S
        if (s.contains("sudoku")) return new int[]{0xFFE8BE55, 0xFF45300F};   // the A
        if (s.contains("memory")) return new int[]{0xFFD6F75C, 0xFF3E6A16};   // the crown
        if (s.contains("cube"))   return new int[]{0xFFC98A3E, 0xFF3A2410};   // bronze
        if (s.contains("quiz"))   return new int[]{0xFFA6D83E, 0xFF22380E};
        if (s.contains("spin"))   return new int[]{0xFFF7DC7C, 0xFF6B4A1C};
        return PALETTE[Math.floorMod(s.hashCode(), PALETTE.length)];
    }

    public static String emoji(String slug, String given) {
        if (given != null && !given.trim().isEmpty() && !"null".equals(given)) return given;
        String s = slug == null ? "" : slug.toLowerCase(java.util.Locale.US);
        if (s.contains("chess"))  return "♟️";
        if (s.contains("sudoku")) return "🔢";
        if (s.contains("memory")) return "🧠";
        if (s.contains("cube"))   return "🧊";
        return "🎮";
    }

    /** A rounded diagonal gradient with a glassy rim. */
    public static GradientDrawable banner(View v, String slug, float radiusDp) {
        int[] c = colors(slug);
        float d = v.getResources().getDisplayMetrics().density;
        GradientDrawable g = new GradientDrawable(
                GradientDrawable.Orientation.TL_BR, new int[]{c[0], blend(c[0], c[1]), c[1]});
        g.setCornerRadius(radiusDp * d);
        g.setStroke(Math.max(1, Math.round(d)), Color.argb(110, 232, 190, 85));   // gold rim
        return g;
    }

    /** Paint a view with the banner and clip its children to it. */
    public static void paint(View v, String slug, float radiusDp) {
        v.setBackground(banner(v, slug, radiusDp));
        v.setClipToOutline(true);
    }

    /** A small tinted chip ground in the game's colour. */
    public static GradientDrawable chip(View v, String slug, float radiusDp) {
        int[] c = colors(slug);
        float d = v.getResources().getDisplayMetrics().density;
        GradientDrawable g = new GradientDrawable(
                GradientDrawable.Orientation.TL_BR, new int[]{c[0], c[1]});
        g.setCornerRadius(radiusDp * d);
        return g;
    }

    private static int blend(int a, int b) {
        return Color.rgb((Color.red(a) + Color.red(b)) / 2,
                         (Color.green(a) + Color.green(b)) / 2,
                         (Color.blue(a) + Color.blue(b)) / 2);
    }
}
