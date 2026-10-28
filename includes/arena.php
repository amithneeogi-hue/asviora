<?php
/**
 * THE ARENA — one list, every playable thing.
 * ------------------------------------------------------------------
 * The site used to treat the three HTML5 games as "games" and put the Quiz
 * Contest and the Daily Spin in a separate band further down the page. From
 * a player's point of view they are all just things to play, so this file
 * normalises all of them into one shape and renders them with one card, which
 * means the Games section shows five tiles instead of three.
 *
 * Adding another non-database mode later is a matter of appending one more
 * entry in arena_items().
 */

/**
 * The picture for a game: the uploaded thumbnail if there is one, else a
 * bundled icon in assets/img/<slug>_icon.png (chess, quiz and spin ship
 * with one), else null. (v7 — used by the site, the API and the app.)
 */
function game_thumb(string $slug, ?string $file = null): ?string
{
    if ($file && is_file(UPLOAD_PATH . '/games/' . $file)) {
        return UPLOAD_URL . '/games/' . rawurlencode($file);
    }
    $rel = 'assets/img/' . preg_replace('/[^a-z0-9_\-]/i', '', $slug) . '_icon.png';
    return is_file(ROOT_PATH . '/' . $rel) ? url($rel) : null;
}

/** A small inline picture for a game (falls back to its emoji). */
function game_icon_html(string $slug, ?string $file, ?string $emoji, int $size = 24): string
{
    $t = game_thumb($slug, $file);
    if ($t) {
        return '<img src="' . e($t) . '" alt="" class="g-mini" width="' . $size . '" height="' . $size
             . '" loading="lazy" style="width:' . $size . 'px;height:' . $size . 'px">';
    }
    return (string) ($emoji ?: '&#127918;');
}

/**
 * Every playable item, in display order.
 *
 * @param string $cat  optional category filter ('' = everything)
 * @return array<int, array<string, mixed>>
 */
