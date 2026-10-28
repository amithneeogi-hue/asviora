package com.asviora.games.view;

import android.animation.ValueAnimator;
import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RadialGradient;
import android.graphics.RectF;
import android.graphics.Shader;
import android.graphics.SweepGradient;
import android.util.AttributeSet;
import android.view.HapticFeedbackConstants;
import android.view.View;
import android.view.animation.DecelerateInterpolator;

import androidx.annotation.Nullable;

/**
 * The prize wheel, drawn rather than shipped as images so it always
 * matches whatever segments the admin has configured.
 *
 * Premium look (v3): a gold rim studded with marquee bulbs that chase while
 * the wheel turns, glossy segments, a jewel hub and a pointer that never
 * clips. A light haptic tick fires as each segment passes the pointer.
 *
 * Segment i is centred on the angle i * (360 / n) measured clockwise from
 * the top, which is where the pointer sits. To land on segment i the wheel
 * therefore has to end at -i * (360 / n) plus a few whole turns for drama.
 */
public class SpinWheelView extends View {

    public interface OnLanded { void done(); }

    private final Paint slice  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint gloss  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint sep    = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint rim    = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint rimIn  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint bulb   = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint text   = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint small  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint hub    = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint hubIn  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint hubTxt = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint arrow  = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint arrowShade = new Paint(Paint.ANTI_ALIAS_FLAG);

    private final RectF box = new RectF();
    private final Path pointer = new Path();

    private int[] coins = new int[0];
    private int[] colors = new int[0];
    private float rotation;
    private ValueAnimator anim;
    private ValueAnimator lights;
    private int phase;
    private int lastTick = -1;
    private float builtFor = -1;

    public SpinWheelView(Context c) { this(c, null); }

    public SpinWheelView(Context c, @Nullable AttributeSet a) {
        super(c, a);

        sep.setStyle(Paint.Style.STROKE);
        sep.setColor(Color.argb(70, 255, 255, 255));

        rim.setStyle(Paint.Style.STROKE);
        rimIn.setStyle(Paint.Style.STROKE);
        rimIn.setColor(Color.parseColor("#0B0F16"));

        text.setColor(Color.WHITE);
        text.setTextAlign(Paint.Align.CENTER);
        text.setFakeBoldText(true);
        text.setShadowLayer(4f, 0f, 1.5f, Color.argb(150, 0, 0, 0));

        small.setTextAlign(Paint.Align.CENTER);
        small.setFakeBoldText(true);
        small.setLetterSpacing(0.12f);

        hubTxt.setColor(Color.parseColor("#1A1405"));
        hubTxt.setTextAlign(Paint.Align.CENTER);
        hubTxt.setFakeBoldText(true);
        hubTxt.setLetterSpacing(0.08f);

        arrow.setColor(Color.parseColor("#FFE08A"));
        arrowShade.setColor(Color.argb(120, 0, 0, 0));
    }

    public void setSegments(int[] coinValues, String[] hexColors) {
        coins = coinValues == null ? new int[0] : coinValues;
        colors = new int[coins.length];
        for (int i = 0; i < coins.length; i++) {
            int col;
            try {
                col = Color.parseColor(hexColors[i]);
            } catch (Exception e) {
                col = (i % 2 == 0) ? Color.parseColor("#A6D83E") : Color.parseColor("#E8BE55");
            }
            colors[i] = col;
        }
        invalidate();
    }

