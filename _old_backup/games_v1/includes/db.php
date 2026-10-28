<?php
/**
 * PDO database connection + small query helpers.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        $msg = DEBUG_MODE ? htmlspecialchars($e->getMessage()) : 'Database connection failed.';
        echo '<div style="font:15px/1.6 system-ui,sans-serif;max-width:640px;margin:60px auto;'
           . 'padding:28px;background:#14181d;color:#e6edf3;border:1px solid #2a3038;border-radius:14px">'
           . '<h2 style="margin:0 0 12px;color:#a4d94c">Database not reachable</h2>'
           . '<p style="color:#9aa4b2">' . $msg . '</p>'
           . '<p style="color:#9aa4b2">Import <code>config/database.sql</code> and check the '
           . 'credentials in <code>config/config.php</code>.</p></div>';
        exit;
    }

    return $pdo;
}

/** Run a prepared statement and return the PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Fetch a single row (or null). */
function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch a single scalar value. */
function fetch_col(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Insert and return the new id. */
function insert_row(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** Update rows matching a simple `id = ?` style where clause. */
function update_row(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) {
        $set[] = '`' . $c . '` = ?';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}
