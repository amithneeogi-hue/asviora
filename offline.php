<?php
/**
 * Shown by the service worker when the installed app is opened with no
 * connection. Kept deliberately self-contained — no database, no includes —
 * because it has to render from the cache alone.
 */
$logo = 'assets/img/icon-192.png';
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Offline &mdash; Asviora Games</title>
<meta name="theme-color" content="#0b0c0e">
<style>
  :root { --green:#8fce3f; --gold:#d9a63c; --bg:#0b0c0e; --surface:#17191d; --line:#2e3239; }
  *{box-sizing:border-box}
  body{
    margin:0;min-height:100vh;display:grid;place-items:center;padding:28px;
    background:
      radial-gradient(620px 420px at 20% -10%, rgba(143,206,63,.14), transparent 62%),
      radial-gradient(560px 400px at 88% 6%,  rgba(217,166,60,.12), transparent 60%),
      var(--bg);
    color:#eaeee8;text-align:center;
    font-family:"Plus Jakarta Sans","Segoe UI",system-ui,-apple-system,sans-serif;
  }
  .box{max-width:420px;background:var(--surface);border:1px solid var(--line);
       border-radius:20px;padding:38px 30px;box-shadow:0 22px 60px -24px rgba(0,0,0,.9)}
  img{width:78px;height:78px;border-radius:18px;margin:0 auto 20px;display:block}
  h1{font-size:1.45rem;margin:0 0 10px;font-weight:800;letter-spacing:-.02em}
  p{color:#a2ab99;margin:0 0 22px;line-height:1.6;font-size:.95rem}
  button{
    display:inline-block;border:0;cursor:pointer;
    padding:13px 26px;border-radius:12px;font:inherit;font-weight:800;color:#0b0c0e;
    background:linear-gradient(135deg,#b6e857 0%,#5c9418 100%);
  }
</style>
</head>
<body>
  <div class="box">
    <img src="<?= $logo ?>" alt="">
    <h1>You&rsquo;re offline</h1>
    <p>Asviora Games needs a connection to load games, scores and the leaderboard.
       Reconnect and this page will pick up where you left off.</p>
    <button type="button" onclick="location.reload()">Try again</button>
  </div>
  <script>
    addEventListener('online', function () { location.reload(); });
  </script>
</body>
</html>