function arena_items(string $cat = ''): array
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        $me    = is_logged_in() ? current_user() : null;

        /* ---------------- the database games ---------------- */
        // Chess is registered in the games table *and* handled below as its
        // own module with the real chess.php entry point. Without this guard
        // it would appear twice and inflate every "how many games" count.
        $chessLive = chess_on();

        foreach (fetch_all('SELECT * FROM games WHERE is_active = 1 ORDER BY sort_order, id') as $g) {
            if ($chessLive && $g['slug'] === 'chess') {
                continue;
            }
            $best = fetch_one(
                'SELECT s.score, u.username FROM scores s JOIN users u ON u.id = s.user_id
                  WHERE s.game_id = ? ORDER BY s.score DESC LIMIT 1', [$g['id']]);

            $rows = [
                ['Reward',    '+' . num($g['reward_coins']) . ' coins', 'gold'],
                ['Top score', $best
                    ? num($best['score']) . ' <span class="text-dim">&middot; ' . e($best['username']) . '</span>'
                    : '&mdash;', ''],
            ];
            if ($me) {
                $mine = (int) fetch_col(
                    'SELECT MAX(score) FROM scores WHERE game_id = ? AND user_id = ?',
                    [$g['id'], $me['id']], 0);
                $rows[] = ['Your best', $mine ? num($mine) : 'Not played', 'green'];
            }
            $rows[] = ['Rounds played', num($g['plays']), ''];

            $thumb = game_thumb($g['slug'], $g['thumbnail']);

            $cache[] = [
                'kind'      => 'game',
                'key'       => $g['slug'],
                'title'     => $g['title'],
                // excerpt() returns an HTML entity for the ellipsis; decode it so
                // arena_card()'s e() does not turn it into "&amp;hellip;"
                'tagline'   => $g['tagline']
                               ?: html_entity_decode(excerpt($g['description'], 130), ENT_QUOTES, 'UTF-8'),
                'category'  => $g['category'],
                'icon'      => $g['icon'] ?: '&#127918;',
                'thumb'     => $thumb,
                'featured'  => (bool) $g['is_featured'],
                'rows'      => $rows,
                'play_url'  => url('play.php?game=' . urlencode($g['slug'])),
                'play_txt'  => 'Play Now',
                'alt_url'   => url('leaderboard.php?game=' . urlencode($g['slug'])),
                'alt_txt'   => 'Board',
            ];
        }

        /* ---------------- quiz contest ---------------- */
        if (quiz_on()) {
            $bank = (int) fetch_col('SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1', [], 0);

            $rows = [
                ['Reward',              '+' . num((int) setting('quiz_reward_hard', '50'))
                                        . ' coins <span class="text-dim">/ answer</span>', 'gold'],
                ['Questions per round', num(quiz_round_size()), ''],
                ['Questions in bank',   num($bank), ''],
            ];
            if ($me && has_table('quiz_sessions')) {
                $mine = (int) fetch_col(
                    'SELECT MAX(correct) FROM quiz_sessions
                      WHERE user_id = ? AND status = "finished"', [$me['id']], 0);
                $rows[] = ['Your best',
                           $mine ? num($mine) . ' correct' : 'Not played', 'green'];
            }
            $rows[] = ['Perfect round bonus',
                       '+' . num((int) setting('quiz_perfect_bonus', '100')) . ' coins', 'gold'];

            $cache[] = [
                'kind'      => 'quiz',
                'key'       => 'quiz',
                'title'     => 'Quiz Contest',
                'tagline'   => quiz_round_size() . ' timed questions across Easy, Mid and Hard. '
                             . 'Every correct answer pays coins the moment you lock it in.',
                'category'  => 'Quiz',
                'icon'      => '&#129504;',
                'thumb'     => url('assets/img/quiz_icon.png'),
                'featured'  => true,
                'rows'      => $rows,
                'play_url'  => url('quiz.php'),
                'play_txt'  => 'Play Quiz',
                'alt_url'   => url('leaderboard.php'),
                'alt_txt'   => 'Board',
            ];
        }

        /* ---------------- daily spin ---------------- */
        if (spin_on()) {
            $segs = spin_segments();
            $top  = 0;
            foreach ($segs as $s) {
                $top = max($top, (int) $s['coins']);
            }
            $record = (int) fetch_col('SELECT COALESCE(MAX(coins),0) FROM spin_results', [], 0);

            $perDay = spin_default_per_day();

            $rows = [
                ['Reward',        'up to +' . num($top) . ' coins', 'gold'],
                ['Cost to spin',  'Free', 'green'],
                ['Spins per day', $perDay > 0 ? num($perDay) : 'Paused', ''],
            ];
            if (spin_default_cooldown() > 0) {
                $rows[] = ['Wait between spins', spin_default_cooldown() . ' min', ''];
            }
            if ($me) {
                $today = spin_today((int) $me['id']);
                $rows[] = ['Today&rsquo;s spin',
                           $today ? 'Used &middot; +' . num($today['coins']) . ' coins' : 'Available',
                           $today ? '' : 'green'];
            }
            $rows[] = ['Biggest win so far', $record ? '+' . num($record) . ' coins' : '&mdash;', 'gold'];

            $cache[] = [
                'kind'      => 'spin',
                'key'       => 'spin',
                'title'     => 'Daily Spin',
                'tagline'   => ($perDay === 1 ? 'One free spin every day' : $perDay . ' free spins a day')
                             . ', no entry fee and nothing to lose. '
                             . 'The wheel resets at midnight — skip a day and that spin is gone.',
                'category'  => 'Rewards',
                'icon'      => '&#127920;',
                'thumb'     => url('assets/img/spin_icon.png'),
                'featured'  => false,
                'rows'      => $rows,
                'play_url'  => url('spin.php'),
                'play_txt'  => 'Spin the Wheel',
                'alt_url'   => url('dashboard.php?tab=wallet'),
                'alt_txt'   => 'Wallet',
            ];
        }

        /* ---------------- chess ---------------- */
        if (chess_on()) {
            $liveNow = (int) fetch_col(
                'SELECT COUNT(*) FROM chess_matches WHERE status = "active"', [], 0);
            $openNow = (int) fetch_col(
                'SELECT COUNT(*) FROM chess_matches WHERE status = "open"', [], 0);

            $rows = [
                ['Reward', 'the whole pot', 'gold'],
                ['Stake',  chess_pvp_on()
                    ? num(chess_min_stake()) . '&ndash;' . num(chess_max_stake()) . ' coins'
                    : 'Free practice', ''],
                ['Open challenges', num($openNow), ''],
            ];
            if ($me) {
                $won = (int) fetch_col(
                    'SELECT COUNT(*) FROM chess_matches WHERE winner_id = ?', [$me['id']], 0);
                $rows[] = ['Your wins', num($won), 'green'];
            }
            $rows[] = ['Matches in play', num($liveNow), ''];

            $cache[] = [
                'kind'      => 'chess',
                'key'       => 'chess',
                'title'     => 'Chess',
                'tagline'   => 'Play the engine for practice, or put coins on the table and '
                             . 'challenge anyone with a link. Winner takes the pot.',
                'category'  => 'Strategy',
                'icon'      => '&#9822;',
                'thumb'     => url('assets/img/chess_icon.png'),
                'featured'  => true,
                'rows'      => $rows,
                'play_url'  => url('chess.php'),
                'play_txt'  => 'Play Chess',
                'alt_url'   => url('chess.php#challengeCard'),
                'alt_txt'   => 'Challenge',
            ];
        }

        /* ---------------- v7: paid plans ---------------- */
        // Price tags and lock state, so every tile — website and app —
        // says up front which games need a pass and whether you have one.
        $mySubs = $me && function_exists('subs_of') ? subs_of((int) $me['id']) : [];
        foreach ($cache as &$it) {
            $plan = function_exists('game_plan') ? game_plan($it['key']) : null;
            $it['plan']     = $plan;
            $it['sub_till'] = $mySubs[$it['key']] ?? null;
            $it['locked']   = $plan !== null && $it['sub_till'] === null;
            if ($plan) {
                $it['price_txt'] = money((int) $plan['price_paise']) . ' / '
                                 . days_label((int) $plan['duration_days']);
                if ($me && $it['locked']) {
                    $it['pay_url'] = url('pay.php?for=game&item=' . urlencode($it['key']));
                }
            }
        }
        unset($it);
    }

    if ($cat === '') {
        return $cache;
    }
    return array_values(array_filter($cache, static function ($i) use ($cat) {
        return strcasecmp($i['category'], $cat) === 0;
    }));
}