    /** Spin to a segment the server has already chosen. */
    public void spinTo(final int segment, final OnLanded then) {
        if (coins.length == 0) { if (then != null) then.done(); return; }
        if (anim != null) anim.cancel();

        final float step   = 360f / coins.length;
        float target = 360f * 6 - (segment * step);       // six turns, then land

        // always travel forwards from wherever the wheel currently sits
        float from = rotation % 360f;
        float to   = from + (target - from % 360f) + 360f * 6;

        setLightsFast(true);
        anim = ValueAnimator.ofFloat(from, to);
        anim.setDuration(4600);
        anim.setInterpolator(new DecelerateInterpolator(2.1f));
        anim.addUpdateListener(a -> {
            rotation = (float) a.getAnimatedValue();
            // a tick each time a segment boundary passes the pointer
            int idx = (int) Math.floor((rotation + step / 2f) / step);
            if (idx != lastTick) {
                lastTick = idx;
                performHapticFeedback(HapticFeedbackConstants.CLOCK_TICK);
            }
            invalidate();
        });
        anim.addListener(new android.animation.AnimatorListenerAdapter() {
            @Override public void onAnimationEnd(android.animation.Animator a) {
                if (isAttachedToWindow()) {
                    setLightsFast(false);
                    performHapticFeedback(HapticFeedbackConstants.LONG_PRESS);
                }
                if (then != null) then.done();
            }
        });
        anim.start();
    }

    // ---------------------------------------------------------------
    //  Marquee lights
    // ---------------------------------------------------------------

    private void setLightsFast(boolean fast) {
        if (lights != null) lights.cancel();
        lights = ValueAnimator.ofInt(0, 1);
        lights.setDuration(fast ? 110 : 520);
        lights.setRepeatCount(ValueAnimator.INFINITE);
        lights.addListener(new android.animation.AnimatorListenerAdapter() {
            @Override public void onAnimationRepeat(android.animation.Animator a) {
                phase = (phase + 1) % 2;
                invalidate();
            }
        });
        lights.start();
    }

    @Override
    protected void onAttachedToWindow() {
        super.onAttachedToWindow();
        setLightsFast(false);
    }

    // ---------------------------------------------------------------
    //  Drawing
    // ---------------------------------------------------------------

    @Override
    protected void onMeasure(int wSpec, int hSpec) {
        int w = MeasureSpec.getSize(wSpec);
        setMeasuredDimension(w, w);              // always square
    }

    private void buildShaders(float size, float cx, float cy, float r) {
        if (builtFor == size) return;
        builtFor = size;

        rim.setShader(new SweepGradient(cx, cy,
                new int[]{0xFFFFE08A, 0xFFE08A1E, 0xFFFFC94D, 0xFF8A5A10, 0xFFFFE08A},
                null));
        gloss.setShader(new RadialGradient(cx, cy - r * 0.25f, r * 1.1f,
                new int[]{Color.argb(70, 255, 255, 255), Color.argb(0, 255, 255, 255),
                          Color.argb(90, 0, 0, 0)},
                new float[]{0f, 0.55f, 1f}, Shader.TileMode.CLAMP));
        hub.setShader(new LinearGradient(cx, cy - r * 0.2f, cx, cy + r * 0.2f,
                0xFFFFE08A, 0xFFE08A1E, Shader.TileMode.CLAMP));
        hubIn.setShader(new RadialGradient(cx, cy - r * 0.05f, r * 0.17f,
                0xFFFFF1C2, 0xFFFFB636, Shader.TileMode.CLAMP));
    }

