package com.asviora.games.ui;

import android.app.Dialog;
import android.content.Context;
import android.view.View;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.annotation.DrawableRes;
import androidx.annotation.Nullable;

import com.asviora.games.R;

/**
 * The app's one confirm sheet (leave a game, quit a level, restart).
 * The safe choice is the big button; the destructive one is the red ghost.
 */
public final class ConfirmDialog {

    private ConfirmDialog() { }

    public static Dialog show(Context ctx, @DrawableRes int icon,
                              CharSequence title, CharSequence body,
                              CharSequence stay, CharSequence leave,
                              @Nullable final Runnable onLeave) {
        final Dialog d = new Dialog(ctx, R.style.Dialog_Asviora);
        d.setContentView(R.layout.dialog_confirm);

        ((ImageView) d.findViewById(R.id.dIcon)).setImageResource(icon);
        ((TextView) d.findViewById(R.id.dTitle)).setText(title);
        ((TextView) d.findViewById(R.id.dBody)).setText(body);

        Button bStay  = d.findViewById(R.id.dStay);
        Button bLeave = d.findViewById(R.id.dLeave);
        bStay.setText(stay);
        bLeave.setText(leave);

        bStay.setOnClickListener(v -> d.dismiss());
        bLeave.setOnClickListener(v -> {
            d.dismiss();
            if (onLeave != null) onLeave.run();
        });

        if (d.getWindow() != null) {
            d.getWindow().setLayout(
                    android.view.ViewGroup.LayoutParams.MATCH_PARENT,
                    android.view.ViewGroup.LayoutParams.WRAP_CONTENT);
            float dp = ctx.getResources().getDisplayMetrics().density;
            View decor = d.getWindow().getDecorView();
            decor.setPadding(Math.round(24 * dp), 0, Math.round(24 * dp), 0);
        }
        d.show();
        return d;
    }
}