/**
 * How many things are actually playable right now.
 *
 * The admin dashboard used to count `games WHERE is_active = 1`, which only
 * ever sees the rows in the games table — so the Quiz Contest, the Daily
 * Spin and Chess were missing and the site reported three when six tiles
 * were live on games.php. Counting the arena instead keeps the two numbers
 * in step whatever gets added next.
 */
function arena_count(): int
{
    return count(arena_items());
}

/** A breakdown of the same number, for the admin dashboard tooltip. */
function arena_breakdown(): array
{
    $out = [];
    foreach (arena_items() as $i) {
        $k = $i['kind'];
        $out[$k] = ($out[$k] ?? 0) + 1;
    }
    return $out;
}

/** The distinct categories across everything playable, for the filter tabs. */
function arena_categories(): array
{
    $out = [];
    foreach (arena_items() as $i) {
        $out[$i['category']] = true;
    }
    return array_keys($out);
}

/**
 * One arena tile. Same markup for a game, the quiz and the spin, so all five
 * sit in the grid looking like siblings.
 *
 * @param array $i     an entry from arena_items()
 * @param bool  $full  true on games.php (every stat row), false on the home page
 */
function arena_card(array $i, bool $full = true): string
{
    $rows = '';
    if ($full) {
        foreach ($i['rows'] as $r) {
            $tone  = $r[2] ? ' text-' . $r[2] : '';
            $rows .= '<div class="kv"><span class="k">' . $r[0] . '</span>'
                   . '<span class="v' . $tone . '">' . $r[1] . '</span></div>';
        }
    }

    $thumb = $i['thumb']
        ? '<img src="' . e($i['thumb']) . '" alt="' . e($i['title'] . ' — ' . $i['category'] . ' game') . '"'
          . ' loading="lazy" width="512" height="512">'
        : '<span class="g-icon">' . $i['icon'] . '</span>';

    $tags = '';
    if ($i['featured']) {
        $tags .= '<span class="badge badge-gold">Featured</span>';
    }
    if ($i['kind'] !== 'game') {
        $label = ['quiz' => 'Live contest', 'spin' => 'Daily', 'chess' => 'Head to head'];
        $tags .= '<span class="badge badge-green">'
               . ($label[$i['kind']] ?? 'Live') . '</span>';
    }
    $tags .= '<span class="badge">' . e($i['category']) . '</span>';

    // v7 — paid plan tags
    $playUrl = $i['play_url'];
    $playTxt = $i['play_txt'];
    $lock    = '';
    if (!empty($i['plan'])) {
        if (!empty($i['sub_till'])) {
            $tags .= '<span class="badge badge-green">&#10003; Pass till '
                   . e(date('d M', strtotime($i['sub_till']))) . '</span>';
        } else {
            $tags .= '<span class="badge badge-gold badge-price">&#128274; ' . e($i['price_txt']) . '</span>';
            $lock  = '<span class="g-lock" aria-hidden="true">&#128274;</span>';
            if (!empty($i['pay_url'])) {
                $playUrl = $i['pay_url'];
                $playTxt = 'Unlock ' . money((int) $i['plan']['price_paise']);
            }
        }
    }

    $btnCls = (!empty($i['pay_url']) && $playUrl === $i['pay_url']) ? 'btn btn-gold btn-sm' : 'btn btn-primary btn-sm';
    $foot = $full
        ? '<a href="' . e($i['alt_url']) . '" class="btn btn-ghost btn-sm">' . e($i['alt_txt']) . '</a>'
          . '<a href="' . e($playUrl) . '" class="' . $btnCls . '">'
          . e($playTxt) . ' &rarr;</a>'
        : '<div><div class="text-dim">' . $i['rows'][0][0] . '</div>'
          . '<div style="font-weight:800">' . $i['rows'][0][1] . '</div></div>'
          . '<a href="' . e($playUrl) . '" class="' . $btnCls . '">'
          . e($playTxt) . '</a>';

    return '<article class="card card-hover game-card arena-' . e($i['kind']) . ' reveal">'
         . '<div class="game-thumb">'
         .   '<div class="game-tags">' . $tags . '</div>'
         .   $thumb . $lock
         . '</div>'
         . '<div class="game-body">'
         .   '<h3>' . e($i['title']) . '</h3>'
         .   '<p>' . e($i['tagline']) . '</p>'
         .   $rows
         .   '<div class="game-foot' . ($full ? ' mt-2' : '') . '">' . $foot . '</div>'
         . '</div>'
         . '</article>';
}
