package com.asviora.games.ui;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Handler;
import android.os.Looper;
import android.util.LruCache;
import android.widget.ImageView;

import com.asviora.games.R;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * v4 — a very small image loader for game pictures, so the app needs no
 * image library: memory cache, a disk cache in cacheDir, downsampling to
 * the view's size, and a tag check so a recycled row never shows the
 * wrong game. Everything is posted back to the main thread.
 */
public final class ImageLoader {

    private static final ExecutorService POOL = Executors.newFixedThreadPool(3);
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final LruCache<String, Bitmap> MEM = new LruCache<String, Bitmap>(
            (int) Math.min(Runtime.getRuntime().maxMemory() / 8, 24L * 1024 * 1024)) {
        @Override protected int sizeOf(String k, Bitmap b) { return b.getByteCount(); }
    };

    private ImageLoader() { }

    public static void cancel(ImageView iv) {
        iv.setTag(R.id.tag_image_url, null);
    }

    /** Load url into iv. The current drawable stays until the new one is ready. */
    public static void load(final ImageView iv, final String url, final int fallbackRes) {
        if (url == null || url.isEmpty()) return;
        iv.setTag(R.id.tag_image_url, url);

        Bitmap hit = MEM.get(url);
        if (hit != null) {
            iv.setImageBitmap(hit);
            return;
        }
        final Context app = iv.getContext().getApplicationContext();
        POOL.execute(() -> {
            Bitmap bmp = null;
            try {
                File f = new File(app.getCacheDir(), "img_" + sha1(url));
                if (!f.exists() || f.length() == 0
                        || System.currentTimeMillis() - f.lastModified() > 3L * 24 * 3600 * 1000) {
                    download(url, f);
                }
                bmp = decode(f, 720);
            } catch (Exception ignored) { }

            final Bitmap out = bmp;
            MAIN.post(() -> {
                if (out == null) return;              // keep the bundled art / emoji
                MEM.put(url, out);
                if (url.equals(iv.getTag(R.id.tag_image_url))) {
                    iv.setImageBitmap(out);
                    iv.setAlpha(0f);
                    iv.animate().alpha(1f).setDuration(220).start();
                }
            });
        });
    }

    private static void download(String url, File to) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setConnectTimeout(12000);
        c.setReadTimeout(15000);
        try {
            if (c.getResponseCode() != 200) throw new Exception("HTTP " + c.getResponseCode());
            File tmp = new File(to.getPath() + ".part");
            try (InputStream in = c.getInputStream(); FileOutputStream os = new FileOutputStream(tmp)) {
                byte[] buf = new byte[16 * 1024];
                int n;
                while ((n = in.read(buf)) > 0) os.write(buf, 0, n);
            }
            if (!tmp.renameTo(to)) throw new Exception("cache");
        } finally {
            c.disconnect();
        }
    }

    private static Bitmap decode(File f, int max) {
        BitmapFactory.Options o = new BitmapFactory.Options();
        o.inJustDecodeBounds = true;
        BitmapFactory.decodeFile(f.getPath(), o);
        int s = 1;
        while (o.outWidth / (s * 2) >= max && o.outHeight / (s * 2) >= max) s *= 2;
        BitmapFactory.Options d = new BitmapFactory.Options();
        d.inSampleSize = s;
        return BitmapFactory.decodeFile(f.getPath(), d);
    }

    private static String sha1(String s) throws Exception {
        byte[] h = MessageDigest.getInstance("SHA-1").digest(s.getBytes("UTF-8"));
        StringBuilder b = new StringBuilder();
        for (byte x : h) b.append(String.format("%02x", x));
        return b.toString();
    }
}
