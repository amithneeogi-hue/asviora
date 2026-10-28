package com.asviora.games.view;

import android.animation.ValueAnimator;
import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RectF;
import android.util.AttributeSet;
import android.view.View;
import android.view.animation.DecelerateInterpolator;

import androidx.annotation.Nullable;

/**
 * The prize wheel, drawn rather than shipped as images so it always
 * matches whatever segments the admin has configured.
 *
 * Segment i is centred on the angle i * (360 / n) measured clockwise from
 * the top, which is where the pointer sits. To land on segment i the wheel
 * therefore has to end at -i * (360 / n) plus a few whole turns for drama.
 */
public class SpinWheelView extends View {

    public interface OnLanded { void done(); }

    private final Paint slice = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint edge  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint text  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint hub   = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint arrow = new Paint(Paint.ANTI_ALIAS_FLAG);

    private final RectF box = new RectF();
    private final Path pointer = new Path();

    private int[] coins = new int[0];
    private int[] colors = new int[0];
    private float rotation;
    private ValueAnimator anim;

    public SpinWheelView(Context c) { this(c, null); }

    public SpinWheelView(Context c, @Nullable AttributeSet a) {
        super(c, a);

        edge.setStyle(Paint.Style.STROKE);
        edge.setStrokeWidth(2f);
        edge.setColor(Color.parseColor("#0B0F16"));

        text.setColor(Color.WHITE);
        text.setTextAlign(Paint.Align.CENTER);
        text.setFakeBoldText(true);

        hub.setColor(Color.parseColor("#151B23"));
        arrow.setColor(Color.parseColor("#E5B84B"));
    }

    public void setSegments(int[] coinValues, String[] hexColors) {
        coins = coinValues == null ? new int[0] : coinValues;
        colors = new int[coins.length];
        for (int i = 0; i < coins.length; i++) {
            int col;
            try {
                col = Color.parseColor(hexColors[i]);
            } catch (Exception e) {
                col = (i % 2 == 0) ? Color.parseColor("#8FCE3F") : Color.parseColor("#4A7616");
            }
            colors[i] = col;
        }
        invalidate();
    }

    /** Spin to a segment the server has already chosen. */
    public void spinTo(final int segment, final OnLanded then) {
        if (coins.length == 0) { if (then != null) then.done(); return; }
        if (anim != null) anim.cancel();

        float step   = 360f / coins.length;
        float target = 360f * 6 - (segment * step);       // six turns, then land

        // always travel forwards from wherever the wheel currently sits
        float from = rotation % 360f;
        float to   = from + (target - from % 360f) + 360f * 6;

        anim = ValueAnimator.ofFloat(from, to);
        anim.setDuration(4200);
        anim.setInterpolator(new DecelerateInterpolator(1.9f));
        anim.addUpdateListener(a -> {
            rotation = (float) a.getAnimatedValue();
            invalidate();
        });
        anim.addListener(new android.animation.AnimatorListenerAdapter() {
            @Override public void onAnimationEnd(android.animation.Animator a) {
                if (then != null) then.done();
            }
        });
        anim.start();
    }

    @Override
    protected void onMeasure(int wSpec, int hSpec) {
        int w = MeasureSpec.getSize(wSpec);
        setMeasuredDimension(w, w);              // always square
    }

    @Override
    protected void onDraw(Canvas c) {
        super.onDraw(c);

        int n = coins.length;
        if (n == 0) return;

        float size = Math.min(getWidth(), getHeight());
        float pad  = size * 0.06f;
        box.set(pad, pad, size - pad, size - pad);

        float cx = size / 2f, cy = size / 2f, r = (size - 2 * pad) / 2f;
        float step = 360f / n;

        text.setTextSize(Math.max(11f, r * 0.13f));

        c.save();
        c.rotate(rotation, cx, cy);

        for (int i = 0; i < n; i++) {
            // -90 puts segment 0's centre at the top, where the pointer is
            float start = -90f - step / 2f + i * step;

            slice.setColor(colors[i]);
            c.drawArc(box, start, step, true, slice);
            c.drawArc(box, start, step, true, edge);

            // the label, rotated to sit along its own slice
            c.save();
            c.rotate(start + step / 2f + 90f, cx, cy);
            c.drawText(String.valueOf(coins[i]), cx, cy - r * 0.66f, text);
            c.restore();
        }

        c.restore();

        // hub
        c.drawCircle(cx, cy, r * 0.22f, hub);
        edge.setColor(Color.parseColor("#29313D"));
        c.drawCircle(cx, cy, r * 0.22f, edge);
        edge.setColor(Color.parseColor("#0B0F16"));

        // pointer at twelve o'clock
        pointer.reset();
        pointer.moveTo(cx, pad + r * 0.10f);
        pointer.lineTo(cx - size * 0.045f, pad - size * 0.03f);
        pointer.lineTo(cx + size * 0.045f, pad - size * 0.03f);
        pointer.close();
        c.drawPath(pointer, arrow);
    }

    @Override
    protected void onDetachedFromWindow() {
        if (anim != null) anim.cancel();
        super.onDetachedFromWindow();
    }
}
