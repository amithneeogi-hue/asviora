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

    private static final int[][] PALETTE = {
            {0xFF8B5CF6, 0xFF3B1F7A},   // violet
            {0xFF22C1C3, 0xFF0B4F6C},   // teal
            {0xFFFF8A3D, 0xFF8A2A12},   // ember
            {0xFFFF4FA3, 0xFF5A1740},   // magenta
            {0xFF4FD1FF, 0xFF1B3FA0},   // sky
            {0xFF9BE15D, 0xFF1E5A3A},   // lime
    };

    /** [start, end] colours for a game slug. */
    public static int[] colors(String slug) {
        String s = slug == null ? "" : slug.toLowerCase(java.util.Locale.US);
        if (s.contains("chess"))  return new int[]{0xFF2ECC8F, 0xFF0B3D33};
        if (s.contains("sudoku")) return new int[]{0xFF4F8BFF, 0xFF1A2B7A};
        if (s.contains("memory")) return new int[]{0xFFFF5FA2, 0xFF6A1FB0};
        if (s.contains("cube"))   return new int[]{0xFFFFA63D, 0xFFB02A2A};
        if (s.contains("quiz"))   return new int[]{0xFF8B5CF6, 0xFF2A1B6B};
        if (s.contains("spin"))   return new int[]{0xFFFFC94D, 0xFF8A3A12};
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
        g.setStroke(Math.max(1, Math.round(d)), Color.argb(56, 255, 255, 255));
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
