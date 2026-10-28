package com.asviora.games.ui;

import android.content.Context;
import android.widget.Toast;

import com.asviora.games.R;

import org.json.JSONObject;

/**
 * v4 — one place that turns the API's 402 "payment_required" answer into
 * the unlock sheet. Every screen that starts a game, a quiz round or a spin
 * calls handle() from its onError and stops if it returns true.
 */
public final class Paywall {

    private Paywall() { }

    public static boolean handle(Context ctx, String code, String message, JSONObject err) {
        if (!"payment_required".equals(code)) return false;

        String url    = err == null ? "" : err.optString("pay_url", "");
        String reason = err == null ? "" : err.optString("reason", "");
        String price  = err == null ? "" : err.optString("price", "");

        if (url.isEmpty()) {                       // gateway not set up yet
            Toast.makeText(ctx, message, Toast.LENGTH_LONG).show();
            return true;
        }
        boolean reg = "registration".equals(reason);
        ConfirmDialog.show(ctx, R.drawable.ic_star,
                ctx.getString(reg ? R.string.reg_title : R.string.locked_title),
                message,
                ctx.getString(R.string.not_now),
                ctx.getString(R.string.unlock) + (price.isEmpty() ? "" : " " + price),
                () -> PayActivity.open(ctx, url));
        return true;
    }

    /** The sheet for a tile the catalogue already knows is locked. */
    public static void forGame(Context ctx, String slug, String title, String priceLabel) {
        ConfirmDialog.show(ctx, R.drawable.ic_star,
                ctx.getString(R.string.locked_title),
                title + " is a paid game — " + priceLabel
                        + ". Pay once and play as much as you like until it runs out.",
                ctx.getString(R.string.not_now),
                ctx.getString(R.string.unlock),
                () -> PayActivity.openFor(ctx, "game", slug));
    }
}
