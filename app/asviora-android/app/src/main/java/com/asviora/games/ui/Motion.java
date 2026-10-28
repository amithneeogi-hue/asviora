package com.asviora.games.ui;

import android.animation.Animator;
import android.animation.AnimatorSet;
import android.animation.AnimatorListenerAdapter;
import android.animation.ObjectAnimator;
import android.animation.ValueAnimator;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.DecelerateInterpolator;
import android.view.animation.LinearInterpolator;
import android.view.animation.OvershootInterpolator;
import android.widget.ProgressBar;
import android.widget.TextView;

import java.util.Locale;

/**
 * Small, shared motion vocabulary so every screen moves the same way.
 *
 * Nothing here is required for the app to work: every call is safe on a
 * view that is already gone, and the helpers respect the system
 * "remove animations" setting by simply finishing instantly (the framework
 * scales animator durations to zero in that case).
 */
public final class Motion {

    private Motion() { }

    private static final DecelerateInterpolator EASE = new DecelerateInterpolator(2f);

    /** Children of a container rise and fade in one after another. */
    public static void stagger(ViewGroup parent) {
        if (parent == null) return;
        int n = parent.getChildCount();
        float dy = 28f * parent.getResources().getDisplayMetrics().density;
        for (int i = 0; i < n; i++) {
            View c = parent.getChildAt(i);
            if (c.getVisibility() != View.VISIBLE) continue;
            c.setAlpha(0f);
            c.setTranslationY(dy);
            c.animate()
             .alpha(1f).translationY(0f)
             .setStartDelay(60L + i * 55L)
             .setDuration(460)
             .setInterpolator(EASE)
             .start();
        }
    }

    /** One view pops in with a little overshoot. */
    public static void pop(View v, long delay) {
        if (v == null) return;
        v.setAlpha(0f);
        v.setScaleX(0.7f);
        v.setScaleY(0.7f);
        v.animate()
         .alpha(1f).scaleX(1f).scaleY(1f)
         .setStartDelay(delay)
         .setDuration(520)
         .setInterpolator(new OvershootInterpolator(1.6f))
         .start();
    }

    /** Gentle breathing scale, forever (until the view detaches or {@link #still}). */
    public static void breathe(View v, float amount, long period) {
        if (v == null) return;
        still(v);
        ObjectAnimator sx = ObjectAnimator.ofFloat(v, View.SCALE_X, 1f, 1f + amount);
        ObjectAnimator sy = ObjectAnimator.ofFloat(v, View.SCALE_Y, 1f, 1f + amount);
        for (ObjectAnimator a : new ObjectAnimator[]{sx, sy}) {
            a.setDuration(period);
            a.setRepeatCount(ValueAnimator.INFINITE);
            a.setRepeatMode(ValueAnimator.REVERSE);
            a.setInterpolator(new android.view.animation.AccelerateDecelerateInterpolator());
        }
        AnimatorSet set = new AnimatorSet();
        set.playTogether(sx, sy);
        set.start();
        v.setTag(com.asviora.games.R.id.motion_loop, set);
        stopOnDetach(v, set);
    }

    /** Stop a loop started by {@link #breathe} and settle the view. */
    public static void still(View v) {
        if (v == null) return;
        Object t = v.getTag(com.asviora.games.R.id.motion_loop);
        if (t instanceof Animator) ((Animator) t).cancel();
        v.setTag(com.asviora.games.R.id.motion_loop, null);
        v.setScaleX(1f);
        v.setScaleY(1f);
    }

    /** Continuous rotation, forever (until the view detaches). */
    public static void spin(View v, long period) {
        if (v == null) return;
        ObjectAnimator r = ObjectAnimator.ofFloat(v, View.ROTATION, 0f, 360f);
        r.setDuration(period);
        r.setRepeatCount(ValueAnimator.INFINITE);
        r.setInterpolator(new LinearInterpolator());
        r.start();
        stopOnDetach(v, r);
    }

    /** Soft glow pulse on alpha. */
    public static void glow(View v, float min, float max, long period) {
        if (v == null) return;
        ObjectAnimator a = ObjectAnimator.ofFloat(v, View.ALPHA, min, max);
        a.setDuration(period);
        a.setRepeatCount(ValueAnimator.INFINITE);
        a.setRepeatMode(ValueAnimator.REVERSE);
        a.start();
        stopOnDetach(v, a);
    }

    private static void stopOnDetach(View v, final Animator... anims) {
        v.addOnAttachStateChangeListener(new View.OnAttachStateChangeListener() {
            @Override public void onViewAttachedToWindow(View view) { }
            @Override public void onViewDetachedFromWindow(View view) {
                for (Animator a : anims) a.cancel();
                view.removeOnAttachStateChangeListener(this);
            }
        });
    }

    /** Count a number up from what the view shows now to {@code to}. */
    public static void countUp(final TextView tv, final int to) {
        if (tv == null) return;
        int from = 0;
        try {
            from = Integer.parseInt(tv.getText().toString().replaceAll("[^0-9-]", ""));
        } catch (NumberFormatException ignored) { }
        if (from == to) {
            tv.setText(format(to));
            return;
        }
        ValueAnimator a = ValueAnimator.ofInt(from, to);
        a.setDuration(Math.min(1400, 500 + Math.abs(to - from) / 4L));
        a.setInterpolator(EASE);
        a.addUpdateListener(x -> tv.setText(format((int) x.getAnimatedValue())));
        a.start();
        stopOnDetach(tv, a);
    }

    public static String format(int n) {
        return String.format(Locale.US, "%,d", n);
    }

    /** Animate a progress bar from 0 to its value. */
    public static void fill(ProgressBar bar, int value) {
        if (bar == null) return;
        ObjectAnimator a = ObjectAnimator.ofInt(bar, "progress", bar.getProgress(), value);
        a.setDuration(900);
        a.setInterpolator(EASE);
        a.start();
    }

    /** A quick scale bump, for "yes". */
    public static void bump(View v) {
        if (v == null) return;
        ObjectAnimator sx = ObjectAnimator.ofFloat(v, View.SCALE_X, 1f, 1.04f, 1f);
        ObjectAnimator sy = ObjectAnimator.ofFloat(v, View.SCALE_Y, 1f, 1.04f, 1f);
        AnimatorSet set = new AnimatorSet();
        set.playTogether(sx, sy);
        set.setDuration(360);
        set.start();
    }

    /** A quick shake, for "no". */
    public static void shake(View v) {
        if (v == null) return;
        float d = 8f * v.getResources().getDisplayMetrics().density;
        ObjectAnimator a = ObjectAnimator.ofFloat(v, View.TRANSLATION_X, 0, -d, d, -d * .6f, d * .6f, 0);
        a.setDuration(380);
        a.start();
    }

    /** Fade a view out and remove it from layout at the end. */
    public static void fadeOut(final View v, long duration) {
        if (v == null) return;
        v.animate().alpha(0f).scaleX(1.04f).scaleY(1.04f)
         .setDuration(duration)
         .setInterpolator(EASE)
         .setListener(new AnimatorListenerAdapter() {
             @Override public void onAnimationEnd(Animator a) {
                 v.setVisibility(View.GONE);
                 v.animate().setListener(null);
             }
         }).start();
    }

    /** Light haptic tick, where the device has one. */
    public static void tick(View v) {
        if (v != null) v.performHapticFeedback(android.view.HapticFeedbackConstants.KEYBOARD_TAP);
    }
}
