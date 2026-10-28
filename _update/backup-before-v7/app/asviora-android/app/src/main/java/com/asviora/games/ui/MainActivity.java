package com.asviora.games.ui;

import android.os.Bundle;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.fragment.app.Fragment;

import com.asviora.games.R;
import com.asviora.games.ui.tabs.GamesFragment;
import com.asviora.games.ui.tabs.HomeFragment;
import com.asviora.games.ui.tabs.ProfileFragment;
import com.asviora.games.ui.tabs.QuizFragment;
import com.asviora.games.ui.tabs.SpinFragment;
import com.google.android.material.bottomnavigation.BottomNavigationView;

/**
 * The shell: a floating bottom bar with five tabs. Each tab is a fragment
 * that loads its own data, so switching tabs never blocks on a request that
 * belongs to a different screen. Tabs cross-fade with a slight rise.
 *
 * Tabs whose module is hidden on the server are removed rather than shown
 * broken — the config call in SplashActivity is what decides.
 *
 * Back on any tab but Home goes Home first; back on Home leaves the app.
 */
public class MainActivity extends AppCompatActivity {

    private BottomNavigationView nav;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_main);

        nav = findViewById(R.id.nav);

        boolean quizOn  = modules().optBoolean("quiz", true);
        boolean spinOn  = modules().optBoolean("spin", true);
        boolean gamesOn = modules().optBoolean("games", true);

        if (!quizOn)  nav.getMenu().removeItem(R.id.nav_quiz);
        if (!spinOn)  nav.getMenu().removeItem(R.id.nav_spin);
        if (!gamesOn) nav.getMenu().removeItem(R.id.nav_games);

        nav.setOnItemSelectedListener(item -> {
            Motion.tick(nav);
            int id = item.getItemId();
            if (id == R.id.nav_home)    return swap(new HomeFragment());
            if (id == R.id.nav_games)   return swap(new GamesFragment());
            if (id == R.id.nav_quiz)    return swap(new QuizFragment());
            if (id == R.id.nav_spin)    return swap(new SpinFragment());
            if (id == R.id.nav_profile) return swap(new ProfileFragment());
            return false;
        });
        // tapping the tab you are on does nothing, instead of reloading it
        nav.setOnItemReselectedListener(item -> { });

        if (saved == null) {
            swap(new HomeFragment());
        }

        // the bar floats up into place on first open
        nav.setTranslationY(200f);
        nav.setAlpha(0f);
        nav.animate().translationY(0f).alpha(1f)
           .setStartDelay(150).setDuration(520)
           .setInterpolator(new android.view.animation.DecelerateInterpolator(2f))
           .start();
    }

    private org.json.JSONObject modules() {
        org.json.JSONObject m = SplashActivity.config.optJSONObject("modules");
        return m == null ? new org.json.JSONObject() : m;
    }

    private boolean swap(Fragment f) {
        getSupportFragmentManager()
                .beginTransaction()
                .setCustomAnimations(R.anim.tab_in, R.anim.tab_out)
                .replace(R.id.host, f)
                .commit();
        return true;
    }

    @Override
    public void onBackPressed() {
        if (nav != null && nav.getSelectedItemId() != R.id.nav_home) {
            nav.setSelectedItemId(R.id.nav_home);
            return;
        }
        super.onBackPressed();
    }
}
