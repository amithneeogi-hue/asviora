package com.asviora.games;

import android.app.Application;

import com.asviora.games.net.Session;

/**
 * Application entry point. Its only job is to give the session store a
 * context before any activity asks for it.
 */
public class AsvioraApp extends Application {
    @Override
    public void onCreate() {
        super.onCreate();
        Session.init(this);
    }
}
