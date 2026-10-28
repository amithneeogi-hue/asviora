<?php
/**
 * Automatic schema migrations.
 *
 * On every request the bootstrap compares the `schema_version` setting with
 * SCHEMA_VERSION below. If the database is behind, the matching .sql files in
 * config/ are applied once and the version is bumped. When they match — which
 * is the case on all but the very first request after an update — nothing runs
 * beyond the settings read that happens anyway, so this costs nothing.
 *
 * Every migration file is written to be idempotent (CREATE TABLE IF NOT EXISTS,
 * INSERT IGNORE, and so on), so a half-applied migration can simply be re-run.
 */

const SCHEMA_VERSION = 6;

/** version => migration file, relative to config/ */
const SCHEMA_MIGRATIONS = [
    2 => 'upgrade-quiz-spin.sql',
    3 => 'upgrade-quiz-proctor.sql',
    // An earlier build also used step 4, so the database may already be
    // marked as migrated. The same idempotent file is therefore mapped to
    // both 4 and 5: whichever step the database is short of, it runs, and
    // running it twice changes nothing.
    4 => 'upgrade-v5.sql',
    5 => 'upgrade-v5.sql',
    // v6 — visitor analytics, spin limits, the Govt Exam module.
    6 => 'upgrade-v6.sql',
];

/**
 * Split a SQL dump into statements without breaking on semicolons that live
 * inside quoted strings — the seed data is full of HTML entities like
 * `&#127758;` — or inside comments.
 */
function sql_statements(string $sql): array
{
    $out = [];
    $cur = '';
    $len = strlen($sql);
    $i   = 0;
    $q   = null;

    while ($i < $len) {
        $c = $sql[$i];

        if ($q !== null) {                       // inside a quoted string
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $cur .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) {   // '' escape
                    $cur .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $q = null;
            }
            $i++;
            continue;
        }

        if (($c === '-' && substr($sql, $i, 2) === '--') || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) break;
            $i = $nl + 1;
            continue;
        }
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) break;
            $i = $end + 2;
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $cur .= $c;
            $i++;
            continue;
        }

        if ($c === ';') {
            $t = trim($cur);
            if ($t !== '') $out[] = $t;
            $cur = '';
            $i++;
            continue;
        }

        $cur .= $c;
        $i++;
    }

    $t = trim($cur);
    if ($t !== '') $out[] = $t;
    return $out;
}

/**
 * MySQL errors that mean "this change is already in place" rather than
 * "something went wrong".
 *   1050 table exists      1060 duplicate column   1061 duplicate key
 *   1062 duplicate entry   1022 duplicate key      1826 duplicate FK
 *   1091 key/column to drop is already gone
 *   1553 index needed by a foreign key — leave it alone
 */
function sql_error_is_benign(PDOException $e): bool
{
    return in_array((int) ($e->errorInfo[1] ?? 0),
                    [1050, 1060, 1061, 1062, 1022, 1826, 1091, 1553], true);
}

/**
 * Apply any migrations the database has not seen yet.
 * Returns the list of files applied (usually empty).
 */
function run_pending_migrations(): array
{
    $applied = [];

    try {
        $current = (int) setting('schema_version', '0');
    } catch (Throwable $e) {
        // settings table missing -> the site has not been installed yet
        return $applied;
    }

    if ($current >= SCHEMA_VERSION) {
        return $applied;
    }

    // A second request arriving mid-migration must not run it in parallel.
    $lock = fopen(sys_get_temp_dir() . '/asviora_migrate.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return $applied;                      // another request is on it
    }

    try {
        // re-read now that we hold the lock, in case it just finished
        $current = (int) (settings_all(true)['schema_version'] ?? 0);

        for ($v = $current + 1; $v <= SCHEMA_VERSION; $v++) {
            if (!isset(SCHEMA_MIGRATIONS[$v])) {
                continue;
            }
            $file = dirname(__DIR__) . '/config/' . SCHEMA_MIGRATIONS[$v];
            if (!is_readable($file)) {
                break;                        // file missing — stop, try again later
            }

            $pdo    = db();
            $failed = false;

            foreach (sql_statements((string) file_get_contents($file)) as $stmt) {
                // the migration files open with `USE <db>`; we are already connected
                if (preg_match('/^USE\s/i', $stmt)) {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    if (!sql_error_is_benign($e)) {
                        $failed = true;
                        if (DEBUG_MODE) {
                            error_log('[migration ' . $v . '] ' . $e->getMessage()
                                      . ' — while running: ' . substr($stmt, 0, 120));
                        }
                        break;
                    }
                }
            }

            if ($failed) {
                break;                        // leave the version alone and retry next request
            }

            set_setting('schema_version', (string) $v);
            $applied[] = SCHEMA_MIGRATIONS[$v];
        }
    } catch (Throwable $e) {
        if (DEBUG_MODE) {
            error_log('[migration] ' . $e->getMessage());
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $applied;
}