    @Override
    protected void onDraw(Canvas c) {
        super.onDraw(c);

        int n = coins.length;
        if (n == 0) return;

        float size = Math.min(getWidth(), getHeight());
        float rimW = size * 0.055f;
        float pad  = size * 0.07f;               // room for the pointer
        float cx = size / 2f, cy = size / 2f;
        float r  = size / 2f - pad;              // outer edge of the rim
        float wr = r - rimW;                     // wheel face radius
        float step = 360f / n;

        buildShaders(size, cx, cy, r);

        // ---- rim ----
        rim.setStrokeWidth(rimW);
        c.drawCircle(cx, cy, r - rimW / 2f, rim);

        // ---- segments ----
        box.set(cx - wr, cy - wr, cx + wr, cy + wr);
        text.setTextSize(Math.max(12f, wr * 0.15f));
        small.setTextSize(Math.max(8f, wr * 0.055f));
        sep.setStrokeWidth(Math.max(1.5f, size * 0.004f));

        c.save();
        c.rotate(rotation, cx, cy);

        for (int i = 0; i < n; i++) {
            // -90 puts segment 0's centre at the top, where the pointer is
            float start = -90f - step / 2f + i * step;
            slice.setColor(colors[i]);
            c.drawArc(box, start, step, true, slice);
        }
        // one gloss pass over the whole face
        c.drawCircle(cx, cy, wr, gloss);

        for (int i = 0; i < n; i++) {
            float start = -90f - step / 2f + i * step;

            // separator
            c.save();
            c.rotate(start + 90f, cx, cy);
            c.drawLine(cx, cy, cx, cy - wr, sep);
            c.restore();

            // label along its own slice
            c.save();
            c.rotate(start + step / 2f + 90f, cx, cy);
            boolean light = luminance(colors[i]) > 0.62f;
            text.setColor(light ? Color.parseColor("#1A1405") : Color.WHITE);
            text.setShadowLayer(light ? 0f : 4f, 0f, 1.5f, Color.argb(150, 0, 0, 0));
            small.setColor(light ? Color.argb(170, 26, 20, 5) : Color.argb(200, 255, 255, 255));
            c.drawText(String.valueOf(coins[i]), cx, cy - wr * 0.64f, text);
            c.drawText(coins[i] > 0 ? "COINS" : "TRY", cx, cy - wr * 0.64f + small.getTextSize() * 1.5f, small);
            c.restore();
        }
        c.restore();

        // inner dark line between rim and face
        rimIn.setStrokeWidth(Math.max(2f, size * 0.006f));
        c.drawCircle(cx, cy, wr, rimIn);

        // ---- marquee bulbs ----
        int bulbs = Math.max(12, n * 2);
        float br = rimW * 0.22f;
        for (int i = 0; i < bulbs; i++) {
            double a = Math.toRadians(i * (360f / bulbs) - 90f);
            float bx = cx + (float) Math.cos(a) * (r - rimW / 2f);
            float by = cy + (float) Math.sin(a) * (r - rimW / 2f);
            boolean on = (i % 2) == phase;
            bulb.setColor(on ? Color.WHITE : Color.parseColor("#8A5A10"));
            c.drawCircle(bx, by, br * (on ? 1.15f : 0.9f), bulb);
            if (on) {
                bulb.setColor(Color.argb(90, 255, 224, 138));
                c.drawCircle(bx, by, br * 2.1f, bulb);
            }
        }

        // ---- hub ----
        float hr = wr * 0.22f;
        c.drawCircle(cx, cy, hr, hub);
        c.drawCircle(cx, cy, hr * 0.78f, hubIn);
        hubTxt.setTextSize(hr * 0.42f);
        c.drawText("SPIN", cx, cy + hubTxt.getTextSize() * 0.36f, hubTxt);

        // ---- pointer at twelve o'clock, inside the view ----
        float pw = size * 0.052f;
        float top = pad * 0.25f;
        float tip = pad + rimW * 1.6f;
        pointer.reset();
        pointer.moveTo(cx, tip + size * 0.008f);
        pointer.lineTo(cx - pw, top + size * 0.008f);
        pointer.lineTo(cx + pw, top + size * 0.008f);
        pointer.close();
        c.drawPath(pointer, arrowShade);

        pointer.reset();
        pointer.moveTo(cx, tip);
        pointer.lineTo(cx - pw, top);
        pointer.lineTo(cx + pw, top);
        pointer.close();
        c.drawPath(pointer, arrow);
        c.drawCircle(cx, top + pw * 0.35f, pw * 0.32f, hubIn);
    }

    private static float luminance(int col) {
        return (0.299f * Color.red(col) + 0.587f * Color.green(col)
                + 0.114f * Color.blue(col)) / 255f;
    }

    @Override
    protected void onDetachedFromWindow() {
        if (anim != null) anim.cancel();
        if (lights != null) lights.cancel();
        super.onDetachedFromWindow();
    }
}
