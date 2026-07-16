<?php
/**
 * SURF-JS — single-file three.js surf sim + sqlite leaderboard
 * drop on bluehost / raspberry pi / `php -S localhost:8000` and go surf.
 *
 * php layer: self-provisioning sqlite (./data/surf.sqlite), csrf-guarded
 * ride saves, top-10 leaderboard. everything else is the game:
 * swash/wet-sand shore sim, effectcomposer post-fx, skinned surfer w/ ik,
 * manual takeoffs, momentum surfing, barrels + bomb sets. all procedural.
 */
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$NONCE = base64_encode(random_bytes(16));

function surf_db(): PDO {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    // the db lives under the web root ("drop on bluehost") — deny direct downloads
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (!file_exists($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    $pdo = new PDO('sqlite:' . $dir . '/surf.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rides (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        duration REAL NOT NULL,
        distance REAL NOT NULL,
        max_speed REAL NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rides_duration ON rides(duration DESC)');
    return $pdo;
}

function json_out(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload);
    exit;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action === 'leaderboard') {
    try {
        $rows = surf_db()->query(
            'SELECT name, duration, distance, max_speed, created_at
             FROM rides ORDER BY duration DESC, distance DESC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);
        json_out(200, ['ok' => true, 'data' => $rows]);
    } catch (Throwable $e) {
        json_out(500, ['ok' => false, 'error' => 'db unavailable']);
    }
}

if ($action === 'save_ride') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(405, ['ok' => false, 'error' => 'POST required']);
    }
    // per-session rate limit: 30 saves / hour
    $now = time();
    if (!isset($_SESSION['rl_win']) || $now - (int)$_SESSION['rl_win'] > 3600) {
        $_SESSION['rl_win'] = $now;
        $_SESSION['rl_n'] = 0;
    }
    if ((int)$_SESSION['rl_n'] >= 30) {
        json_out(429, ['ok' => false, 'error' => 'slow down, kook']);
    }
    $raw = file_get_contents('php://input');
    $in  = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($in) || !hash_equals($_SESSION['csrf'], (string)($in['csrf'] ?? ''))) {
        json_out(403, ['ok' => false, 'error' => 'bad csrf token']);
    }
    $name = trim((string)($in['name'] ?? ''));
    $name = preg_replace('/[^\p{L}\p{N} _\-\.]/u', '', $name);
    $name = $name !== '' ? $name : 'PENGU';
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 20) : substr($name, 0, 20);
    $duration = (float)($in['duration'] ?? 0);
    $distance = (float)($in['distance'] ?? 0);
    $speed    = (float)($in['max_speed'] ?? 0);
    if ($duration < 1 || $duration > 600 || $distance < 0 || $distance > 8000
        || $speed < 0 || $speed > 200) {
        json_out(422, ['ok' => false, 'error' => 'ride stats out of range']);
    }
    try {
        $st = surf_db()->prepare(
            'INSERT INTO rides (name, duration, distance, max_speed) VALUES (?,?,?,?)'
        );
        $st->execute([$name, round($duration, 2), round($distance, 1), round($speed, 1)]);
        $_SESSION['rl_n'] = (int)$_SESSION['rl_n'] + 1;
        json_out(200, ['ok' => true, 'data' => ['saved' => true]]);
    } catch (Throwable $e) {
        json_out(500, ['ok' => false, 'error' => 'db unavailable']);
    }
}

// ---- page ----
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$NONCE}' https://cdn.jsdelivr.net; style-src 'nonce-{$NONCE}'; img-src 'self' data: blob:; connect-src 'self'; base-uri 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin');
$CSRF = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<title>surf-js — surf</title>
<style nonce="<?= $NONCE ?>">
:root{
  --ink:#0b2233; --foam:#f4fbfd; --teal:#19c1d4; --sun:#ffb45e;
  --panel:rgba(8,26,38,.62); --panel-brd:rgba(180,230,240,.22);
  --disp:'Avenir Next Condensed','Arial Narrow Bold','Arial Narrow','Helvetica Neue',Arial,sans-serif;
  --body:'Avenir Next','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;overflow:hidden;background:#0a1c2a;font-family:var(--body);color:var(--foam)}
canvas{display:block}
#ui{position:fixed;inset:0;pointer-events:none;z-index:5;visibility:hidden}
.game-started #ui{visibility:visible}
.panel{background:var(--panel);border:1px solid var(--panel-brd);border-radius:12px;
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);box-shadow:0 8px 30px rgba(0,10,20,.35)}
.disp{font-family:var(--disp);font-weight:800;text-transform:uppercase;letter-spacing:.14em}

#topbar{position:absolute;top:14px;left:14px;display:flex;gap:10px;align-items:center}
#brand{padding:8px 14px;font-size:15px}
#brand .peng{color:var(--teal)}
#stats{padding:8px 14px;font-size:12px;letter-spacing:.06em;opacity:.95}
#stats b{color:var(--sun)}

#alert{position:absolute;top:16px;left:50%;transform:translateX(-50%);padding:9px 20px;
  font-size:14px;color:#08222e;background:linear-gradient(135deg,#8ff0ff,#37d3e6);
  border:0;border-radius:999px;opacity:0;transition:opacity .35s,transform .35s;font-family:var(--disp);
  font-weight:800;text-transform:uppercase;letter-spacing:.14em}
#alert.show{opacity:1;transform:translateX(-50%) translateY(4px)}

#prompt{position:absolute;bottom:26px;left:50%;transform:translateX(-50%);padding:10px 18px;
  font-size:13px;letter-spacing:.05em;text-align:center;max-width:86vw}
#prompt kbd{font-family:var(--disp);font-weight:800;background:rgba(255,255,255,.14);
  border:1px solid rgba(255,255,255,.25);border-radius:6px;padding:1px 7px;margin:0 2px;font-size:12px}

#ridehud{position:absolute;top:74px;left:50%;transform:translateX(-50%);text-align:center;display:none}
#ridehud .t{font-size:34px;font-family:var(--disp);font-weight:800;letter-spacing:.08em;
  text-shadow:0 2px 14px rgba(0,40,60,.6)}
#ridehud .s{font-size:13px;letter-spacing:.2em;text-transform:uppercase;opacity:.9}

#wavemeter{position:absolute;bottom:26px;right:18px;padding:9px 14px;font-size:12px;
  letter-spacing:.08em;display:none;text-transform:uppercase}
#wavemeter b{color:var(--teal);font-family:var(--disp);font-size:15px}

#radarWrap{position:absolute;right:18px;bottom:64px;display:none;padding:7px 7px 4px}
#radarWrap .cap{font-family:var(--disp);font-weight:800;font-size:9px;letter-spacing:.18em;
  text-transform:uppercase;opacity:.7;text-align:center;padding:3px 0 1px}
#radar{display:block;border-radius:7px}

#cards{position:absolute;left:14px;bottom:26px;display:none;flex-direction:column;gap:8px;max-width:264px}
.wcard{padding:8px 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.wcard .ft{font-family:var(--disp);font-weight:800;font-size:22px;line-height:1;min-width:52px}
.wcard .ft small{font-size:10px;letter-spacing:.1em;display:block;opacity:.7}
.wcard .call{font-family:var(--disp);font-weight:800;font-size:11px;letter-spacing:.12em;
  padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.14)}
.wcard.LEFT .call{background:rgba(25,193,212,.3);color:#8ff0ff}
.wcard.RIGHT .call{background:rgba(255,180,94,.3);color:#ffd39e}
.wcard.CLOSEOUT .call{background:rgba(255,90,90,.3);color:#ffb3b3}
.wcard .call.hollow{background:rgba(167,139,250,.32)!important;color:#d8ccff!important;margin-left:2px}
.wcard .eta{margin-left:auto;font-size:12px;opacity:.85;font-variant-numeric:tabular-nums}
.wcard.hot{outline:2px solid var(--teal)}

#grade{position:absolute;top:120px;left:50%;transform:translateX(-50%);padding:8px 22px;
  font-family:var(--disp);font-weight:800;font-size:18px;letter-spacing:.14em;text-transform:uppercase;
  color:#08222e;background:linear-gradient(135deg,#ffe9a8,#ffb45e);border-radius:999px;
  opacity:0;transition:opacity .25s,transform .25s;pointer-events:none}
#grade.show{opacity:1;transform:translateX(-50%) translateY(6px)}
#grade.bad{background:linear-gradient(135deg,#ff9a9a,#e05a5a);color:#2b0808}

#barrelhud{position:absolute;top:150px;left:50%;transform:translateX(-50%);display:none;
  text-align:center;font-family:var(--disp);font-weight:800;letter-spacing:.16em;
  text-transform:uppercase;color:#bff4ff;text-shadow:0 0 18px rgba(60,220,255,.8)}
#barrelhud .bt{font-size:30px}#barrelhud .bl{font-size:11px;opacity:.85}

#toast{position:absolute;bottom:86px;left:50%;transform:translateX(-50%) translateY(20px);
  padding:14px 16px;display:none;pointer-events:auto;min-width:300px;opacity:0;transition:.3s}
#toast.show{display:block;opacity:1;transform:translateX(-50%) translateY(0)}
#toastMsg{font-size:13px;letter-spacing:.04em}
#toast .row{display:flex;gap:8px;margin-top:10px}
#toast input{flex:1;background:rgba(255,255,255,.1);border:1px solid var(--panel-brd);
  border-radius:8px;color:var(--foam);padding:7px 10px;font-family:var(--body);font-size:13px}
#toast input:focus{outline:2px solid var(--teal)}

button{pointer-events:auto;cursor:pointer;font-family:var(--disp);font-weight:800;
  text-transform:uppercase;letter-spacing:.1em;font-size:12px;border:0;border-radius:8px;
  padding:8px 14px;background:linear-gradient(135deg,#37d3e6,#1b9fb5);color:#06222c}
button:hover{filter:brightness(1.12)}
button:focus-visible{outline:2px solid #fff;outline-offset:2px}
button.ghost{background:rgba(255,255,255,.1);color:var(--foam);border:1px solid var(--panel-brd)}

.modal{position:absolute;inset:0;display:none;align-items:center;justify-content:center;
  background:rgba(4,14,22,.55);pointer-events:auto}
.modal.show{display:flex}
.modal .card{width:min(460px,92vw);padding:26px 26px 22px}
.modal h2{font-family:var(--disp);font-weight:800;letter-spacing:.14em;text-transform:uppercase;
  font-size:20px;margin-bottom:14px;color:var(--foam)}
.modal h2 span{color:var(--teal)}
.kv{display:flex;justify-content:space-between;font-size:13px;padding:7px 2px;
  border-bottom:1px dashed rgba(255,255,255,.12)}
.kv .k{opacity:.85}.kv kbd{font-family:var(--disp);font-weight:800;background:rgba(255,255,255,.12);
  border-radius:5px;padding:1px 7px;font-size:11px;border:1px solid rgba(255,255,255,.22)}
.btnrow{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
#lbBody{max-height:300px;overflow:auto;margin-top:4px}
#lbBody table{width:100%;border-collapse:collapse;font-size:13px}
#lbBody td,#lbBody th{padding:7px 6px;text-align:left;border-bottom:1px solid rgba(255,255,255,.1)}
#lbBody th{font-family:var(--disp);letter-spacing:.1em;text-transform:uppercase;font-size:11px;opacity:.75}
#lbBody td.num{text-align:right;font-variant-numeric:tabular-nums}

#underwater{position:absolute;inset:0;display:none;pointer-events:none;
  background:radial-gradient(ellipse at 50% 45%, rgba(14,110,120,.28), rgba(3,44,64,.72));
  mix-blend-mode:normal}
#barrelfx{position:absolute;inset:0;pointer-events:none;opacity:0;transition:opacity .22s;
  background:radial-gradient(ellipse at 67% 46%,rgba(90,245,225,0) 0 18%,rgba(5,94,102,.14) 43%,
    rgba(1,37,48,.54) 78%,rgba(0,16,27,.74) 100%);mix-blend-mode:multiply}
#barrelfx.on{opacity:1}
#vignette{position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(ellipse at center, transparent 62%, rgba(2,12,20,.45))}

#start{position:absolute;inset:0;display:grid;grid-template-columns:minmax(250px,30vw) minmax(360px,35vw);
  align-items:center;justify-content:space-between;gap:clamp(150px,28vw,520px);
  padding:clamp(28px,5vw,80px);overflow:hidden;
  background:linear-gradient(90deg,rgba(4,24,39,.96) 0%,rgba(5,43,64,.78) 28%,rgba(7,58,78,.05) 45%,
    rgba(7,58,78,.05) 62%,rgba(5,43,64,.78) 82%,rgba(4,24,39,.96) 100%),
    linear-gradient(180deg,rgba(8,54,79,.32),rgba(5,34,50,.58));
  pointer-events:auto;z-index:9}
.startHero{display:flex;flex-direction:column;align-items:flex-start;gap:13px;position:relative;z-index:2}
.startPanel{display:flex;flex-direction:column;align-items:stretch;gap:12px;position:relative;z-index:2;
  padding:20px;background:rgba(5,24,37,.68);border:1px solid rgba(180,230,240,.2);border-radius:18px;
  box-shadow:0 18px 60px rgba(0,18,30,.34)}
#start h1{font-family:var(--disp);font-weight:800;font-size:clamp(40px,9vw,86px);
  letter-spacing:.1em;text-transform:uppercase;line-height:.95;text-align:left;
  background:linear-gradient(180deg,#f6fdff,#8fe7f5 60%,#31c3da);
  -webkit-background-clip:text;background-clip:text;color:transparent;
  filter:drop-shadow(0 6px 22px rgba(0,30,50,.5))}
#start .sub{letter-spacing:.32em;text-transform:uppercase;font-size:12px;opacity:.85}
#start .peng{font-size:44px;line-height:1;filter:drop-shadow(0 4px 10px rgba(0,20,30,.5))}
#start button{font-size:15px;padding:13px 30px;margin-top:0}
#start .hint{font-size:12px;opacity:.7;letter-spacing:.06em;margin-top:6px}
#start .hint b{color:var(--teal)}
.picklabel{font-family:var(--disp);font-weight:800;font-size:10px;letter-spacing:.2em;text-transform:uppercase;
  color:#a7e8f1;margin:2px 2px -4px}
.liveTag{display:inline-flex;align-items:center;gap:8px;font-family:var(--disp);font-weight:800;font-size:10px;
  letter-spacing:.16em;text-transform:uppercase;color:#c8f5fa;background:rgba(8,35,50,.58);
  border:1px solid rgba(175,235,243,.2);border-radius:999px;padding:7px 11px}
.liveTag::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--teal);
  box-shadow:0 0 12px rgba(25,193,212,.9)}
.selgrp{display:flex;gap:10px;margin-top:2px;flex-wrap:wrap;justify-content:center}
#start .selcard{display:flex;flex-direction:column;align-items:center;gap:4px;
  background:rgba(8,26,38,.45);border:1px solid var(--panel-brd);border-radius:12px;
  padding:10px 16px;color:var(--foam);min-width:128px;margin-top:0;font-size:12px}
#start .selcard small{font-family:var(--body);font-weight:400;text-transform:none;
  letter-spacing:.03em;font-size:10px;opacity:.72}
#start .selcard b{font-size:12px;letter-spacing:.1em}
#start .selcard.sel{outline:2px solid var(--teal);background:rgba(25,193,212,.16)}
.rswatch{width:44px;height:30px;display:block;border-radius:22px 22px 10px 10px;border:2px solid rgba(255,255,255,.2);
  box-shadow:inset 0 -8px 0 rgba(0,0,0,.18)}
.rswatch.kai{background:linear-gradient(90deg,#1a1d23 0 68%,#19c1d4 68%)}
.rswatch.mara{background:linear-gradient(90deg,#2a1030 0 68%,#ff7d9e 68%)}
.bshape{display:block;width:13px;background:linear-gradient(180deg,#f6fdff,#bfe9f2);
  border-radius:50% / 26%;box-shadow:0 2px 6px rgba(0,20,30,.4)}
.bshort{height:32px}
.bfun{height:42px}
.blong{height:54px;background:linear-gradient(180deg,#ffe9c2,#e0bd85)}
#btnStart{width:100%;margin-top:4px!important}
#err{position:absolute;inset:0;display:none;align-items:center;justify-content:center;
  text-align:center;padding:30px;background:#0a1c2a;z-index:10;font-size:15px;line-height:1.6}
@media(max-width:900px){
  #start{grid-template-columns:minmax(210px,1fr) minmax(310px,1.15fr);gap:24vw;padding:24px}
  #start h1{font-size:clamp(40px,7vw,62px)}.startPanel{padding:14px}.startHero .peng{font-size:32px}
  #start .selcard{min-width:104px;padding:8px 10px}#start .hint{font-size:10px}
}
@media (prefers-reduced-motion: reduce){ *{transition:none!important;animation:none!important} }
</style>
</head>
<body>
<div id="ui">
  <div id="topbar">
    <div class="panel disp" id="brand"><span class="peng">&#9679;</span> surf-js</div>
    <div class="panel" id="stats">waves <b id="stWaves">0</b> &nbsp;&middot;&nbsp; best <b id="stBest">0.0s</b>
      &nbsp;&middot;&nbsp; barrel <b id="stTube">&mdash;</b> &nbsp;&middot;&nbsp; face <b id="stFace">&mdash;</b></div>
  </div>
  <div id="alert" role="status"></div>
  <div id="grade" role="status"></div>
  <div id="barrelhud"><div class="bt" id="barrelT">0.0s</div><div class="bl">in the barrel</div></div>
  <div id="ridehud"><div class="t" id="rideTime">0.0s</div><div class="s"><span id="rideSpd">0</span> mph</div></div>
  <div id="wavemeter" class="panel">next wave <b id="waveDist">&mdash;</b></div>
  <div id="radarWrap" class="panel"><canvas id="radar" width="150" height="168" aria-label="wave radar"></canvas>
    <div class="cap">lineup radar</div></div>
  <div id="cards" aria-label="incoming waves"></div>
  <div id="prompt" class="panel"></div>

  <div id="toast" class="panel">
    <div id="toastMsg"></div>
    <div class="row"><input id="riderName" maxlength="20" placeholder="your name" value="PENGU">
      <button id="btnSaveRide">save</button><button class="ghost" id="btnDismiss">skip</button></div>
  </div>

  <div class="modal" id="pause"><div class="card panel">
    <h2>paused <span>&#124;</span> controls</h2>
    <div class="kv"><span class="k">move / paddle / carve</span><span><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd></span></div>
    <div class="kv"><span class="k">run (sand) / duck dive / kick out</span><span><kbd>SHIFT</kbd></span></div>
    <div class="kv"><span class="k">hop on board / pop up</span><span><kbd>SPACE</kbd></span></div>
    <div class="kv"><span class="k">sit up / lie down / dismount</span><span><kbd>E</kbd></span></div>
    <div class="kv"><span class="k">camera 3rd &harr; 1st person</span><span><kbd>C</kbd></span></div>
    <div class="kv"><span class="k">look around / zoom</span><span>drag &middot; wheel</span></div>
    <div class="kv"><span class="k">leaderboard / mute / pause</span><span><kbd>L</kbd><kbd>M</kbd><kbd>P</kbd></span></div>
    <div class="btnrow">
      <button id="btnResume">resume</button>
      <button class="ghost" id="btnSound">sound: on</button>
      <button class="ghost" id="btnQuality">post-fx: full</button>
    </div>
    <div class="btnrow">
      <button class="ghost" id="btnParticles">spray: high</button>
      <button class="ghost" id="btnRefl">mirror sand: on</button>
      <button class="ghost" id="btnMotion">motion: full</button>
      <button class="ghost" id="btnLb2">leaderboard</button>
    </div>
  </div></div>

  <div class="modal" id="lb"><div class="card panel">
    <h2>local <span>leaderboard</span></h2>
    <div id="lbBody">loading&hellip;</div>
    <div class="btnrow"><button id="btnLbClose">close</button></div>
  </div></div>

  <div id="underwater"></div>
  <div id="barrelfx"></div>
  <div id="vignette"></div>
</div>

<div id="start">
  <section class="startHero">
    <div class="peng">&#128039;</div>
    <h1>surf<br>-js</h1>
    <div class="sub">a single-file surf sim</div>
    <div class="liveTag">live procedural rider preview</div>
  </section>
  <section class="startPanel" aria-label="surfer setup">
    <div class="picklabel">choose your surfer</div>
    <div class="selgrp" id="riderSel" role="group" aria-label="choose your rider">
      <button class="selcard" data-rider="kai"><span class="rswatch kai"></span><b>KAI</b><small>regular &middot; power lines</small></button>
      <button class="selcard" data-rider="mara"><span class="rswatch mara"></span><b>MARA</b><small>goofy &middot; light feet</small></button>
    </div>
    <div class="picklabel">choose your board</div>
    <div class="selgrp" id="boardSel" role="group" aria-label="choose your board">
      <button class="selcard" data-board="short"><i class="bshape bshort"></i><b>5'10" SHORT</b><small>quick rails &middot; late drops</small></button>
      <button class="selcard" data-board="fun"><i class="bshape bfun"></i><b>7'2" FUNBOARD</b><small>balanced &middot; forgiving</small></button>
      <button class="selcard" data-board="long"><i class="bshape blong"></i><b>9'1" LOG</b><small>glides in early &middot; mind the pearl</small></button>
    </div>
    <button id="btnStart">paddle out</button>
    <div class="hint"><b>1/2/3</b> board &middot; <b>R</b> rider &middot; <b>F</b> fullscreen<br>keyboard + mouse &middot; best in chrome/safari on desktop</div>
  </section>
</div>
<div id="err"><div></div></div>

<script nonce="<?= $NONCE ?>">
window.PP_CSRF = "<?= $CSRF ?>";
</script>
<script type="importmap" nonce="<?= $NONCE ?>">
{ "imports": {
  "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
  "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
} }
</script>
<script type="module" nonce="<?= $NONCE ?>">
import * as THREE from 'three';
import {EffectComposer} from 'three/addons/postprocessing/EffectComposer.js';
import {RenderPass} from 'three/addons/postprocessing/RenderPass.js';
import {ShaderPass} from 'three/addons/postprocessing/ShaderPass.js';
import {UnrealBloomPass} from 'three/addons/postprocessing/UnrealBloomPass.js';
import {SMAAPass} from 'three/addons/postprocessing/SMAAPass.js';
import {OutputPass} from 'three/addons/postprocessing/OutputPass.js';

/* ============================================================
   SURF-JS — game module
   layout: config / shared wave+swash math (JS<->GLSL parity) /
   post-fx tiers / sky+env / wet sand / terrain / under-pass /
   props / ocean+lip curl / swash strip / spray / swells+bores /
   skinned character / poses / ik+anim+ragdoll / board /
   state machine (manual takeoff, momentum surf, tubes) /
   camera / input / audio / hud / net / main loop
   ============================================================ */

// ---------- config ----------
const CFG = {
  gravity: 9.81,
  seaLevel: 0,
  worldX: 700,             // half-extent along shore
  oceanZ: [-780, -5.5],    // ocean plane z range (swash strip owns -8..+10)
  walk: 2.3, run: 4.8,
  paddle: 2.8,
  maxSwells: 6,
  maxBores: 4,
  setEveryMin: 13, setEveryMax: 30,   // seconds between sets
  wavesPerSetMin: 2, wavesPerSetMax: 4,
  swellGapMin: 6.5, swellGapMax: 10,
  bombEveryMin: 240, bombEveryMax: 480,  // rare bomb sets, 4-8 min
  spawnZ: -260,
  sunDir: new THREE.Vector3(0.42, 0.40, -0.82).normalize(), // toward sun
};
// wave classes: deep-water amp0 ranges; shoaling roughly doubles at the bar
const WCLS = [
  {name:'1-2FT',  amp:[0.60,0.90], w:0.06},
  {name:'2-3FT',  amp:[0.90,1.30], w:0.24},
  {name:'4-5FT',  amp:[1.30,1.90], w:0.40},
  {name:'6-8FT',  amp:[1.90,3.00], w:0.30},
  {name:'8-10FT', amp:[3.00,4.50], w:0.00},   // bombs only via bomb clock
];
// swash sheet constants (authored once, mirrored into GLSL below)
const SWH = {
  zBase: -6.0,          // where the sheet hands over to the open ocean surface
  v0k: 1.35,            // uprush launch speed = v0k*sqrt(g*boreAmp)
  v0Max: 6.0,           // keeps runup on the strip (z <= ~+9.5)
  aUp: 9.81*(0.062+0.06),   // decel: beach slope + friction 0.06
  aDn: 9.81*(0.062-0.020),  // backwash accel (lubricated sand drains slower)
  filmK: 0.085,         // sheet thickness scale per meter of bore amp
  feather: 0.35,        // meniscus alpha feather at the leading edge (m)
};

// front-face compression as a wave breaks: 0 -> symmetric sech^2, 1 -> vertical wall.
// used by BOTH the JS physics and the GLSL twins (injected via f6) — keep in sync.
const FACEK = 0.78;

const clamp = (v,a,b)=>Math.min(b,Math.max(a,v));
const lerp = (a,b,t)=>a+(b-a)*t;
const damp = (rate,dt)=>1-Math.exp(-rate*dt);
const sstep = (a,b,x)=>{const t=clamp((x-a)/(b-a),0,1);return t*t*(3-2*t);};
const rand = (a,b)=>a+Math.random()*(b-a);
const TAU = Math.PI*2;

// ---------- shared surface math (authored once, mirrored into GLSL) ----------
// ambient chop: directional sine stack [deg, amp, wavelength]
const AMB = [[5,.10,14],[-12,.07,9],[20,.05,6],[-25,.045,4.2],[8,.16,22],[-4,.12,17]]
  .map(([d,a,l],i)=>{const r=d*Math.PI/180,k=TAU/l;
    return {a, kx:Math.sin(r)*k, kz:Math.cos(r)*k, w:Math.sqrt(9.81*k), ph:i*1.7};});

function terrainY(x,z){
  let y;
  // gentle shelf + pronounced outer sandbar at z=-85 -> waves break far out
  if (z<0){ y = -6.2*Math.tanh(-z/135) + 1.45*Math.exp(-(((z+85)/24)**2)); }
  else    { y = z*0.062 + 1.9*sstep(26,64,z); }
  y += 0.22*Math.sin(x*0.021+z*0.017)*sstep(-2,20,z);
  y += 0.05*Math.sin(x*0.13)*Math.sin(z*0.11);
  return y;
}

// live swell list — mirrored to shader uniforms each frame
// swell: xc,zc,amp,L | halfW,bf,bL,bR (broken x-interval) | wash,brl,tBrk,dir,cls
const swells = [];
const faceLen = s => s.L*(1-FACEK*s.bf);   // front-face length of a (breaking) swell
const pocketW = s => 8 + s.amp*4;          // lip-curl pocket width — GLSL twin hardcodes 8.0 + P.z*4.0
function swellHeightAt(x,z){
  let h=0;
  for (const s of swells){
    const dz = z - s.zc;
    const Lf = s.L * (dz>0 ? (1-FACEK*s.bf) : 1);
    const u = dz/Lf, e = Math.exp(u), sech = 2/(e+1/e);
    const q = (x-s.xc)/s.halfW, q2=q*q, env = Math.exp(-(q2*q2));
    const inner = sstep(-3, 2.5, Math.min(x-s.bL, s.bR-x));
    const crest=sech*sech;
    const trU=(u-1.18)/0.62, trough=Math.exp(-(trU*trU))*s.bf;
    h += s.amp*(crest*(1-0.42*s.bf*inner)-0.22*trough)*env*s.wash;
  }
  return h;
}
// ---- swash bores (uprush/backwash sheets on the beach face) ----
// bore: xc, halfW, t0, amp, v0, zPeak, tS (stall time), life
const bores = [];
function makeBore(xc, halfW, t0, amp){
  const v0 = Math.min(SWH.v0k*Math.sqrt(9.81*Math.max(amp,0.05)), SWH.v0Max);
  const zPeak = SWH.zBase + v0*v0/(2*SWH.aUp);
  const tS = v0/SWH.aUp;
  const life = tS + Math.sqrt(2*(zPeak-SWH.zBase)/SWH.aDn);
  return {xc, halfW, t0, amp, v0, zPeak, tS, life};
}
function boreFront(b,t){        // leading-edge z of the sheet
  const tau = t - b.t0;
  if (tau<=0 || tau>=b.life) return SWH.zBase;
  if (tau<=b.tS) return SWH.zBase + b.v0*tau - 0.5*SWH.aUp*tau*tau;
  const td = tau - b.tS;
  return b.zPeak - 0.5*SWH.aDn*td*td;
}
function boreVel(b,t){          // signed sheet velocity (+ = uprush)
  const tau = t - b.t0;
  if (tau<=0 || tau>=b.life) return 0;
  if (tau<=b.tS) return b.v0 - SWH.aUp*tau;
  return -SWH.aDn*(tau - b.tS);
}
function boreEnv(b,x){ const q=(x-b.xc)/b.halfW, q2=q*q; return Math.exp(-(q2*q2)); }
// film thickness of all sheets at a point (rides on the sand above zBase)
function swashFilm(x,z,t){
  let f=0;
  for (const b of bores){
    const zF = boreFront(b,t);
    const rel = zF - z;
    if (rel<=0) continue;
    const body = Math.pow(clamp(rel/Math.max(zF-SWH.zBase,0.5),0,1),0.6);
    f += (0.02 + SWH.filmK*b.amp) * body * boreEnv(b,x);
  }
  return f;
}
function waterH(x,z,t){
  let h=0;
  for (const g of AMB) h += g.a*Math.sin(x*g.kx+z*g.kz-t*g.w+g.ph);
  return h + swellHeightAt(x,z);
}
// hollow-barrel lip curl: crest verts thrown forward past vertical, then drooped.
// visual displacement only — physics stays on the h(x,z,t) heightfield. GLSL twin below.
function lipCurlAt(x,z,out){
  out.y=0; out.z=0;
  for (const s of swells){
    if (s.brl < 0.03 && s.bf < 0.22) continue;
    const dz = z - s.zc;
    const LfD = s.L*(dz>0 ? (1-FACEK*s.bf) : 1);
    const e = Math.exp(dz/LfD), sech = 2/(e+1/e);
    const prof = sech*sech;
    const eL = Math.abs(x-s.bL), eR = Math.abs(x-s.bR);
    const eD = Math.min(eL + (s.dir>0?1e5:0), eR + (s.dir<0?1e5:0));
    const pw = pocketW(s);
    const zone = Math.exp(-eD*eD/(pw*pw));
    const outside = 1 - sstep(-1, 1.5, Math.min(x-s.bL, s.bR-x));
    // pre-barrel: once the face steepens, the whole unbroken crest leans forward
    const q = (x-s.xc)/s.halfW, q2=q*q, envX = Math.exp(-(q2*q2));
    const preK = sstep(0.22, 0.90, s.bf) * 0.22 * envX * outside;
    const k = Math.max(s.brl * zone * Math.max(outside, 0.15), preK) * s.wash;
    const thr = 1.25 + 0.40*sstep(1.5, 2.8, s.amp);   // big waves throw further
    const tf = k * prof*prof;
    out.z += s.amp*thr*tf;
    out.y += s.amp*(0.28*tf - 0.85*tf*tf);
  }
  return out;
}
// full free surface incl. the beach sheet — use near/above the sand line
function surfY(x,z,t){
  const ter = terrainY(x,z);
  return Math.max(waterH(x,z,t), ter + swashFilm(x,z,t));
}
function waterNormal(x,z,t,out){ // finite-diff, matches shader
  const e=0.35, h=waterH(x,z,t);
  const hx=waterH(x+e,z,t), hz=waterH(x,z+e,t);
  out.set(-(hx-h)/e, 1, -(hz-h)/e).normalize();
  return h;
}
function depthAt(x,z,t){ return waterH(x,z,t) - terrainY(x,z); }

// ---- GLSL twins (numbers injected from the same tables above) ----
const f6 = n=>{const s=n.toFixed(6);return s.indexOf('.')<0?s+'.0':s;};
const GLSL_AMB = AMB.map(g=>
  `h += ${f6(g.a)}*sin(p.x*${f6(g.kx)} + p.y*${f6(g.kz)} - t*${f6(g.w)} + ${f6(g.ph)});`).join('\n  ');
const GLSL_SHARED = `
float sstep(float a,float b,float x){float t=clamp((x-a)/(b-a),0.,1.);return t*t*(3.-2.*t);}
float tanH(float x){return 1.0-2.0/(exp(2.0*x)+1.0);}
float terrainY(vec2 p){
  float x=p.x, z=p.y, y;
  // square by hand: pow(negative, 2.0) is undefined in GLSL
  float tb=(z+85.0)/24.0;
  if (z<0.0){ y = -6.2*tanH(-z/135.0) + 1.45*exp(-tb*tb); }
  else      { y = z*0.062 + 1.9*sstep(26.0,64.0,z); }
  y += 0.22*sin(x*0.021+z*0.017)*sstep(-2.0,20.0,z);
  y += 0.05*sin(x*0.13)*sin(z*0.11);
  return y;
}
uniform vec4 uSwP[${CFG.maxSwells}]; // xc, zc, amp, L
uniform vec4 uSwQ[${CFG.maxSwells}]; // halfW, breakFrac, brokenL, brokenR
uniform vec4 uSwR[${CFG.maxSwells}]; // wash, barrel, tSinceBreak, peelDir
float swellH(vec2 p){
  float h=0.0;
  for (int i=0;i<${CFG.maxSwells};i++){
    vec4 P=uSwP[i]; vec4 Q=uSwQ[i];
    float dz = p.y - P.y;
    float Lf = P.w * (1.0 - ${f6(FACEK)}*Q.y*step(0.0,dz));
    float u = dz/Lf; float e = exp(u); float sech = 2.0/(e+1.0/e);
    float q = (p.x-P.x)/Q.x; float q2=q*q; float env = exp(-(q2*q2));
    float inner = sstep(-3.0, 2.5, min(p.x-Q.z, Q.w-p.x));
    float crest=sech*sech;
    float trU=(u-1.18)/0.62;
    float trough=exp(-(trU*trU))*Q.y;
    h += P.z*(crest*(1.0-0.42*Q.y*inner)-0.22*trough)*env*uSwR[i].x;
  }
  return h;
}
float waterH(vec2 p, float t){
  float h=0.0;
  ${GLSL_AMB}
  h += swellH(p);
  return h;
}
// twin of JS lipCurlAt (yz offset packed in a vec2)
vec2 lipCurl(vec2 p){
  vec2 off = vec2(0.0);
  for (int i=0;i<${CFG.maxSwells};i++){
    float brl = uSwR[i].y;
    vec4 P=uSwP[i]; vec4 Q=uSwQ[i];
    float dz = p.y - P.y;
    float LfD = P.w*(1.0 - ${f6(FACEK)}*Q.y*step(0.0,dz));
    float e = exp(dz/LfD); float sech = 2.0/(e+1.0/e);
    float prof = sech*sech;
    float eL = abs(p.x-Q.z), eR = abs(p.x-Q.w);
    float eD = min(eL + step(0.5,uSwR[i].w)*1e5, eR + step(uSwR[i].w,-0.5)*1e5);
    float pw = 8.0 + P.z*4.0;
    float zone = exp(-eD*eD/(pw*pw));
    float outside = 1.0 - sstep(-1.0, 1.5, min(p.x-Q.z, Q.w-p.x));
    // pre-barrel: once the face steepens, the whole unbroken crest leans forward
    float q = (p.x-P.x)/Q.x; float q2=q*q; float envX = exp(-(q2*q2));
    float preK = sstep(0.22, 0.90, Q.y) * 0.22 * envX * outside;
    float k = max(brl * zone * max(outside, 0.15), preK) * uSwR[i].x;
    float thr = 1.25 + 0.40*sstep(1.5, 2.8, P.z);   // big waves throw further
    float tf = k * prof*prof;
    off.y += P.z*thr*tf;
    off.x += P.z*(0.28*tf - 0.85*tf*tf);
  }
  return off;   // x = dy, y = dz
}
// ---- swash bores (same analytic model as JS makeBore/boreFront/boreVel) ----
uniform vec4 uBoA[${CFG.maxBores}]; // xc, halfW, t0, amp
uniform vec4 uBoB[${CFG.maxBores}]; // v0, zPeak, tS, life
float boreFront(int i, float t){
  vec4 A=uBoA[i]; vec4 B=uBoB[i];
  float tau = t - A.z;
  if (tau<=0.0 || tau>=B.w) return ${f6(SWH.zBase)};
  if (tau<=B.z) return ${f6(SWH.zBase)} + B.x*tau - 0.5*${f6(SWH.aUp)}*tau*tau;
  float td = tau - B.z;
  return B.y - 0.5*${f6(SWH.aDn)}*td*td;
}
float boreVel(int i, float t){
  vec4 A=uBoA[i]; vec4 B=uBoB[i];
  float tau = t - A.z;
  if (tau<=0.0 || tau>=B.w) return 0.0;
  if (tau<=B.z) return B.x - ${f6(SWH.aUp)}*tau;
  return -${f6(SWH.aDn)}*(tau - B.z);
}
float boreEnv(int i, vec2 p){
  float q=(p.x-uBoA[i].x)/uBoA[i].y; float q2=q*q; return exp(-(q2*q2));
}
// uprush arrival time at height z (for per-point foam age); valid z<=zPeak
float boreAge(int i, float z, float t){
  vec4 A=uBoA[i]; vec4 B=uBoB[i];
  float dz = max(z - ${f6(SWH.zBase)}, 0.0);
  float disc = max(B.x*B.x - 2.0*${f6(SWH.aUp)}*dz, 0.0);
  float tc = (B.x - sqrt(disc)) / ${f6(SWH.aUp)};
  return max(t - A.z - tc, 0.0);
}
`;

// ---------- renderer / scene ----------
let renderer;
try {
  renderer = new THREE.WebGLRenderer({antialias:true, powerPreference:'high-performance'});
} catch(e){
  const el=document.getElementById('err');
  el.style.display='flex';
  el.firstElementChild.textContent='webgl is unavailable in this browser — surf-js needs it to render the ocean.';
  throw e;
}
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.setClearColor(0xbfe0ee, 1);
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.08;
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFSoftShadowMap;
document.body.appendChild(renderer.domElement);

const scene = new THREE.Scene();
const FOGC = new THREE.Color(0xbfe0ee);
scene.fog = new THREE.FogExp2(FOGC, 0.00135);
const camera = new THREE.PerspectiveCamera(62, 1, 0.06, 3000);

// ---------- post-fx (EffectComposer chain, 3 hot-swappable tiers) ----------
const POST_VERT = `varying vec2 vUv;
void main(){ vUv = uv; gl_Position = projectionMatrix*modelViewMatrix*vec4(position,1.0); }`;
const GODRAY_SHADER = {
  uniforms: { tDiffuse:{value:null}, uSunPos:{value:new THREE.Vector2(0.5,0.7)}, uOn:{value:0} },
  vertexShader: POST_VERT,
  fragmentShader: `
  varying vec2 vUv; uniform sampler2D tDiffuse; uniform vec2 uSunPos; uniform float uOn;
  void main(){
    vec4 base = texture2D(tDiffuse, vUv);
    if (uOn <= 0.002){ gl_FragColor = base; return; }
    vec2 duv = (uSunPos - vUv)/24.0;
    vec2 uv = vUv;
    float shaft = 0.0, decay = 1.0;
    for (int i=0;i<24;i++){
      uv += duv;
      vec3 s = texture2D(tDiffuse, clamp(uv, 0.0, 1.0)).rgb;
      float l = max(dot(s, vec3(0.299,0.587,0.114)) - 1.05, 0.0);   // HDR-bright only
      shaft += min(l, 2.5)*decay;
      decay *= 0.94;
    }
    gl_FragColor = vec4(base.rgb + vec3(1.0,0.92,0.75)*shaft*0.018*uOn, base.a);
  }`,
};
const GRADE_SHADER = {
  uniforms: { tDiffuse:{value:null}, uT:{value:0}, uGrain:{value:0.045}, uCA:{value:1.35} },
  vertexShader: POST_VERT,
  fragmentShader: `
  varying vec2 vUv; uniform sampler2D tDiffuse;
  uniform float uT, uGrain, uCA;
  float gr(vec2 p){ return fract(sin(dot(p, vec2(127.1,311.7)))*43758.5453); }
  void main(){
    vec2 d = vUv - 0.5;
    float r2 = dot(d,d);
    vec2 off = d * r2 * 0.012 * uCA;          // subtle radial chromatic aberration
    vec3 col;
    col.r = texture2D(tDiffuse, vUv + off).r;
    col.g = texture2D(tDiffuse, vUv).g;
    col.b = texture2D(tDiffuse, vUv - off).b;
    float a = texture2D(tDiffuse, vUv).a;
    col += (gr(vUv*vec2(917.,547.) + fract(uT*13.77)) - 0.5) * uGrain;   // film grain
    col *= mix(0.74, 1.0, 1.0 - smoothstep(0.18, 0.62, r2));              // vignette
    gl_FragColor = vec4(col, a);
  }`,
};
const UW_SHADER = {
  uniforms: { tDiffuse:{value:null}, uT:{value:0}, uPx:{value:new THREE.Vector2(1/1280,1/720)} },
  vertexShader: POST_VERT,
  fragmentShader: `
  varying vec2 vUv; uniform sampler2D tDiffuse;
  uniform float uT; uniform vec2 uPx;
  float h1(vec2 p){ return fract(sin(dot(p, vec2(127.1,311.7)))*43758.5453); }
  float n1(vec2 p){ vec2 i=floor(p),f=fract(p); vec2 u=f*f*(3.-2.*f);
    return mix(mix(h1(i),h1(i+vec2(1,0)),u.x), mix(h1(i+vec2(0,1)),h1(i+vec2(1,1)),u.x), u.y); }
  void main(){
    vec2 uv = vUv + vec2(sin(vUv.y*22.0+uT*2.6), cos(vUv.x*19.0-uT*2.2))*0.0045;
    vec3 col = texture2D(tDiffuse, uv).rgb * 0.4;                 // soft focus blur
    col += texture2D(tDiffuse, uv + uPx*vec2( 2.2, 1.1)).rgb * 0.15;
    col += texture2D(tDiffuse, uv + uPx*vec2(-2.2, 1.1)).rgb * 0.15;
    col += texture2D(tDiffuse, uv + uPx*vec2( 1.1,-2.2)).rgb * 0.15;
    col += texture2D(tDiffuse, uv + uPx*vec2(-1.1,-2.2)).rgb * 0.15;
    float ca = abs(2.0*n1(uv*7.0 + vec2(uT*0.5, uT*0.33)) - 1.0)
             * abs(2.0*n1(uv*11.0 - vec2(uT*0.42, uT*0.27)) - 1.0);   // caustic shimmer
    ca = pow(1.0 - ca, 6.0);
    col = col*vec3(0.72, 0.93, 1.0)*0.92 + vec3(0.35,0.8,0.85)*ca*0.14;
    gl_FragColor = vec4(col, 1.0);
  }`,
};
let composer=null, renderPass=null, bloomPass=null, smaaPass=null,
    godPass=null, gradePass=null, uwPass=null, outPass=null;
const _sunNDC = new THREE.Vector3();
function rebuildPost(){
  if (composer){
    for (const p of [renderPass,godPass,bloomPass,outPass,uwPass,smaaPass,gradePass])
      if (p && p.dispose) p.dispose();
    composer.dispose();
    composer=renderPass=bloomPass=smaaPass=godPass=gradePass=uwPass=outPass=null;
  }
  if (quality==='low') return;
  composer = new EffectComposer(renderer);
  composer.setPixelRatio(renderer.getPixelRatio());
  composer.setSize(innerWidth, innerHeight);
  renderPass = new RenderPass(scene, camera);
  composer.addPass(renderPass);
  if (quality==='high'){
    godPass = new ShaderPass(GODRAY_SHADER);
    composer.addPass(godPass);
  }
  bloomPass = new UnrealBloomPass(new THREE.Vector2(innerWidth, innerHeight), 0.35, 0.4, 0.85);
  composer.addPass(bloomPass);
  outPass = new OutputPass();          // ACES + sRGB, kept out of the render targets
  composer.addPass(outPass);
  if (quality==='high'){
    uwPass = new ShaderPass(UW_SHADER);
    uwPass.enabled = false;
    composer.addPass(uwPass);
  }
  const pr = renderer.getPixelRatio();
  smaaPass = new SMAAPass(innerWidth*pr, innerHeight*pr);
  composer.addPass(smaaPass);
  if (quality==='high'){
    gradePass = new ShaderPass(GRADE_SHADER);
    composer.addPass(gradePass);
  }
}
function updatePost(dt){
  if (!composer) return;
  if (godPass){
    _sunNDC.copy(camera.position).addScaledVector(CFG.sunDir, 500).project(camera);
    const behind = _sunNDC.z > 1 || _sunNDC.z < -1;
    const off = Math.max(Math.abs(_sunNDC.x), Math.abs(_sunNDC.y));
    godPass.uniforms.uSunPos.value.set(_sunNDC.x*0.5+0.5, _sunNDC.y*0.5+0.5);
    godPass.uniforms.uOn.value = behind ? 0 : clamp(1.35-off, 0, 1);
  }
  if (gradePass){
    const red = SET.motion==='reduced';
    gradePass.uniforms.uT.value = red ? 3.7 : simT;   // frozen grain when reduced
    gradePass.uniforms.uGrain.value = red ? 0.012 : 0.045;
    gradePass.uniforms.uCA.value = red ? 0 : 1.35;
  }
  if (uwPass){
    uwPass.uniforms.uT.value = simT;
    uwPass.uniforms.uPx.value.set(1/(innerWidth*renderer.getPixelRatio()), 1/(innerHeight*renderer.getPixelRatio()));
  }
}
const REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;
const SET = { particles:'high', refl:true, motion: REDUCED ? 'reduced' : 'full' };

let quality = 'high';   // 'low' | 'med' | 'high' — gates post-fx, grids, particles, rig detail
function applyQuality(){
  const pr = quality==='high' ? Math.min(devicePixelRatio,2)
           : quality==='med'  ? Math.min(devicePixelRatio,1.5) : 1;
  renderer.setPixelRatio(pr);
  renderer.setSize(innerWidth, innerHeight);
  camera.aspect = innerWidth/innerHeight;
  camera.updateProjectionMatrix();
  if (composer){ composer.setPixelRatio(pr); composer.setSize(innerWidth, innerHeight); }
}
applyQuality();
rebuildPost();
addEventListener('resize', applyQuality);

// ---------- lights ----------
const sun = new THREE.DirectionalLight(0xfff1d6, 2.6);
sun.castShadow = true;
sun.shadow.mapSize.set(2048,2048);
sun.shadow.camera.near = 10; sun.shadow.camera.far = 500;
sun.shadow.camera.left=-70; sun.shadow.camera.right=70;
sun.shadow.camera.top=70; sun.shadow.camera.bottom=-70;
sun.shadow.bias = -0.0004;
scene.add(sun, sun.target);
const hemi = new THREE.HemisphereLight(0xbfe3f2, 0x8a7a5c, 0.85);
scene.add(hemi);
const menuKey = new THREE.DirectionalLight(0xe4f9ff, 2.2);
menuKey.position.set(-3,10,66); menuKey.target.position.set(0,7,58);
scene.add(menuKey,menuKey.target);
sun.layers.enable(1); hemi.layers.enable(1);   // layer 1 = planar-reflection subset
sun.layers.enable(2); hemi.layers.enable(2);   // layer 2 = under-surface prepass (lit!)

// ---------- sky dome ----------
const sky = new THREE.Mesh(
  new THREE.SphereGeometry(1600, 32, 16),
  new THREE.ShaderMaterial({
    side: THREE.BackSide, fog:false, depthWrite:false,
    uniforms: { uSun:{value:CFG.sunDir} },
    vertexShader: `
      varying vec3 vDir;
      void main(){ vDir = normalize(position);
        gl_Position = projectionMatrix*modelViewMatrix*vec4(position,1.0); }`,
    fragmentShader: `
      varying vec3 vDir; uniform vec3 uSun;
      void main(){
        float y = clamp(vDir.y, -0.12, 1.0);
        vec3 zen = vec3(0.13,0.38,0.68);
        vec3 hor = vec3(0.80,0.90,0.95);
        vec3 col = mix(hor, zen, pow(max(y,0.0), 0.62));
        float d = max(dot(vDir, uSun), 0.0);
        col += vec3(1.0,0.86,0.62) * (pow(d,180.0)*0.55 + pow(d,10.0)*0.10);
        col += vec3(1.0,0.97,0.9) * smoothstep(0.99935,0.99965,d) * 8.0;   // disc
        col += vec3(0.95,0.85,0.72) * pow(1.0-abs(vDir.y),7.0)*0.35;       // haze band
        gl_FragColor = vec4(col,1.0);
        #include <tonemapping_fragment>
        #include <colorspace_fragment>
      }`
  })
);
scene.add(sky);
sky.layers.enable(1);

// ---------- environment from the sky dome (pmrem for PBR, cube for water) ----------
const envScene = new THREE.Scene();
envScene.add(new THREE.Mesh(sky.geometry, sky.material));   // shares geo+mat, separate node
const pmrem = new THREE.PMREMGenerator(renderer);
scene.environment = pmrem.fromScene(envScene, 0, 0.1, 2500).texture;   // far must clear the 1600-radius dome
pmrem.dispose();
// r160: intensity is per-material; hemi light already carries ambient, keep IBL subtle
function setEnvIntensity(root, v){ root.traverse(o=>{ if(o.material && 'envMapIntensity' in o.material) o.material.envMapIntensity = v; }); }
const cubeRT = new THREE.WebGLCubeRenderTarget(256);
const cubeCam = new THREE.CubeCamera(0.5, 2500, cubeRT);
cubeCam.update(renderer, envScene);   // static sun -> render once

// ---------- wet sand: wetness buffer + planar reflection ----------
const WETRECT = {x0:-CFG.worldX, z0:-8, w:2*CFG.worldX, h:20, nx:256, nz:64};
const wetData = new Uint8Array(WETRECT.nx*WETRECT.nz);
const wetF    = new Float32Array(WETRECT.nx*WETRECT.nz);
const wetDry  = new Float32Array(WETRECT.nx*WETRECT.nz);
for (let i=0;i<wetDry.length;i++) wetDry[i] = rand(10,20);   // dry-out 10-20s
const wetTex = new THREE.DataTexture(wetData, WETRECT.nx, WETRECT.nz, THREE.RedFormat, THREE.UnsignedByteType);
wetTex.magFilter = THREE.LinearFilter; wetTex.minFilter = THREE.LinearFilter;
wetTex.needsUpdate = true;
const WETSAND = {
  acc: 0,
  update(dt, t){
    this.acc += dt;
    if (this.acc < 0.033) return;      // ~30Hz is plenty for drying sand
    const dt2 = Math.min(this.acc, 0.2); this.acc = 0;
    const {nx, nz, x0, z0, w, h} = WETRECT;
    for (let i=0;i<wetF.length;i++){
      const v = wetF[i] - dt2/wetDry[i];
      wetF[i] = v > 0 ? v : 0;
    }
    for (let j=0;j<nz;j++){
      const z = z0 + h*j/(nz-1);
      if (z < 0.55){                    // ambient waterline keeps the low band wet
        const row = j*nx;
        for (let i=0;i<nx;i++) if (wetF[row+i] < 0.9) wetF[row+i] = 0.9;
      }
    }
    for (const b of bores){
      const zF = boreFront(b, t);
      if (zF <= SWH.zBase+0.05) continue;
      const j1 = Math.min(Math.floor((zF - z0)/h*(nz-1)), nz-1);
      const iC = (b.xc - x0)/w*(nx-1), iR = Math.max(b.halfW*1.15/w*(nx-1), 2);
      const iA = Math.max(Math.floor(iC-iR),0), iB = Math.min(Math.ceil(iC+iR),nx-1);
      for (let j=0;j<=j1;j++){
        const row = j*nx;
        for (let i=iA;i<=iB;i++){
          const q = (i-iC)/(iR/1.15), q2=q*q;
          const e = Math.exp(-(q2*q2));
          if (e > wetF[row+i]) wetF[row+i] = e;
        }
      }
    }
    for (let i=0;i<wetF.length;i++) wetData[i] = (wetF[i]*255)|0;
    wetTex.needsUpdate = true;
  },
};
// planar reflection: 384px mirrored camera, sky + surfer + board only (layer 1)
const reflRT = new THREE.WebGLRenderTarget(384, 192);
const reflCam = new THREE.PerspectiveCamera();
reflCam.layers.set(1);
const REFL = {on:true, frame:0};
const _rv1=new THREE.Vector3(), _rv2=new THREE.Vector3(), _rv3=new THREE.Vector3();
const _reflVP = new THREE.Matrix4();
function updateReflection(){
  const sh = terrain.material.userData.sh;
  if (!REFL.on || !SET.refl){ if (sh) sh.uniforms.uReflOn.value = 0; return; }
  REFL.frame ^= 1; if (REFL.frame) return;
  camera.getWorldPosition(_rv1); _rv1.y = -_rv1.y;
  camera.getWorldDirection(_rv2); _rv2.y = -_rv2.y;
  _rv3.set(0,1,0).applyQuaternion(camera.quaternion); _rv3.y = -_rv3.y;
  reflCam.position.copy(_rv1);
  reflCam.up.copy(_rv3);
  reflCam.lookAt(_rv2.add(_rv1));
  reflCam.projectionMatrix.copy(camera.projectionMatrix);
  reflCam.projectionMatrixInverse.copy(camera.projectionMatrixInverse);
  reflCam.updateMatrixWorld(true);
  _reflVP.copy(reflCam.projectionMatrix).multiply(reflCam.matrixWorldInverse);
  if (sh){ sh.uniforms.uReflVP.value.copy(_reflVP); sh.uniforms.uReflOn.value = 1; }
  const sav = renderer.shadowMap.autoUpdate;
  renderer.shadowMap.autoUpdate = false;
  renderer.setRenderTarget(reflRT);
  renderer.clear();
  renderer.render(scene, reflCam);
  renderer.setRenderTarget(null);
  renderer.shadowMap.autoUpdate = sav;
}

// ---------- terrain (sand + seabed, positions baked in world space) ----------
function buildTerrain(){
  const NX=150, NZ=110, x0=-CFG.worldX, x1=CFG.worldX, z0=-780, z1=210;
  const pos=new Float32Array(NX*NZ*3), uv=new Float32Array(NX*NZ*2);
  let p=0,u=0;
  for(let j=0;j<NZ;j++){
    const z = z0 + (z1-z0)*(j/(NZ-1));
    for(let i=0;i<NX;i++){
      const x = x0 + (x1-x0)*(i/(NX-1));
      pos[p++]=x; pos[p++]=terrainY(x,z); pos[p++]=z;
      uv[u++]=i/(NX-1); uv[u++]=j/(NZ-1);
    }
  }
  const idx=new Uint32Array((NX-1)*(NZ-1)*6); let k=0;
  for(let j=0;j<NZ-1;j++)for(let i=0;i<NX-1;i++){
    const a=j*NX+i,b=a+1,c=a+NX,d=c+1;
    idx[k++]=a;idx[k++]=c;idx[k++]=b; idx[k++]=b;idx[k++]=c;idx[k++]=d;
  }
  const g=new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos,3));
  g.setAttribute('uv', new THREE.BufferAttribute(uv,2));
  g.setIndex(new THREE.BufferAttribute(idx,1));
  g.computeVertexNormals();
  const m=new THREE.MeshStandardMaterial({color:0xd9c69b, roughness:0.85, metalness:0});
  m.onBeforeCompile = (sh)=>{
    sh.uniforms.uT = {value:0};
    sh.uniforms.uWet = {value:wetTex};
    sh.uniforms.uWetRect = {value:new THREE.Vector4(WETRECT.x0, WETRECT.z0, 1/WETRECT.w, 1/WETRECT.h)};
    sh.uniforms.uReflTex = {value:reflRT.texture};
    sh.uniforms.uReflVP = {value:new THREE.Matrix4()};
    sh.uniforms.uReflOn = {value:0};
    m.userData.sh = sh;
    sh.vertexShader = 'varying vec3 vWpos;\n' + sh.vertexShader.replace(
      '#include <begin_vertex>', '#include <begin_vertex>\n vWpos = position;');
    sh.fragmentShader = `
      varying vec3 vWpos;
      uniform float uT; uniform sampler2D uWet; uniform vec4 uWetRect;
      uniform sampler2D uReflTex; uniform mat4 uReflVP; uniform float uReflOn;
      float ppHash(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
      float ppNoise(vec2 p){vec2 i=floor(p),f=fract(p);vec2 u=f*f*(3.-2.*f);
        return mix(mix(ppHash(i),ppHash(i+vec2(1.,0.)),u.x),mix(ppHash(i+vec2(0.,1.)),ppHash(i+vec2(1.,1.)),u.x),u.y);}
      ` + sh.fragmentShader.replace(
      '#include <color_fragment>', `#include <color_fragment>
      vec2 wetUV = vec2((vWpos.x-uWetRect.x)*uWetRect.z, (vWpos.z-uWetRect.y)*uWetRect.w);
      float wet = 0.0;
      if (wetUV.x>0.001 && wetUV.x<0.999 && wetUV.y>0.001 && wetUV.y<0.999)
        wet = texture2D(uWet, wetUV).r;
      diffuseColor.rgb *= mix(vec3(1.0), vec3(0.52,0.49,0.44), wet*0.8);
      float uw = 1.0 - smoothstep(-0.6,0.1,vWpos.y);
      diffuseColor.rgb = mix(diffuseColor.rgb, diffuseColor.rgb*vec3(0.55,0.78,0.74), uw);
      float sp = ppHash(floor(vWpos.xz*14.0));
      diffuseColor.rgb *= 0.95 + 0.09*sp;
      // two scrolling caustic layers on the shallow seabed, depth-faded
      float cfade = (1.0-smoothstep(-0.45,-0.05,vWpos.y)) * smoothstep(-6.0,-2.1,vWpos.y);
      if (cfade > 0.003){
        float c1 = abs(2.0*ppNoise(vWpos.xz*0.85 + vec2(uT*0.55, uT*0.31)) - 1.0);
        float c2 = abs(2.0*ppNoise(vWpos.xz*1.65 - vec2(uT*0.38, uT*0.47)) - 1.0);
        float caust = pow(max(1.0 - c1*c2, 0.0), 6.0);
        diffuseColor.rgb += vec3(0.30,0.50,0.46)*caust*cfade*0.55;
      }`
      ).replace(
      '#include <roughnessmap_fragment>', `#include <roughnessmap_fragment>
      roughnessFactor = mix(roughnessFactor, 0.15, wet);`   // 0.85 -> 0.15 in the wet band
      ).replace(
      '#include <opaque_fragment>', `
      if (uReflOn > 0.5 && wet > 0.03){
        vec3 NwR = normalize(normal);
        float ndv = clamp(dot(NwR, normalize(vViewPosition)), 0.0, 1.0);
        float fresW = 0.15 + 0.85*pow(1.0-ndv, 3.0);
        vec4 rp = uReflVP * vec4(vWpos, 1.0);
        if (rp.w > 0.0){
          vec2 ruv = rp.xy/rp.w*0.5+0.5;
          // mirror smeared by the sand's micro-relief
          ruv += (vec2(ppNoise(vWpos.xz*3.1), ppNoise(vWpos.xz*3.1+9.7))-0.5)*0.045;
          if (ruv.x>0.0 && ruv.x<1.0 && ruv.y>0.0 && ruv.y<1.0){
            vec3 rc = texture2D(uReflTex, ruv).rgb;
            outgoingLight = mix(outgoingLight, rc, wet*fresW*0.75);
          }
        }
      }
      #include <opaque_fragment>`);
  };
  const mesh=new THREE.Mesh(g,m);
  mesh.receiveShadow = true;
  mesh.layers.enable(2);          // layer 2 = under-water prepass (refraction + depth)
  scene.add(mesh);
  return mesh;
}
const terrain = buildTerrain();

// ---------- under-surface prepass: refraction color + scene depth ----------
const underRT = new THREE.WebGLRenderTarget(512, 256, {
  depthTexture: new THREE.DepthTexture(512, 256),
});
function sizeUnderRT(){
  const pr = renderer.getPixelRatio();
  underRT.setSize(Math.max((innerWidth*pr)>>1,64), Math.max((innerHeight*pr)>>1,64));
}
sizeUnderRT();
addEventListener('resize', sizeUnderRT);
function updateUnder(){
  if (quality==='low'){ WATER_UNI.uUnderOn.value = 0; return; }
  WATER_UNI.uUnderOn.value = 1;
  const pr = renderer.getPixelRatio();
  WATER_UNI.uScreen.value.set(1/(innerWidth*pr), 1/(innerHeight*pr));
  const mask = camera.layers.mask;
  camera.layers.set(2);
  const sav = renderer.shadowMap.autoUpdate;
  renderer.shadowMap.autoUpdate = false;
  renderer.setRenderTarget(underRT);
  renderer.render(scene, camera);
  renderer.setRenderTarget(null);
  renderer.shadowMap.autoUpdate = sav;
  camera.layers.mask = mask;
}

// ---------- props: palms + rocks ----------
function frondTexture(){
  const c=document.createElement('canvas'); c.width=128; c.height=64;
  const g=c.getContext('2d'); g.clearRect(0,0,128,64);
  g.fillStyle='#2e7d3e';
  g.beginPath(); g.moveTo(0,32);
  for(let i=0;i<=16;i++){const x=i/16*128, s=Math.sin(i/16*Math.PI);
    g.lineTo(x, 32 - s*26*(i%2?0.55:1));}
  for(let i=16;i>=0;i--){const x=i/16*128, s=Math.sin(i/16*Math.PI);
    g.lineTo(x, 32 + s*26*(i%2?0.55:1));}
  g.closePath(); g.fill();
  g.fillStyle='#3f9b50';
  g.fillRect(0,30,128,4);
  const t=new THREE.CanvasTexture(c); t.colorSpace=THREE.SRGBColorSpace; return t;
}
const palms=[];
function buildPalm(x,z){
  const grp=new THREE.Group();
  const h=rand(6.5,9), lean=rand(0.12,0.3), leanDir=rand(0,TAU);
  const segs=6, trunkMat=new THREE.MeshStandardMaterial({color:0x8a6a44, roughness:0.9});
  let px=0,py=0,pz=0;
  for(let i=0;i<segs;i++){
    const sh=h/segs;
    const m=new THREE.Mesh(new THREE.CylinderGeometry(0.14-(i*0.012),0.17-(i*0.012),sh,7),trunkMat);
    const bend=lean*(i/segs)**1.6*h;
    m.position.set(px+Math.cos(leanDir)*bend, py+sh/2, pz+Math.sin(leanDir)*bend);
    m.rotation.z=Math.cos(leanDir)*lean*(i/segs)*1.2;
    m.rotation.x=-Math.sin(leanDir)*lean*(i/segs)*1.2;
    m.castShadow=true; grp.add(m);
    py+=sh*0.98;
    if(i===segs-1){px+=Math.cos(leanDir)*bend; pz+=Math.sin(leanDir)*bend;}
  }
  const tex=frondTexture();
  const fm=new THREE.MeshStandardMaterial({map:tex,alphaTest:0.5,side:THREE.DoubleSide,roughness:0.85});
  const fronds=new THREE.Group();
  const n=9;
  for(let i=0;i<n;i++){
    const f=new THREE.Mesh(new THREE.PlaneGeometry(3.4,1.5,6,1),fm);
    const pp=f.geometry.attributes.position;
    for(let v=0;v<pp.count;v++){const vx=pp.getX(v); pp.setY(v,pp.getY(v)-((vx+1.7)**2)*0.075);}
    pp.needsUpdate=true; f.geometry.computeVertexNormals();
    f.position.set(0,0,0); f.rotation.y=i/n*TAU; f.rotation.z=-0.15;
    f.position.x=Math.cos(i/n*TAU)*0; f.castShadow=true;
    const holder=new THREE.Group(); holder.rotation.y=i/n*TAU;
    f.rotation.set(0,0,rand(-0.5,-0.2)); f.position.x=1.55;
    holder.add(f); fronds.add(holder);
  }
  fronds.position.set(px,py,pz);
  grp.add(fronds);
  grp.position.set(x,terrainY(x,z)-0.1,z);
  grp.userData.fronds=fronds; grp.userData.ph=rand(0,TAU);
  scene.add(grp); palms.push(grp);
}
[[-46,52],[-30,66],[18,58],[52,70],[86,55]].forEach(([x,z])=>buildPalm(x,z));

function buildRocks(){
  const mat=new THREE.MeshStandardMaterial({color:0x6b6f72,roughness:0.95});
  [[-120,14,2.2],[-128,8,1.4],[150,20,2.8],[158,12,1.6]].forEach(([x,z,s])=>{
    const g=new THREE.IcosahedronGeometry(s,1);
    const p=g.attributes.position;
    for(let i=0;i<p.count;i++){
      const v=new THREE.Vector3().fromBufferAttribute(p,i);
      v.multiplyScalar(0.85+0.3*Math.abs(Math.sin(v.x*3.1+v.y*2.3+v.z*4.7)));
      p.setXYZ(i,v.x,v.y*0.7,v.z);
    }
    g.computeVertexNormals();
    const m=new THREE.Mesh(g,mat);
    m.position.set(x,terrainY(x,z)+s*0.18,z);
    m.rotation.y=rand(0,TAU); m.castShadow=true; m.receiveShadow=true;
    m.layers.enable(2);
    scene.add(m);
  });
}
buildRocks();

// ---------- ocean ----------
const swU = {
  P: Array.from({length:CFG.maxSwells},()=>new THREE.Vector4(0,-9999,0,10)),
  Q: Array.from({length:CFG.maxSwells},()=>new THREE.Vector4(50,0,9999,-9999)),
  R: Array.from({length:CFG.maxSwells},()=>new THREE.Vector4(0,0,0,0)),
};
const boU = {
  A: Array.from({length:CFG.maxBores},()=>new THREE.Vector4(0,50,-1e4,0)),
  B: Array.from({length:CFG.maxBores},()=>new THREE.Vector4(0,SWH.zBase,0,0)),
};
// shared uniform *objects* — ocean + swash strip materials reference the same values
const WATER_UNI = {
  uTime:{value:0},
  uSwP:{value:swU.P}, uSwQ:{value:swU.Q}, uSwR:{value:swU.R},
  uBoA:{value:boU.A}, uBoB:{value:boU.B},
  uSun:{value:CFG.sunDir},
  uSkyH:{value:new THREE.Color(0xcde8f2)},
  uSkyZ:{value:new THREE.Color(0x2a6db8)},
  uDeep:{value:new THREE.Color(0x06263c)},
  uShal:{value:new THREE.Color(0x1e9a94)},
  uSand:{value:new THREE.Color(0x8a7455)},   // sediment turbidity tint
  uFogC:{value:FOGC}, uFogD:{value:scene.fog.density},
  uEnvCube:{value:cubeRT.texture},
  uUnderTex:{value:underRT.texture},
  uUnderDepth:{value:underRT.depthTexture},
  uUnderOn:{value:0},
  uScreen:{value:new THREE.Vector2(1/1280,1/720)},
  uCamNF:{value:new THREE.Vector2(camera.near, camera.far)},
};
const oceanUniforms = WATER_UNI;

const OCEAN_VERT = `
uniform float uTime;
varying vec3 vWorld; varying vec3 vN; varying float vViewZ;
${'PLACEHOLDER_SHARED'}
void main(){
  vec2 p = position.xz;
  float t = uTime;
  float h  = waterH(p, t);
  float e = 0.35;
  float hx = waterH(p+vec2(e,0.0), t);
  float hz = waterH(p+vec2(0.0,e), t);
  vN = normalize(vec3(-(hx-h)/e, 1.0, -(hz-h)/e));
  vec2 curl = lipCurl(p);
  vWorld = vec3(p.x, h + curl.x, p.y + curl.y);
  vec4 mv = modelViewMatrix * vec4(vWorld, 1.0);
  vViewZ = -mv.z;
  gl_Position = projectionMatrix * mv;
}`;

const OCEAN_FRAG = `
precision highp float;
uniform float uTime; uniform vec3 uSun;
uniform vec3 uSkyH, uSkyZ, uDeep, uShal, uFogC; uniform float uFogD;
uniform samplerCube uEnvCube;
uniform sampler2D uUnderTex; uniform sampler2D uUnderDepth;
uniform float uUnderOn; uniform vec2 uScreen; uniform vec2 uCamNF;
varying vec3 vWorld; varying vec3 vN; varying float vViewZ;
${'PLACEHOLDER_SHARED'}
float hash(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
float vnoise(vec2 p){vec2 i=floor(p),f=fract(p);vec2 u=f*f*(3.-2.*f);
  return mix(mix(hash(i),hash(i+vec2(1.,0.)),u.x),mix(hash(i+vec2(0.,1.)),hash(i+vec2(1.,1.)),u.x),u.y);}
float linDepth(float d){
  float zN=uCamNF.x, zF=uCamNF.y;
  return (2.0*zN*zF)/(zF+zN - (d*2.0-1.0)*(zF-zN));
}
void main(){
  vec3 N = normalize(vN);
  if(!gl_FrontFacing) N = -N;
  vec3 Nmac = N;               // macro normal, before ripple/foam perturbation
  vec3 V = normalize(cameraPosition - vWorld);
  float t = uTime;
  float depth = max(vWorld.y - terrainY(vWorld.xz), 0.0);

  // small ripple perturbation for sparkle
  float r1 = vnoise(vWorld.xz*1.7 + vec2(t*0.55, t*0.35));
  float r2 = vnoise(vWorld.xz*3.9 - vec2(t*0.42, t*0.6));
  N = normalize(N + vec3(r1-0.5, 0.0, r2-0.5)*0.16);

  // ---- foam + backlit lip accumulation ----
  float F = 0.0, lipSum = 0.0;
  for (int i=0;i<${CFG.maxSwells};i++){
    vec4 P=uSwP[i]; vec4 Q=uSwQ[i]; vec4 R=uSwR[i];
    float dz = vWorld.z - P.y;
    float q = (vWorld.x-P.x)/Q.x; float q2=q*q; float env = exp(-(q2*q2));
    float inner = sstep(-3.0, 2.5, min(vWorld.x-Q.z, Q.w-vWorld.x));
    float gp = dz/(1.2*P.w);                                            // squared by hand:
    float pocket = inner * Q.y * exp(-gp*gp);                           // whitewater pocket
    float trail  = inner * Q.y * exp(min(dz,0.0)/9.0) * step(dz,0.0) * exp(-R.z*0.05); // aging wake
    // feathering lip: faint along the shoulder, strong near the active peel edge(s)
    float eL = abs(vWorld.x-Q.z), eR = abs(vWorld.x-Q.w);
    float eD = min(eL + step(0.5,R.w)*1e5, eR + step(R.w,-0.5)*1e5);
    float gl2 = (dz-0.25)/0.55;
    float lip = (1.0-inner) * env * Q.y * exp(-gl2*gl2)
              * (0.30 + 0.70*exp(-eD*eD/144.0));
    lipSum += lip * (0.4 + 0.6*R.y) * R.x;
    F += (pocket*1.25 + trail*0.8 + lip*0.50) * R.x;   // foam stays secondary to shape
  }
  float wash = sstep(0.55, 0.05, depth) * (0.45+0.55*vnoise(vWorld.xz*0.8+vec2(t*0.3)));
  F += wash*0.85;
  F = clamp(F, 0.0, 1.0);
  float nz = vnoise(vWorld.xz*2.3 + vec2(t*0.35, -t*0.28));
  F *= sstep(0.30, 0.72, F + (nz-0.5)*0.75);

  // foam churns the surface: perturb the normal where it sits
  N = normalize(N + vec3(nz-0.5, 0.0, vnoise(vWorld.xz*3.1+13.7)-0.5)*F*0.55);

  // base color by depth (absorption)
  vec3 water = mix(uShal, uDeep, 1.0 - exp(-depth*0.5));

  // slope shading vs the sun — this is what makes swell faces READ as waves
  float ndl = clamp(dot(N, uSun), 0.0, 1.0);
  water *= 0.62 + 0.55*ndl;

  // ---- forming wall: the shore-facing face reads as SHAPE before any foam ----
  // Nmac.z > 0 = face leaning toward the beach; height gate skips ambient chop
  float wall = sstep(0.035, 0.24, Nmac.z) * sstep(0.18, 0.82, vWorld.y);
  vec3 wallCol = mix(uDeep*0.8, vec3(0.02,0.32,0.36), 0.55);
  water = mix(water, wallCol, wall*0.72);
  float vstreak = vnoise(vec2(vWorld.x*2.7, vWorld.y*1.3 + vWorld.z*0.22 - t*0.5));
  water *= 1.0 + wall*(vstreak-0.5)*0.42;          // water drawing up the face
  float crest = wall * sstep(0.42, 1.18, vWorld.y);
  water += vec3(0.05,0.45,0.40) * crest * 0.78;    // green-glass band under the lip

  // subsurface glow on backlit shoaling faces — boosted through the thin lip
  float toSun = max(dot(normalize(vWorld - cameraPosition), uSun), 0.0);
  float sss = pow(toSun, 4.0) * clamp(vWorld.y*1.4 + 0.15, 0.0, 1.0) * sstep(4.0, 1.2, depth);
  water += vec3(0.06, 0.55, 0.47) * sss * (1.25 + lipSum*2.4 + wall*1.2);

  // refraction of the seabed through the genuinely shallow band only
  if (uUnderOn > 0.5){
    vec2 suv = gl_FragCoord.xy * uScreen;
    float shal = sstep(1.5, 0.22, depth);
    vec2 ruv = clamp(suv + N.xz*0.055*shal, 0.001, 0.999);
    vec3 refr = texture2D(uUnderTex, ruv).rgb;
    water = mix(water, refr*vec3(0.70,0.92,0.92), shal*0.65);
  }

  // real-sky reflection via fresnel, tinted so the sea stays blue
  vec3 R = reflect(-V, N);
  R.y = abs(R.y);
  vec3 skyCol = textureCube(uEnvCube, R).rgb * vec3(0.52,0.70,0.98);
  float fres = 0.02 + 0.98*pow(1.0 - max(dot(N,V),0.0), 5.0);
  // Standing faces stay green/dark instead of reflecting so much sky that
  // their height disappears from the lineup camera.
  vec3 col = mix(water, skyCol, clamp(fres*(1.0-0.58*wall),0.0,0.55));

  vec3 foamCol = vec3(0.93,0.965,0.975)*(0.78+0.22*nz);
  col = mix(col, foamCol, F);

  // rim light on foam edges + sun glint (killed by foam)
  float rim = pow(1.0 - max(dot(N,V),0.0), 3.0);
  col += vec3(0.95,0.98,1.0) * rim * F * 0.38;
  float spec = pow(max(dot(R, uSun), 0.0), 620.0) * 2.4 * (1.0-F);
  col += vec3(1.0,0.93,0.8)*spec;

  // manual exp2 fog (eased so distant swell lines stay readable)
  float d = length(vWorld - cameraPosition);
  float fog = 1.0 - exp(-d*d*uFogD*uFogD*0.55);
  col = mix(col, uFogC, clamp(fog,0.0,1.0));

  float alpha = clamp(0.28 + depth*1.3, 0.0, 1.0);
  alpha = max(alpha, F*0.95);
  // depth-texture soft intersection fade (kills the hard seam at the sand)
  if (uUnderOn > 0.5){
    float zScene = linDepth(texture2D(uUnderDepth, gl_FragCoord.xy*uScreen).x);
    alpha *= sstep(0.0, 0.55, zScene - vViewZ);
  }
  alpha *= 1.0 - sstep(-8.0, -5.8, vWorld.z);   // hand over to the swash strip
  gl_FragColor = vec4(col, alpha);
  #include <tonemapping_fragment>
  #include <colorspace_fragment>
}`;

let oceanMesh = null;
function buildOceanGeometry(hi){
  // non-uniform grid: dense in the surf zone, sparse far out
  const xs=[], zs=[];
  const XN = hi?170:110, ZN = hi?190:120;
  for(let i=0;i<XN;i++){
    const u=i/(XN-1)*2-1;                     // -1..1
    xs.push(Math.sign(u)*Math.pow(Math.abs(u),1.5)*CFG.worldX);
  }
  for(let j=0;j<ZN;j++){
    const u=j/(ZN-1);                          // 0..1 from shore(-5.5) to deep(-780)
    const z = CFG.oceanZ[1] - Math.pow(u,1.7)*(CFG.oceanZ[1]-CFG.oceanZ[0]);
    zs.push(z);
  }
  const pos=new Float32Array(XN*ZN*3); let p=0;
  for(let j=0;j<ZN;j++)for(let i=0;i<XN;i++){pos[p++]=xs[i];pos[p++]=0;pos[p++]=zs[j];}
  const idx=new Uint32Array((XN-1)*(ZN-1)*6); let k=0;
  for(let j=0;j<ZN-1;j++)for(let i=0;i<XN-1;i++){
    const a=j*XN+i,b=a+1,c=a+XN,d=c+1;
    idx[k++]=a;idx[k++]=c;idx[k++]=b; idx[k++]=b;idx[k++]=c;idx[k++]=d;
  }
  const g=new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos,3));
  g.setIndex(new THREE.BufferAttribute(idx,1));
  g.boundingSphere = new THREE.Sphere(new THREE.Vector3(0,0,-350), 1200); // skip recompute w/ displaced verts
  return g;
}
function buildOcean(){
  if (oceanMesh){ oceanMesh.geometry.dispose(); oceanMesh.material.dispose(); scene.remove(oceanMesh); }
  const mat=new THREE.ShaderMaterial({
    uniforms: oceanUniforms,
    vertexShader: OCEAN_VERT.replace('PLACEHOLDER_SHARED', GLSL_SHARED),
    fragmentShader: OCEAN_FRAG.replace('PLACEHOLDER_SHARED', GLSL_SHARED),
    transparent:true, side:THREE.DoubleSide, fog:false,
  });
  oceanMesh = new THREE.Mesh(buildOceanGeometry(quality!=='low'), mat);
  oceanMesh.frustumCulled = false;
  oceanMesh.renderOrder = 1;
  scene.add(oceanMesh);
}
buildOcean();

// ---------- swash strip (dedicated hi-res shoreline sheet, z -8..+10) ----------
const SWASH_VERT = `
uniform float uTime;
varying vec3 vWorld; varying vec3 vN;
varying vec4 vSw;    // film, flowUp, flowDn, age (dominant bore)
varying vec2 vEx;    // rel (front dist of dominant bore), depth above sand
${'PLACEHOLDER_SHARED'}
float smax(float a, float b, float k){
  float h = clamp(0.5 + 0.5*(b-a)/k, 0.0, 1.0);
  return mix(a, b, h) + k*h*(1.0-h);
}
float sheetAt(vec2 p, float t, out float film, out float up, out float dn,
              out float age, out float rel){
  film=0.0; up=0.0; dn=0.0; age=0.0; rel=-1.0;
  float best=0.0;
  for (int i=0;i<${CFG.maxBores};i++){
    if (uBoA[i].w <= 0.001) continue;    // parity with JS: skip empty bore slots
    float zF = boreFront(i,t);
    float r = zF - p.y;
    if (r<=0.0) continue;
    float env = boreEnv(i,p);
    float body = pow(clamp(r/max(zF-(${f6(SWH.zBase)}),0.5),0.0,1.0),0.6);
    float f = (0.02 + ${f6(SWH.filmK)}*uBoA[i].w) * body * env;
    float v = boreVel(i,t)*env;
    up += max(v,0.0)*step(0.008,f); dn += max(-v,0.0)*step(0.008,f);
    if (f>best){ best=f; rel=r; age=boreAge(i,p.y,t); }
    film += f;
  }
  float chop = min(up,dn);
  float chopY = chop*0.075*sin(p.x*3.1+t*9.0)*sin(p.y*4.3-t*7.0);
  float sheetY = terrainY(p) + film + chopY;
  return smax(sheetY, waterH(p,t), 0.22);
}
void main(){
  vec2 p = position.xz;
  float t = uTime;
  float f0,u0,d0,a0,r0;
  float h  = sheetAt(p, t, f0,u0,d0,a0,r0);
  float e = 0.30;
  float f1,u1,d1,a1,r1;
  float hx = sheetAt(p+vec2(e,0.0), t, f1,u1,d1,a1,r1);
  float hz = sheetAt(p+vec2(0.0,e), t, f1,u1,d1,a1,r1);
  vN = normalize(vec3(-(hx-h)/e, 1.0, -(hz-h)/e));
  vWorld = vec3(p.x, h, p.y);
  vSw = vec4(f0,u0,d0,a0);
  vEx = vec2(r0, h - terrainY(p));
  gl_Position = projectionMatrix * modelViewMatrix * vec4(vWorld, 1.0);
}`;

const SWASH_FRAG = `
precision highp float;
uniform float uTime; uniform vec3 uSun;
uniform vec3 uSkyH, uSkyZ, uShal, uSand, uFogC; uniform float uFogD;
varying vec3 vWorld; varying vec3 vN;
varying vec4 vSw; varying vec2 vEx;
${'PLACEHOLDER_SHARED'}
float hash(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
vec2 hash2(vec2 p){return fract(sin(vec2(dot(p,vec2(127.1,311.7)),dot(p,vec2(269.5,183.3))))*43758.5453);}
float vnoise(vec2 p){vec2 i=floor(p),f=fract(p);vec2 u=f*f*(3.-2.*f);
  return mix(mix(hash(i),hash(i+vec2(1.,0.)),u.x),mix(hash(i+vec2(0.,1.)),hash(i+vec2(1.,1.)),u.x),u.y);}
float worley(vec2 p){
  vec2 i=floor(p), f=fract(p);
  float d=8.0;
  for(int y=-1;y<=1;y++)for(int x=-1;x<=1;x++){
    vec2 g=vec2(float(x),float(y));
    d=min(d, length(g+hash2(i+g)-f));
  }
  return d;
}
void main(){
  float t = uTime;
  float film = vSw.x, up = vSw.y, dn = vSw.z, age = vSw.w;
  float rel = vEx.x;
  // per-fragment depth — per-vertex depth patchworks on the coarse x grid
  float wDepth = max(vWorld.y - terrainY(vWorld.xz), 0.0);
  vec3 N = normalize(vN);
  vec3 V = normalize(cameraPosition - vWorld);

  // thin water over bright sand, teal as it deepens, + drain turbidity
  vec3 col = mix(uSand*1.45, uShal*0.95, clamp(wDepth*2.0,0.0,1.0));
  float ndl = clamp(dot(N, uSun), 0.0, 1.0);
  col *= 0.72 + 0.42*ndl;
  float turb = clamp(dn*0.6, 0.0, 1.0) * sstep(0.012, 0.09, film);
  col = mix(col, uSand, turb*0.62);

  // sky reflection (glancing sheet = mirror of the sky)
  vec3 R = reflect(-V, N);
  vec3 skyCol = mix(uSkyH, uSkyZ, clamp(R.y, 0.0, 1.0));
  float fres = 0.03 + 0.97*pow(1.0 - max(dot(N,V),0.0), 5.0);
  col = mix(col, skyCol, clamp(fres, 0.0, 0.7));

  // ---- lace foam: 2-octave voronoi web, advected with the sheet ----
  // stretched on uprush, streaked (z-elongated) on backwash
  float zsq = 1.0/(1.0 + 0.35*up + 0.85*dn);
  vec2 wuv = vec2(vWorld.x*0.80, (rel>0.0?rel:0.0)*3.2*zsq + vWorld.x*0.13);
  float W = 0.62*worley(wuv*1.25) + 0.38*worley(wuv*3.15+17.31);
  float dTime = 3.0 + 5.0*hash(vec2(floor(vWorld.x*0.41), 7.7));   // bubble decay 3-8s
  float th = 0.16 + 0.55*clamp(age/6.0, 0.0, 1.0);                 // holes grow w/ age
  float lace = sstep(th, th+0.26, W) * exp(-age/dTime);
  float sheetOn = sstep(0.008, 0.05, film);
  float F = lace * sheetOn * 0.9;
  // frothy leading edge of an uprushing bore
  F += exp(-pow(max(rel,0.0)/0.8, 2.0)) * sheetOn * clamp(up*0.5,0.0,1.0);
  // collision chop between backwash and the next uprush
  float chop = min(up,dn);
  F += chop * (0.4 + 0.6*vnoise(vWorld.xz*3.0 + vec2(t*1.7))) * 0.85;
  // wash foam where the open ocean is genuinely shallow (deep edge of the strip)
  float washN = vnoise(vWorld.xz*0.8+vec2(t*0.3));
  F += sstep(0.30, 0.04, wDepth) * sstep(-2.0, -4.5, vWorld.z)
     * sstep(0.45, 0.75, washN) * 0.55;
  F = clamp(F, 0.0, 1.0);
  vec3 foamCol = vec3(0.94,0.965,0.975)*(0.80+0.20*vnoise(wuv*6.0));
  col = mix(col, foamCol, F);

  // sun glint
  float spec = pow(max(dot(R, uSun), 0.0), 240.0) * 1.4 * (1.0-F);
  col += vec3(1.0,0.93,0.8)*spec;

  // fog
  float d = length(vWorld - cameraPosition);
  float fog = 1.0 - exp(-d*d*uFogD*uFogD);
  col = mix(col, uFogC, clamp(fog,0.0,1.0));

  // thin-film alpha: meniscus feather at the leading edge, watery body behind;
  // openW keeps the strip solid where it is genuinely the ocean surface
  float openW = max(sstep(0.06, 0.25, wDepth - film), sstep(-3.0, -5.0, vWorld.z));
  float meniscus = max(sstep(0.0, ${f6(SWH.feather)}, rel), openW);
  float alpha = clamp(0.10 + wDepth*2.4 + film*3.0, 0.0, 0.92);
  alpha = max(alpha*meniscus, F*0.95*max(meniscus,0.35));
  alpha *= max(sstep(0.002, 0.012, film), openW);
  gl_FragColor = vec4(col, alpha);
  #include <tonemapping_fragment>
  #include <colorspace_fragment>
}`;

let swashMesh = null;
function buildSwash(hi){
  if (swashMesh){ swashMesh.geometry.dispose(); swashMesh.material.dispose(); scene.remove(swashMesh); }
  const NX = hi?220:140, NZ = hi?64:40, z0=-8, z1=10;
  const pos=new Float32Array(NX*NZ*3); let p=0;
  for(let j=0;j<NZ;j++){
    const z = z0 + (z1-z0)*(j/(NZ-1));
    for(let i=0;i<NX;i++){
      const u=i/(NX-1)*2-1;
      pos[p++]=Math.sign(u)*Math.pow(Math.abs(u),1.35)*CFG.worldX; pos[p++]=0; pos[p++]=z;
    }
  }
  const idx=new Uint32Array((NX-1)*(NZ-1)*6); let k=0;
  for(let j=0;j<NZ-1;j++)for(let i=0;i<NX-1;i++){
    const a=j*NX+i,b=a+1,c=a+NX,d=c+1;
    idx[k++]=a;idx[k++]=c;idx[k++]=b; idx[k++]=b;idx[k++]=c;idx[k++]=d;
  }
  const g=new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos,3));
  g.setIndex(new THREE.BufferAttribute(idx,1));
  g.boundingSphere = new THREE.Sphere(new THREE.Vector3(0,0,1), 720);
  const mat=new THREE.ShaderMaterial({
    uniforms: WATER_UNI,
    vertexShader: SWASH_VERT.replace('PLACEHOLDER_SHARED', GLSL_SHARED),
    fragmentShader: SWASH_FRAG.replace('PLACEHOLDER_SHARED', GLSL_SHARED),
    transparent:true, side:THREE.DoubleSide, fog:false, depthWrite:false,
  });
  swashMesh = new THREE.Mesh(g, mat);
  swashMesh.frustumCulled = false;
  swashMesh.renderOrder = 2;
  scene.add(swashMesh);
}
buildSwash(true);

// ---------- gpu spray (one Points pool: crest spray, spit, splash, bursts) ----------
const SPRAY = (()=>{
  const MAXP = 2000;
  const pos0 = new Float32Array(MAXP*3);
  const vel  = new Float32Array(MAXP*3);
  const meta = new Float32Array(MAXP*4);     // birth, life, size, kind
  for (let i=0;i<MAXP;i++) meta[i*4] = -1e4;
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos0,3));
  g.setAttribute('aVel',     new THREE.BufferAttribute(vel,3));
  g.setAttribute('aMeta',    new THREE.BufferAttribute(meta,4));
  g.boundingSphere = new THREE.Sphere(new THREE.Vector3(0,0,-60), 900);
  const mat = new THREE.ShaderMaterial({
    uniforms: { uT:{value:0}, uPxScale:{value:800} },
    transparent:true, depthWrite:false,
    vertexShader: `
    attribute vec3 aVel; attribute vec4 aMeta;
    uniform float uT; uniform float uPxScale;
    varying float vA; varying float vKind;
    void main(){
      float age = uT - aMeta.x;
      float life = max(aMeta.y, 0.001);
      float on = step(0.0, age) * step(age, life);
      float k = aMeta.w;
      float grav = k>2.5 ? 2.2 : (k>1.5 ? 7.5 : 5.2);   // foam floats, droplets fall
      vec3 p = position + aVel*age + vec3(0.0, -0.5*grav*age*age, 0.0);
      float tt = age/life;
      vA = on * (1.0-tt) * (k>2.5 ? 0.55 : 0.85);
      vKind = k;
      vec4 mv = viewMatrix * vec4(p, 1.0);
      float sz = aMeta.z * (1.0 + tt*(k>0.5?2.6:1.4));
      gl_PointSize = clamp(uPxScale * sz / max(-mv.z, 0.5), 0.0, 64.0) * on;
      gl_Position = projectionMatrix * mv;
    }`,
    fragmentShader: `
    precision highp float;
    varying float vA; varying float vKind;
    void main(){
      vec2 d = gl_PointCoord - 0.5;
      float r = length(d);
      float a = (1.0 - smoothstep(0.12, 0.5, r)) * vA;
      if (a < 0.004) discard;
      vec3 col = vKind>1.5 ? vec3(0.97,0.99,1.0) : vec3(0.92,0.96,0.98);
      gl_FragColor = vec4(col, a);
      #include <tonemapping_fragment>
      #include <colorspace_fragment>
    }`,
  });
  const pts = new THREE.Points(g, mat);
  pts.frustumCulled = false;
  pts.renderOrder = 3;
  scene.add(pts);
  let head = 0, dirty = false;
  const budget = ()=> (quality==='high' ? 1 : quality==='med' ? 0.6 : 0.4)
                    * (SET.particles==='high' ? 1 : 0.45);
  // kinds: 0 crest spray, 1 spit, 2 splash droplets, 3 foam burst
  function emit(x,y,z, vx,vy,vz, spread, n, size, life, kind){
    if (SET.particles==='off') return;
    n = Math.max(1, (n*budget())|0);
    for (let i=0;i<n;i++){
      const j = head; head = (head+1)%MAXP;
      pos0[j*3]   = x + (Math.random()-0.5)*spread;
      pos0[j*3+1] = y + (Math.random()-0.5)*spread*0.5;
      pos0[j*3+2] = z + (Math.random()-0.5)*spread;
      vel[j*3]    = vx + (Math.random()-0.5)*Math.abs(vx*0.6+1.5);
      vel[j*3+1]  = vy + (Math.random()-0.5)*Math.abs(vy*0.5+1.0);
      vel[j*3+2]  = vz + (Math.random()-0.5)*Math.abs(vz*0.6+1.5);
      meta[j*4]   = simT + Math.random()*0.12;
      meta[j*4+1] = life*(0.6+Math.random()*0.8);
      meta[j*4+2] = size*(0.7+Math.random()*0.6);
      meta[j*4+3] = kind;
    }
    dirty = true;
  }
  function tick(){
    mat.uniforms.uT.value = simT;
    mat.uniforms.uPxScale.value = (innerHeight*renderer.getPixelRatio())/(2*Math.tan(camera.fov*Math.PI/360));
    if (dirty){
      g.attributes.position.needsUpdate = true;
      g.attributes.aVel.needsUpdate = true;
      g.attributes.aMeta.needsUpdate = true;
      dirty = false;
    }
  }
  return {emit, tick};
})();
// ambient crest spray blown off breaking waves (wind offshore = spray thrown back+up)
let sprayAcc = 0;
function updateCrestSpray(dt){
  sprayAcc += dt;
  if (sprayAcc < 0.07) return;
  const step = sprayAcc; sprayAcc = 0;
  for (const s of swells){
    if (!s.breaking || s.amp < 0.75 || s.wash < 0.6) continue;
    const n = clamp(Math.round(s.amp*2.2), 1, 6);
    if (s.dir<=0 && s.bL > s.xc - s.halfW*1.2){
      SPRAY.emit(s.bL-1.5, s.amp*1.05, s.zc+0.4, -1.2, s.amp*1.4, -s.c*0.35, 2.2, n, 0.16+s.amp*0.05, 1.1, 0);
    }
    if (s.dir>=0 && s.bR < s.xc + s.halfW*1.2){
      SPRAY.emit(s.bR+1.5, s.amp*1.05, s.zc+0.4, 1.2, s.amp*1.4, -s.c*0.35, 2.2, n, 0.16+s.amp*0.05, 1.1, 0);
    }
    // barreling: the thrown lip lands ahead of the face — explosion foam + periodic spit
    if (s.brl > 0.45){
      lipCurlAt(s.dir>=0 ? s.bR+2 : s.bL-2, s.zc, _lipOff);
      const zImp = s.zc + faceLen(s) + _lipOff.z;
      const xe = s.dir>=0 ? s.bR+1.2 : s.bL-1.2;
      SPRAY.emit(xe, 0.35, zImp, 0, 2.2+s.amp, 1.2, 2.6, Math.round(2+s.amp*2), 0.2, 1.3, 3);
      s.spitT -= step;
      if (s.spitT <= 0){
        s.spitT = rand(2.5,4);
        const dirX = s.dir>=0 ? 1 : -1;
        SPRAY.emit(xe + dirX*1.5, s.amp*0.5, s.zc+1.2, dirX*8, 1.2, s.c*0.5,
          1.4, 18, 0.19, 1.1, 1);
        if (Math.abs(player.pos.z - s.zc) < 60) SFX.spit();
      }
    }
  }
}
const _lipOff = {y:0, z:0};

// ---------- swell lifecycle + spawner ----------
function spawnSwell(size, xc, halfW, cls=1, dir=0, closeout=false){
  if (swells.length >= CFG.maxSwells) return null;
  const s = {
    xc, halfW,
    zc: CFG.spawnZ + rand(-15,0),
    amp0: size, amp: size*0.55,
    L: rand(18,26) + size*3.5,
    c: 5.2 + size*1.55,
    peelRate: closeout ? rand(26,40) : rand(4.5,8.5)*(1+size*0.14),
    bf: 0, wash: 1, brl: 0, tBrk: 0,
    x0: xc + rand(-0.25,0.25)*halfW, dir, cls, closeout,
    bL: 9999, bR: -9999, peelR: 0, spitT: rand(2.5,4),
    breaking:false, dying:false, dumped:false, boreDone:false,
  };
  swells.push(s); return s;
}
function spawnBoreFrom(s, k){
  if (s.boreDone && k < 1.5) return;   // dump bores may stack on the normal one
  s.boreDone = true;
  const ampB = clamp(s.amp*k*0.9, 0.06, 3.2);
  const arrive = Math.max((SWH.zBase - s.zc)/Math.max(s.c,1), 0);
  if (bores.length >= CFG.maxBores) bores.shift();
  bores.push(makeBore(s.xc, s.halfW*(0.9+0.3*s.bf), simT + arrive, ampB));
}
function updateSwells(dt,t){
  for (let i=swells.length-1;i>=0;i--){
    const s=swells[i];
    s.zc += s.c*dt;
    const depth = Math.max(CFG.seaLevel - terrainY(s.xc, s.zc), 0.25);
    const shoal = clamp(Math.pow(7.0/Math.max(depth,0.5), 0.33), 1, 2.35);
    if (!s.dying) s.amp = s.amp0*0.55*shoal;
    const ratio = s.amp/depth;
    s.bf = sstep(0.44, 0.85, ratio);
    s.brl = sstep(0.78, 1.06, ratio) * sstep(0.9, 1.6, s.amp) * (s.closeout?0.6:1);
    if (!s.breaking && s.bf > 0.06){
      s.breaking = true; s.bL = s.x0; s.bR = s.x0; s.tBrk = 0;
      SFX.breakBoom(distTo(s), s.cls);
    }
    if (s.breaking){
      s.tBrk += dt;
      const pr = s.peelRate*dt;
      s.bL -= (s.dir<=0 ? pr : pr*0.10);
      s.bR += (s.dir>=0 ? pr : pr*0.10);
      s.bL = Math.max(s.bL, s.xc - s.halfW*1.25);
      s.bR = Math.min(s.bR, s.xc + s.halfW*1.25);
      s.peelR = Math.max(s.bR - s.x0, s.x0 - s.bL, 0);   // compat, removed w/ physics 2.0
    }
    // shorebreak dump: survived (nearly) green to the inside -> the face collapses at once.
    // gate on time-since-breaking, not bf (bf saturates to 1 in shallow water by definition)
    if (!s.dumped && !s.dying && depth < 0.62 && s.amp > 0.42 && s.wash > 0.7
        && (!s.breaking || s.tBrk < 1.2)){
      s.dumped = true; s.bf = 1; s.peelRate = 34;
      if (!s.breaking){ s.breaking = true; s.bL = s.x0; s.bR = s.x0; s.tBrk = 0; }
      SFX.dump(s.cls, distTo(s));
      spawnBoreFrom(s, 1.7);
    }
    if (!s.dying && (depth < 0.55 || s.zc > -6)){ s.dying = true; spawnBoreFrom(s, 0.8+0.6*s.bf); }
    if (s.dying){ s.amp *= Math.exp(-dt*0.9); s.wash *= Math.exp(-dt*0.55); s.bf = Math.max(s.bf, 0.9); }
    if (s.amp < 0.09 || s.zc > 24) swells.splice(i,1);
  }
  // mirror swells + bores to shared uniforms
  for (let i=0;i<CFG.maxSwells;i++){
    const s=swells[i];
    if (s){ swU.P[i].set(s.xc, s.zc, s.amp, s.L); swU.Q[i].set(s.halfW, s.bf, s.bL, s.bR); swU.R[i].set(s.wash, s.brl, s.tBrk, s.dir); }
    else  { swU.P[i].set(0,-9999,0,10); swU.Q[i].set(50,0,9999,-9999); swU.R[i].set(0,0,0,0); }
  }
  for (let i=bores.length-1;i>=0;i--) if (t-bores[i].t0 >= bores[i].life) bores.splice(i,1);
  for (let i=0;i<CFG.maxBores;i++){
    const b=bores[i];
    if (b){ boU.A[i].set(b.xc,b.halfW,b.t0,b.amp); boU.B[i].set(b.v0,b.zPeak,b.tS,b.life); }
    else  { boU.A[i].set(0,50,-1e4,0); boU.B[i].set(0,SWH.zBase,0,0); }
  }
}
function distTo(s){ return Math.abs(player.pos.z - s.zc); }

let setTimer = 7, setQueue = 0, setGap = 0, setSize = 1.6, setCls = 1, setDir = 0, setClose = false;
let bombTimer = rand(CFG.bombEveryMin, CFG.bombEveryMax);
function pickClass(){
  let r=Math.random(), acc=0;
  for (let i=0;i<WCLS.length;i++){ acc+=WCLS[i].w; if (r<acc) return i; }
  return 1;
}
function updateSpawner(dt){
  bombTimer -= dt;
  if (setQueue > 0){
    setGap -= dt;
    if (setGap <= 0){
      spawnSwell(setSize*rand(0.85,1.15), rand(-90,90), rand(45,90)+setSize*8, setCls, setDir, setClose);
      setQueue--; setGap = rand(CFG.swellGapMin, CFG.swellGapMax);
    }
    return;
  }
  setTimer -= dt;
  if (setTimer <= 0){
    const bomb = bombTimer <= 0;
    setCls = bomb ? 4 : pickClass();
    const r = Math.random();
    setDir = r < 0.36 ? 1 : r < 0.72 ? -1 : 0;   // left / right / a-frame
    setClose = !bomb && Math.random() < 0.10;
    setQueue = bomb ? Math.floor(rand(2,4)) : Math.floor(rand(CFG.wavesPerSetMin, CFG.wavesPerSetMax+1));
    setSize = rand(WCLS[setCls].amp[0], WCLS[setCls].amp[1]);
    setGap = 0.01;
    setTimer = rand(CFG.setEveryMin, CFG.setEveryMax);
    if (bomb){ bombTimer = rand(CFG.bombEveryMin, CFG.bombEveryMax); SFX.rumble(); HUD.alert('BOMB SET — get out or get under'); }
    else HUD.alert(`set incoming — ${setQueue} wave${setQueue>1?'s':''}`);
  }
}

// ---------- breaking lip + barrel shells ----------
// The base ocean remains the authoritative physics heightfield. These pooled meshes
// add the overhanging surface a single-valued heightfield cannot represent.
const BARREL_VIS = (()=>{
  const NX=28, NV=18, pool=[];
  const idx=[];
  for(let i=0;i<NX;i++)for(let j=0;j<NV;j++){
    const a=i*(NV+1)+j,b=a+NV+1;
    idx.push(a,b,a+1,a+1,b,b+1);
  }
  function makeSlot(){
    const n=(NX+1)*(NV+1);
    const pos=new Float32Array(n*3), nor=new Float32Array(n*3), col=new Float32Array(n*3);
    for(let i=0;i<=NX;i++)for(let j=0;j<=NV;j++){
      const k=(i*(NV+1)+j)*3, v=j/NV;
      const foam=sstep(0.18,0.0,v)+sstep(0.82,1.0,v);
      col[k]=lerp(0.08,0.92,foam); col[k+1]=lerp(0.48,0.97,foam); col[k+2]=lerp(0.53,1.0,foam);
    }
    const g=new THREE.BufferGeometry();
    g.setAttribute('position',new THREE.BufferAttribute(pos,3).setUsage(THREE.DynamicDrawUsage));
    g.setAttribute('normal',new THREE.BufferAttribute(nor,3).setUsage(THREE.DynamicDrawUsage));
    g.setAttribute('color',new THREE.BufferAttribute(col,3)); g.setIndex(idx);
    g.boundingSphere=new THREE.Sphere(new THREE.Vector3(),180);
    const m=new THREE.MeshPhysicalMaterial({
      color:0xffffff,vertexColors:true,transparent:true,opacity:0.90,roughness:0.24,metalness:0,
      clearcoat:0.35,clearcoatRoughness:0.18,side:THREE.FrontSide,depthWrite:true,
    });
    const mesh=new THREE.Mesh(g,m); mesh.visible=false; mesh.frustumCulled=false; mesh.renderOrder=2;
    // The darker back face is what makes the shell read as a hollow room when
    // the camera and surfer enter it. It shares the animated geometry exactly.
    const innerMat=new THREE.MeshPhysicalMaterial({
      color:0x177783,vertexColors:true,transparent:true,opacity:0.68,roughness:0.34,metalness:0,
      clearcoat:0.18,clearcoatRoughness:0.28,side:THREE.BackSide,depthWrite:false,
      emissive:0x032b34,emissiveIntensity:0.38,
    });
    const inner=new THREE.Mesh(g,innerMat); inner.visible=false; inner.frustumCulled=false; inner.renderOrder=2;
    const lipPos=new Float32Array((NX+1)*3);
    const lipGeo=new THREE.BufferGeometry();
    lipGeo.setAttribute('position',new THREE.BufferAttribute(lipPos,3).setUsage(THREE.DynamicDrawUsage));
    lipGeo.boundingSphere=new THREE.Sphere(new THREE.Vector3(),180);
    const lip=new THREE.Line(lipGeo,new THREE.LineBasicMaterial({color:0xeaffff,transparent:true,opacity:0.92,depthWrite:false}));
    lip.visible=false; lip.frustumCulled=false; lip.renderOrder=3;
    const lipSpray=new THREE.Points(lipGeo,new THREE.PointsMaterial({
      color:0xf3ffff,size:0.13,sizeAttenuation:true,transparent:true,opacity:0.72,depthWrite:false,
    }));
    lipSpray.visible=false; lipSpray.frustumCulled=false; lipSpray.renderOrder=4;
    // A thin arc at the shoulder-side opening gives the barrel a readable
    // round mouth from both chase and side cameras.
    const mouthPos=new Float32Array((NV+1)*3);
    const mouthGeo=new THREE.BufferGeometry();
    mouthGeo.setAttribute('position',new THREE.BufferAttribute(mouthPos,3).setUsage(THREE.DynamicDrawUsage));
    mouthGeo.boundingSphere=new THREE.Sphere(new THREE.Vector3(),180);
    const mouth=new THREE.Line(mouthGeo,new THREE.LineBasicMaterial({
      color:0xa8ffff,transparent:true,opacity:0.62,depthWrite:false,
    }));
    mouth.visible=false; mouth.frustumCulled=false; mouth.renderOrder=4;
    scene.add(mesh,inner,lip,lipSpray,mouth);
    return {mesh,inner,pos,nor,lip,lipSpray,lipPos,mouth,mouthPos};
  }
  for(let i=0;i<CFG.maxSwells*2;i++) pool.push(makeSlot());
  function fill(slot,s,side,t){
    const edge=side>0?s.bR:s.bL;
    if (!Number.isFinite(edge) || Math.abs(edge)>2000) {
      slot.mesh.visible=false; slot.inner.visible=false; slot.lip.visible=false;
      slot.lipSpray.visible=false; slot.mouth.visible=false; return;
    }
    const span=pocketW(s)*0.95;
    let p=0;
    for(let i=0;i<=NX;i++){
      const u=i/NX, open=sstep(0.0,0.38,u);
      const x=edge+side*u*span;
      const q=(x-s.xc)/s.halfW,q2=q*q,envX=Math.exp(-(q2*q2));
      const crest=waterH(x,s.zc,t), ampX=s.amp*envX*s.wash;
      const base=crest-ampX;
      // A genuinely overhead C-section: tall enough to clear a standing rider,
      // with the curtain thrown shoreward into the scoring pocket.
      const ry=Math.max(ampX*(0.88+0.17*s.brl)*open,0.015);
      const rz=Math.max(ampX*(0.38+0.47*s.brl)*open,0.015);
      const cy=base+ampX*0.58*open;
      const cz=s.zc+ampX*(0.07+0.10*s.brl)*open;
      for(let j=0;j<=NV;j++){
        const v=j/NV, th=Math.PI*0.5-v*Math.PI;
        const cs=Math.cos(th),sn=Math.sin(th);
        slot.pos[p]=x;
        slot.pos[p+1]=cy+ry*sn;
        slot.pos[p+2]=cz+rz*cs;
        p+=3;
      }
      const bp=(i*(NV+1))*3, lp=i*3;
      slot.lipPos[lp]=slot.pos[bp]; slot.lipPos[lp+1]=slot.pos[bp+1]+0.035; slot.lipPos[lp+2]=slot.pos[bp+2];
    }
    for(let j=0;j<=NV;j++){
      const src=(NX*(NV+1)+j)*3,dst=j*3;
      slot.mouthPos[dst]=slot.pos[src];
      slot.mouthPos[dst+1]=slot.pos[src+1];
      slot.mouthPos[dst+2]=slot.pos[src+2];
    }
    // Allocation-free finite-difference normals capture both the cross-section
    // curl and the widening mouth along x.
    for(let i=0;i<=NX;i++)for(let j=0;j<=NV;j++){
      const im=Math.max(i-1,0),ip=Math.min(i+1,NX),jm=Math.max(j-1,0),jp=Math.min(j+1,NV);
      const a=(im*(NV+1)+j)*3,b=(ip*(NV+1)+j)*3;
      const c=(i*(NV+1)+jm)*3,d=(i*(NV+1)+jp)*3,k=(i*(NV+1)+j)*3;
      const ux=slot.pos[b]-slot.pos[a],uy=slot.pos[b+1]-slot.pos[a+1],uz=slot.pos[b+2]-slot.pos[a+2];
      const vx=slot.pos[d]-slot.pos[c],vy=slot.pos[d+1]-slot.pos[c+1],vz=slot.pos[d+2]-slot.pos[c+2];
      let nx=uy*vz-uz*vy,ny=uz*vx-ux*vz,nz=ux*vy-uy*vx;
      const il=1/Math.max(Math.hypot(nx,ny,nz),1e-6);nx*=il;ny*=il;nz*=il;
      slot.nor[k]=nx;slot.nor[k+1]=ny;slot.nor[k+2]=nz;
    }
    slot.mesh.material.opacity=0.48+0.38*s.brl;
    slot.inner.material.opacity=0.48+0.16*s.brl;
    slot.mouth.material.opacity=0.36+0.30*s.brl;
    slot.mesh.geometry.attributes.position.needsUpdate=true;
    slot.mesh.geometry.attributes.normal.needsUpdate=true;
    slot.lip.geometry.attributes.position.needsUpdate=true;
    slot.mouth.geometry.attributes.position.needsUpdate=true;
    slot.mesh.visible=true; slot.inner.visible=true; slot.lip.visible=true;
    slot.lipSpray.visible=true; slot.mouth.visible=true;
  }
  return {update(t){
    let n=0;
    for(const s of swells){
      if (!s.breaking || s.brl<0.14 || s.wash<0.38 || s.dying) continue;
      if (s.dir>=0) fill(pool[n++],s,1,t);
      if (s.dir<=0 && n<pool.length) fill(pool[n++],s,-1,t);
    }
    while(n<pool.length){
      pool[n].mesh.visible=false;pool[n].inner.visible=false;pool[n].lip.visible=false;
      pool[n].lipSpray.visible=false;pool[n].mouth.visible=false;n++;
    }
  }};
})();

// ---------- readable swell crests ----------
// A moving crest highlight gives the player an actual line to read from water
// level. It follows the same heightfield and lip displacement as the physics,
// so radar, takeoff timing, and the visible wave agree.
const CREST_VIS = (()=>{
  const NP=56,pool=[],curl={y:0,z:0};
  function makeSlot(){
    const pos=new Float32Array(NP*3),g=new THREE.BufferGeometry();
    g.setAttribute('position',new THREE.BufferAttribute(pos,3).setUsage(THREE.DynamicDrawUsage));
    g.boundingSphere=new THREE.Sphere(new THREE.Vector3(),240);
    const line=new THREE.Line(g,new THREE.LineBasicMaterial({
      color:0x76dce8,transparent:true,opacity:0.32,depthWrite:false,
    }));
    const beads=new THREE.Points(g,new THREE.PointsMaterial({
      color:0xeaffff,size:0.10,sizeAttenuation:true,transparent:true,opacity:0.55,depthWrite:false,
    }));
    line.visible=false;beads.visible=false;line.frustumCulled=false;beads.frustumCulled=false;
    line.renderOrder=3;beads.renderOrder=4;scene.add(line,beads);
    return {pos,g,line,beads};
  }
  for(let i=0;i<CFG.maxSwells;i++)pool.push(makeSlot());
  return {update(t){
    let n=0;
    for(const s of swells){
      if(s.wash<0.32||s.dying||n>=pool.length)continue;
      const slot=pool[n++],span=Math.min(s.halfW*1.08,165);
      for(let i=0;i<NP;i++){
        const x=s.xc+lerp(-span,span,i/(NP-1));
        lipCurlAt(x,s.zc,curl);
        const k=i*3;
        slot.pos[k]=x;slot.pos[k+1]=waterH(x,s.zc,t)+curl.y+0.045;slot.pos[k+2]=s.zc+curl.z;
      }
      slot.g.attributes.position.needsUpdate=true;
      slot.line.material.color.setHex(s.bf>0.55?0xcffcff:0x62d8e6);
      slot.line.material.opacity=clamp(0.20+0.48*s.bf,0.2,0.72)*s.wash;
      slot.beads.material.opacity=clamp((s.bf-0.32)*0.9,0,0.58)*s.wash;
      slot.line.visible=true;slot.beads.visible=s.bf>0.38;
    }
    while(n<pool.length){pool[n].line.visible=false;pool[n].beads.visible=false;n++;}
  }};
})();

// ---------- character rig 2.0: 24-bone skeleton + procedural SkinnedMesh ----------
// rider presets: same bone NAMES + limb lengths (pose/IK system untouched),
// different widths, suit radii, face + hair. selected on the start screen.
const RIDERS = {
  kai:  { key:'kai', skin:0xc98d63, suit:0x1a1d23, accent:0x19c1d4, hair:0x241a12,
          clavX:0.065, shX:0.168, hipX:0.098, shR:1.18, hipR:0.98, chestR:1.13, waistR:0.92,
          armR:1.12, legR:1.08, neckR:1.10, headX:1.02, headY:1.02,
          jawS:1.14, browS:1.12, noseS:1.08, hairStyle:'crop' },
  mara: { key:'mara', skin:0xb97a52, suit:0x2a1030, accent:0xff7d9e, hair:0x140d08,
          clavX:0.040, shX:0.116, hipX:0.116, shR:0.78, hipR:1.18, chestR:0.98, waistR:0.78,
          armR:0.88, legR:0.96, neckR:0.90, headX:0.96, headY:1.04,
          jawS:0.78, browS:0.62, noseS:0.92, hairStyle:'pony' },
};
let RIDER = RIDERS.kai;
const MAT = {
  skin: new THREE.MeshStandardMaterial({color:0xc98d63, roughness:0.65}),
  suit: new THREE.MeshStandardMaterial({color:0x1a1d23, roughness:0.82}),
  accent: new THREE.MeshStandardMaterial({color:0x19c1d4, roughness:0.6}),
  hair: new THREE.MeshStandardMaterial({color:0x241a12, roughness:0.9}),
  eye: new THREE.MeshStandardMaterial({color:0xf6f8f8, roughness:0.35}),
  pupil: new THREE.MeshStandardMaterial({color:0x14181c, roughness:0.4}),
};
// wetsuit seams + chest zip, painted in the shader over the skinned suit
MAT.suit.onBeforeCompile = (sh)=>{
  sh.vertexShader = 'varying vec3 vBind;\nvarying vec2 vSuitUv;\n' + sh.vertexShader.replace(
    '#include <begin_vertex>', '#include <begin_vertex>\n vBind = position; vSuitUv = uv;');
  sh.fragmentShader = `varying vec3 vBind;
varying vec2 vSuitUv;
float ppSuitH(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
float ppSuitN(vec2 p){vec2 i=floor(p),f=fract(p);vec2 u=f*f*(3.-2.*f);
  return mix(mix(ppSuitH(i),ppSuitH(i+vec2(1.,0.)),u.x),mix(ppSuitH(i+vec2(0.,1.)),ppSuitH(i+vec2(1.,1.)),u.x),u.y);}
` + sh.fragmentShader.replace(
    '#include <color_fragment>', `#include <color_fragment>
    float su = abs(fract(vSuitUv.x*2.0 + 0.25) - 0.5);            // panel seams on limb sides
    float seam = 1.0 - smoothstep(0.006, 0.022, su*0.5);
    float sy = min(abs(vBind.y-1.335), abs(vBind.y-0.975));       // yoke + waist seams
    seam = max(seam, 1.0 - smoothstep(0.004, 0.013, sy));
    diffuseColor.rgb *= 1.0 - seam*0.35;
    float zip = step(abs(vBind.x), 0.010) * step(0.02, vBind.z)
              * step(0.98, vBind.y) * step(vBind.y, 1.412);       // chest zip
    diffuseColor.rgb = mix(diffuseColor.rgb, vec3(0.10,0.62,0.70), zip*0.85);
    float pull = 1.0 - smoothstep(0.011, 0.028, length(vBind - vec3(0.0, 1.40, 0.115)));
    diffuseColor.rgb += vec3(0.55)*pull;`
    ).replace(
    '#include <normal_fragment_maps>', `#include <normal_fragment_maps>
    // neoprene weave: fine bind-space noise breaks up the smooth tube shading
    float npA = ppSuitN(vBind.xy*92.0 + vBind.z*41.0);
    float npB = ppSuitN(vBind.yz*118.0 + vBind.x*37.0);
    normal = normalize(normal + vec3(npA-0.5, npB-0.5, (npA+npB)*0.5-0.5)*0.09);`
    ).replace(
    '#include <roughnessmap_fragment>', `#include <roughnessmap_fragment>
    roughnessFactor = clamp(roughnessFactor + (ppSuitN(vBind.xy*64.0)-0.5)*0.10, 0.05, 1.0);`);
};
const J = {};           // joints by name (THREE.Bone)
const BONES = [];       // skeleton order
const BI = {};          // name -> skeleton index
const headMeshes = [];  // hidden in first person
const charRoot = new THREE.Group(); scene.add(charRoot);
function jbone(name,parent,x,y,z){
  const b=new THREE.Bone(); b.position.set(x,y,z);
  parent.add(b); J[name]=b; BI[name]=BONES.length; BONES.push(b);
  return b;
}
const CHARF = {eyeL:null, eyeR:null, nextBlink:rand(3,6), blinkT:0};
function buildSkeleton(pres){
  const pelvis = jbone('pelvis', charRoot, 0, 0.98, 0);
  const spine  = jbone('spine',  pelvis, 0, 0.11, 0);
  const spine2 = jbone('spine2', spine,  0, 0.11, 0);
  const chest  = jbone('chest',  spine2, 0, 0.10, 0);
  const neck   = jbone('neck',   chest,  0, 0.18, 0.01);
  const head   = jbone('head',   neck,   0, 0.06, 0);
  const hairR  = jbone('hairRoot', head, 0, 0.19, -0.035);
  jbone('hairTip', hairR, 0, 0.085, -0.05);
  if (pres.hairStyle==='pony') jbone('hairTip2', J.hairTip, 0, -0.055, -0.075);

  // face + skull ride the head bone (rigid); jaw/nose/ears break up the sphere
  const skull = new THREE.Mesh(new THREE.SphereGeometry(0.105,18,14), MAT.skin);
  skull.position.y=0.105; skull.scale.set(0.92*pres.headX,1.02*pres.headY,0.96); skull.castShadow=true;
  head.add(skull); headMeshes.push(skull);
  const jaw = new THREE.Mesh(new THREE.SphereGeometry(0.082,14,10), MAT.skin);
  jaw.position.set(0, 0.040, 0.030); jaw.scale.set(0.86*pres.jawS, 0.70, 0.95);
  head.add(jaw); headMeshes.push(jaw);
  const nose = new THREE.Mesh(new THREE.ConeGeometry(0.015,0.034,6), MAT.skin);
  nose.scale.setScalar(pres.noseS);
  nose.rotation.x = Math.PI/2; nose.position.set(0, 0.088, 0.102);
  head.add(nose); headMeshes.push(nose);
  for (const sd of [-1,1]){
    const ear = new THREE.Mesh(new THREE.SphereGeometry(0.026,8,6), MAT.skin);
    ear.position.set(0.096*sd, 0.105, 0.008); ear.scale.set(0.45,0.85,0.6);
    head.add(ear); headMeshes.push(ear);
    const eye = new THREE.Group(); eye.position.set(0.038*sd, 0.115, 0.082);
    const w = new THREE.Mesh(new THREE.SphereGeometry(0.017,10,8), MAT.eye);
    const pu = new THREE.Mesh(new THREE.SphereGeometry(0.0075,8,6), MAT.pupil);
    pu.position.z=0.012; eye.add(w,pu); head.add(eye); headMeshes.push(eye);
    if (sd<0) CHARF.eyeL=eye; else CHARF.eyeR=eye;
    const brow = new THREE.Mesh(new THREE.BoxGeometry(0.048,0.008,0.012), MAT.hair);
    brow.position.set(0.038*sd, 0.148, 0.086); brow.rotation.z = -0.12*sd;
    brow.scale.y = pres.browS;
    head.add(brow); headMeshes.push(brow);
  }
  const mouth = new THREE.Mesh(new THREE.BoxGeometry(0.036,0.006,0.01), MAT.pupil);
  mouth.position.set(0, 0.042, 0.094); head.add(mouth); headMeshes.push(mouth);
  // neck bridge under the jawline (the suit collar stops at the shoulders)
  const neckM = new THREE.Mesh(new THREE.CylinderGeometry(0.044,0.054,0.10,10), MAT.skin);
  neckM.position.y = 0.028; neckM.scale.x=neckM.scale.z=pres.neckR;
  neck.add(neckM); headMeshes.push(neckM);
  // spring-bone hair: cropped flaps, or a fuller cap + ponytail chain
  if (pres.hairStyle==='pony'){
    const cap = new THREE.Mesh(new THREE.SphereGeometry(0.108,14,8,0,TAU,0,1.7), MAT.hair);
    cap.position.set(0,-0.062,0.026); cap.scale.set(1.05,0.94,1.06);
    J.hairRoot.add(cap); headMeshes.push(cap);
    const tie = new THREE.Mesh(new THREE.SphereGeometry(0.030,8,6), MAT.accent);
    tie.position.set(0,0.005,-0.015); J.hairTip.add(tie); headMeshes.push(tie);
    const pony1 = new THREE.Mesh(new THREE.CapsuleGeometry(0.028,0.085,3,8), MAT.hair);
    pony1.position.set(0,-0.045,-0.035); pony1.rotation.x=0.55;
    J.hairTip.add(pony1); headMeshes.push(pony1);
    const pony2 = new THREE.Mesh(new THREE.CapsuleGeometry(0.019,0.10,3,8), MAT.hair);
    pony2.position.set(0,-0.06,-0.015); pony2.rotation.x=0.25;
    J.hairTip2.add(pony2); headMeshes.push(pony2);
  } else {
    const flapA = new THREE.Mesh(new THREE.SphereGeometry(0.105,14,8,0,TAU,0,1.5), MAT.hair);
    flapA.position.set(0,-0.065,0.028); flapA.scale.set(1.02,0.9,1.04);
    J.hairRoot.add(flapA); headMeshes.push(flapA);
    const flapB = new THREE.Mesh(new THREE.ConeGeometry(0.045,0.13,7), MAT.hair);
    flapB.position.set(0,0.02,-0.01); flapB.rotation.x=0.5;
    J.hairTip.add(flapB); headMeshes.push(flapB);
  }

  for (const side of [-1,1]){
    const S = side<0?'L':'R';
    const clav = jbone('clav'+S, chest, pres.clavX*side, 0.10, 0);
    const sh = jbone('shoulder'+S, clav, pres.shX*side, 0.02, 0);
    const el = jbone('elbow'+S, sh, 0, -0.30, 0);
    const wr = jbone('wrist'+S, el, 0, -0.27, 0);
    // mitt hand with a thumb
    const palm = new THREE.Mesh(new THREE.SphereGeometry(0.045,10,8), MAT.skin);
    palm.scale.set(1.0,1.35,0.55); palm.position.y=-0.048; palm.castShadow=true;
    wr.add(palm);
    const thumb = new THREE.Mesh(new THREE.CapsuleGeometry(0.015,0.034,3,6), MAT.skin);
    thumb.position.set(-0.036*side, -0.030, 0.012);
    thumb.rotation.z = 0.85*side; thumb.castShadow=true;
    wr.add(thumb);

    const hip = jbone('hip'+S, pelvis, pres.hipX*side, -0.02, 0);
    const kn = jbone('knee'+S, hip, 0, -0.42, 0);
    const an = jbone('ankle'+S, kn, 0, -0.40, 0);
    const toe = jbone('toe'+S, an, 0, -0.05, 0.115);
    const foot = new THREE.Mesh(new THREE.BoxGeometry(0.088,0.046,0.15), MAT.suit);
    foot.position.set(0,-0.022,0.025); foot.castShadow=true; an.add(foot);
    const toeCap = new THREE.Mesh(new THREE.BoxGeometry(0.082,0.038,0.085), MAT.suit);
    toeCap.position.set(0,-0.018,0.028); toeCap.castShadow=true; toe.add(toeCap);
  }
}
// skeleton + inverse bind matrices captured ONCE at rest per rig build (charRoot at
// origin during selection), so a mid-game suit rebuild binds correctly while posed
let SKEL = null;
const _BINDM = new THREE.Matrix4();
const _RIG_POS=new THREE.Vector3(),_RIG_QUAT=new THREE.Quaternion(),_RIG_SCALE=new THREE.Vector3();
function buildRig(key){
  RIDER = RIDERS[key] || RIDERS.kai;
  // The live menu poses and moves charRoot. Always capture skeleton inverses in
  // a clean identity rest frame, then restore the preview/game transform.
  _RIG_POS.copy(charRoot.position);_RIG_QUAT.copy(charRoot.quaternion);_RIG_SCALE.copy(charRoot.scale);
  charRoot.position.set(0,0,0);charRoot.quaternion.identity();charRoot.scale.set(1,1,1);
  charRoot.updateMatrixWorld(true);
  if (J.pelvis){
    const old = J.pelvis;
    charRoot.remove(old);
    old.traverse(o=>{ if (o.geometry) o.geometry.dispose(); });
  }
  BONES.length = 0;
  for (const k in J) delete J[k];
  for (const k in BI) delete BI[k];
  headMeshes.length = 0;
  buildSkeleton(RIDER);
  charRoot.updateMatrixWorld(true);
  SKEL = new THREE.Skeleton(BONES);
  MAT.skin.color.setHex(RIDER.skin);
  MAT.suit.color.setHex(RIDER.suit);
  MAT.accent.color.setHex(RIDER.accent);
  MAT.hair.color.setHex(RIDER.hair);
  buildSuit(quality!=='low');
  setEnvIntensity(charRoot, 0.55);
  charRoot.position.copy(_RIG_POS);charRoot.quaternion.copy(_RIG_QUAT);charRoot.scale.copy(_RIG_SCALE);
  charRoot.updateMatrixWorld(true);
}
function updateFace(dt){
  CHARF.nextBlink -= dt;
  if (CHARF.nextBlink <= 0){ CHARF.blinkT = 0.13; CHARF.nextBlink = rand(3,6); }
  CHARF.blinkT = Math.max(0, CHARF.blinkT - dt);
  const s = CHARF.blinkT > 0 ? 0.08 : 1;
  CHARF.eyeL.scale.y = s; CHARF.eyeR.scale.y = s;
}
// --- procedurally skinned wetsuit: lathed torso + limb tubes, bend loops, 2-bone weights ---
let suitMesh = null;
function buildSuit(hi){
  if (suitMesh){ suitMesh.geometry.dispose(); charRoot.remove(suitMesh); }
  const RS = hi ? 16 : 8;
  const shR=RIDER.shR, hipR=RIDER.hipR, chR=RIDER.chestR, waR=RIDER.waistR;
  const ax = 0.20*(RIDER.shX/0.145);   // arm tube center follows shoulder width
  const pos=[], uv=[], si=[], sw=[], idx=[];
  function ring(cx,cy,cz,r,zs,bA,bB,w,vv){
    const base = pos.length/3;
    for (let k=0;k<=RS;k++){
      const th = k/RS*TAU;
      pos.push(cx+Math.cos(th)*r, cy, cz+Math.sin(th)*r*zs);
      uv.push(k/RS, vv);
      si.push(bA,bB,0,0); sw.push(1-w,w,0,0);
    }
    return base;
  }
  function tube(rings, zs){
    let prev=null, vv=0;
    for (const rg of rings){
      const b = ring(rg[0],rg[1],rg[2],rg[3],zs,BI[rg[4]],BI[rg[5]!==undefined?rg[5]:rg[4]],rg[6]||0,vv);
      vv += 0.6;
      if (prev!==null) for (let k=0;k<RS;k++){
        const a=prev+k, b2=prev+k+1, c=b+k, d=b+k+1;
        idx.push(a,c,b2, b2,c,d);
      }
      prev=b;
    }
  }
  // torso lathe: glutes -> waist pinch -> chest -> shoulder taper -> collar
  tube([
    [0,0.820,0.000,0.062,'pelvis'],
    [0,0.845,0.000,0.128*hipR,'pelvis'],
    [0,0.905,0.000,0.137*hipR,'pelvis'],
    [0,0.975,0.000,0.133*hipR,'pelvis'],
    [0,1.055,0.005,0.118*waR,'pelvis','spine',0.5],
    [0,1.135,0.008,0.120*waR,'spine','spine2',0.5],
    [0,1.215,0.010,0.132*chR,'spine2','chest',0.45],
    [0,1.300,0.012,0.150*chR,'chest'],
    [0,1.365,0.010,0.152*chR,'chest'],
    [0,1.415,0.008,0.128*(0.5+0.5*shR),'chest'],
    [0,1.455,0.005,0.078,'chest','neck',0.35],
    [0,1.505,0.005,0.054,'chest','neck',0.75],
    [0,1.545,0.005,0.050,'neck'],
  ], 0.80);
  for (const side of [-1,1]){
    const S = side<0?'L':'R', x=ax*side;
    tube([   // arm: wrist -> forearm bulge -> elbow -> biceps -> deltoid (ascending y)
      [x,0.828,0,0.015,'wrist'+S],
      [x,0.852,0,0.034*RIDER.armR,'elbow'+S,'wrist'+S,0.85],
      [x,0.905,0,0.041*RIDER.armR,'elbow'+S,'wrist'+S,0.45],
      [x,0.950,0,0.043*RIDER.armR,'elbow'+S,'wrist'+S,0.25],
      [x,1.000,0,0.040*RIDER.armR,'elbow'+S],
      [x,1.075,0,0.045*RIDER.armR,'shoulder'+S,'elbow'+S,0.75],
      [x,1.120,0,0.048*RIDER.armR,'shoulder'+S,'elbow'+S,0.5],
      [x,1.165,0,0.052*shR*RIDER.armR,'shoulder'+S,'elbow'+S,0.3],
      [x,1.250,0,0.055*shR*RIDER.armR,'shoulder'+S],
      [x,1.330,0,0.057*shR*RIDER.armR,'shoulder'+S],
      [x,1.395,0,0.061*shR*RIDER.armR,'shoulder'+S],
      [x,1.438,0,0.058*shR*RIDER.armR,'shoulder'+S,'clav'+S,0.35],
      [x,1.468,0,0.020,'clav'+S],
    ], 1.0);
    const hx=RIDER.hipX*side;
    tube([   // leg: ankle -> calf bulge -> knee -> quads -> hip crease (ascending y)
      [hx,0.118,0,0.021,'ankle'+S],
      [hx,0.150,0,0.040*RIDER.legR,'knee'+S,'ankle'+S,0.85],
      [hx,0.230,0,0.052*RIDER.legR,'knee'+S,'ankle'+S,0.45],
      [hx,0.300,0,0.049*RIDER.legR,'knee'+S,'ankle'+S,0.25],
      [hx,0.370,0,0.052*RIDER.legR,'knee'+S],
      [hx,0.500,0,0.064*RIDER.legR,'hip'+S,'knee'+S,0.75],
      [hx,0.545,0,0.067*RIDER.legR,'hip'+S,'knee'+S,0.5],
      [hx,0.590,0,0.070*RIDER.legR,'hip'+S,'knee'+S,0.3],
      [hx,0.700,0,0.080*hipR*RIDER.legR,'hip'+S],
      [hx,0.880,0,0.090*hipR*RIDER.legR,'hip'+S],
      [hx,0.965,0,0.086*hipR*RIDER.legR,'pelvis','hip'+S,0.45],
    ], 1.0);
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position',   new THREE.Float32BufferAttribute(pos,3));
  g.setAttribute('uv',         new THREE.Float32BufferAttribute(uv,2));
  g.setAttribute('skinIndex',  new THREE.Uint16BufferAttribute(si,4));
  g.setAttribute('skinWeight', new THREE.Float32BufferAttribute(sw,4));
  g.setIndex(idx);
  g.computeVertexNormals();
  suitMesh = new THREE.SkinnedMesh(g, MAT.suit);
  suitMesh.castShadow = true;
  charRoot.add(suitMesh);
  suitMesh.bind(SKEL, _BINDM);
  charRoot.traverse(o=>o.layers.enable(1));
}
buildRig('kai');

// pose targets: each joint eases toward its target euler every frame
// (hair bones are spring-driven in animPost, not pose-driven)
const JNAMES = Object.keys(J).filter(n=>!n.startsWith('hair'));
const TGT = {}; JNAMES.forEach(n=>TGT[n]=new THREE.Euler());
const _q = new THREE.Quaternion();
function resetPose(){ for(const n of JNAMES) TGT[n].set(0,0,0); }
function setJ(n,x=0,y=0,z=0){ TGT[n].set(x,y,z); }
function addPoseMap(map,w){
  for(const k in map){const v=map[k],e=TGT[k];
    e.x+=v[0]*w; e.y+=(v[1]||0)*w; e.z+=(v[2]||0)*w;}
}
function easeJoints(dt, rate=11){
  const k = damp(rate, dt);
  for(const n of JNAMES){ _q.setFromEuler(TGT[n]); J[n].quaternion.slerp(_q, k); }
}

// ---- pose library ----
function poseWalk(phase, runF){
  const sw=Math.sin(phase), amp=0.55+0.4*runF;
  setJ('hipL', sw*amp); setJ('hipR', -sw*amp);
  setJ('kneeL', Math.max(0,-Math.sin(phase-0.55))*(0.8+0.7*runF));
  setJ('kneeR', Math.max(0,-Math.sin(phase+Math.PI-0.55))*(0.8+0.7*runF));
  setJ('ankleL', -sw*amp*0.35); setJ('ankleR', sw*amp*0.35);
  setJ('shoulderL', -sw*0.5*amp, 0, 0.06); setJ('shoulderR', sw*0.5*amp, 0, -0.06);
  setJ('elbowL', -0.3-runF*0.6); setJ('elbowR', -0.3-runF*0.6);
  setJ('pelvis', 0, sw*0.09, 0); setJ('spine', 0.05+runF*0.15, -sw*0.08, 0);
  setJ('neck', -0.05-runF*0.1);
}
function poseCarry(){
  // The left arm wraps over the deck; hard IK in animPost keeps the hand on the rail.
  setJ('clavL', 0.03, 0, 0.12);
  setJ('shoulderL', -0.10, 0.10, 0.34);
  setJ('elbowL', -0.72, 0, 0.06);
  TGT.chest.z += 0.035;
}
function poseWade(phase){
  const sw=Math.sin(phase);
  setJ('hipL', Math.max(0,sw)*1.15 - 0.15); setJ('hipR', Math.max(0,-sw)*1.15 - 0.15);
  setJ('kneeL', Math.max(0,sw)*1.2+0.1); TGT.kneeR.x = Math.max(0,-sw)*1.2+0.1;
  setJ('shoulderL',-0.25,0,0.85); setJ('shoulderR',-0.25,0,-0.85);
  setJ('elbowL',-0.5); setJ('elbowR',-0.5);
  setJ('spine',0.12);
}
function poseProne(paddleF, phase){
  // char root is pitched ~+80° by controller (chest down, head toward board nose)
  setJ('neck', -0.55); setJ('head', -0.18);
  setJ('chest', -0.12);
  setJ('hipL', 0.06, 0, -0.05); setJ('hipR', 0.06, 0, 0.05);
  setJ('ankleL', 0.7); setJ('ankleR', 0.7);
  if (paddleF > 0.02){
    const aL = phase, aR = phase + Math.PI;
    const armX = a => -1.5 - 1.15*Math.sin(a);
    const armZ = a => 0.30 + 0.18*Math.cos(a);
    setJ('shoulderL', armX(aL), 0,  armZ(aL));
    setJ('shoulderR', armX(aR), 0, -armZ(aR));
    setJ('elbowL', -0.25 - 0.55*Math.max(0,Math.cos(aL)));
    setJ('elbowR', -0.25 - 0.55*Math.max(0,Math.cos(aR)));
  } else {
    setJ('shoulderL', -2.15, 0, 0.4); setJ('shoulderR', -2.15, 0, -0.4);
    setJ('elbowL', -0.35); setJ('elbowR', -0.35);
  }
}
function poseDuck(tau){
  const bell = Math.exp(-(((tau-0.5)/0.28)**2));
  setJ('neck', 0.15+0.25*bell); setJ('chest', 0.18*bell);
  setJ('shoulderL', -2.9, 0, 0.25); setJ('shoulderR', -2.9, 0, -0.25);
  setJ('hipR', 0.5*bell); TGT.kneeR.x = 1.25*bell;
  setJ('hipL', 0.05); setJ('ankleL',0.7); setJ('ankleR',0.7);
}
function poseSit(t){
  // real straddle: thighs abducted over the rails, shins dangling in the water
  const sway = Math.sin(t*0.9)*0.05;
  const dgL = Math.sin(t*1.3)*0.09, dgR = Math.sin(t*1.3+1.9)*0.09;   // slow leg dangle
  setJ('hipL', -1.12+dgL*0.4,  0.15, -0.52);
  setJ('hipR', -1.12+dgR*0.4, -0.15,  0.52);
  TGT.kneeL.x = 1.28 + dgL; TGT.kneeR.x = 1.28 + dgR;
  setJ('ankleL', 0.35+dgL); setJ('ankleR', 0.35+dgR);
  setJ('shoulderL', -0.35, 0, 0.22); setJ('shoulderR', -0.35, 0, -0.22);
  setJ('elbowL', -0.55); setJ('elbowR', -0.55);
  setJ('spine', 0.14, 0, sway);           // relaxed forward hunch
  setJ('chest', 0.06, 0, sway*0.5);
  setJ('neck', -0.12, Math.sin(t*0.33)*0.45, 0);
}
const POSE_PUSHUP = {
  shoulderL:[-1.0,0,0.3], shoulderR:[-1.0,0,-0.3], elbowL:[-0.15], elbowR:[-0.15],
  chest:[-0.35], neck:[-0.5], hipL:[0.15,0,-0.05], hipR:[0.15,0,0.05],
  ankleL:[0.8], ankleR:[0.8],
};
const POSE_STANCE = {
  pelvis:[0.14,0.10,0],
  hipL:[-0.48,0,0.10], hipR:[0.28,0,-0.10], kneeL:[0.72], kneeR:[0.74],
  ankleL:[-0.08], ankleR:[-0.12],
  spine:[0.23,0.46,0], chest:[0.07,0.26,0], neck:[-0.11,0.34,0],
  shoulderL:[-0.42,0,1.18], shoulderR:[-0.42,0,-1.18],
  elbowL:[-0.48], elbowR:[-0.48],
};
function posePopup(tau){
  if (tau < 0.45){
    const w = tau/0.45;
    poseProne(0,0);
    // anticipation: gather + chest dip before the push
    TGT.chest.x += 0.22*Math.sin(Math.min(w*1.6,1)*Math.PI);
    // blend prone -> pushup
    for(const n of JNAMES){TGT[n].x*= (1-w); TGT[n].y*=(1-w); TGT[n].z*=(1-w);}
    addPoseMap(POSE_PUSHUP, w);
  } else {
    let w = (tau-0.45)/0.55;
    w += 0.16*Math.sin(w*Math.PI);        // follow-through overshoot into the stance
    addPoseMap(POSE_PUSHUP, Math.max(1-w,0));
    addPoseMap(POSE_STANCE, w);
  }
}
function poseSurf(carve, pumpF, t){
  addPoseMap(POSE_STANCE, 1);
  TGT.kneeL.x += 0.5*pumpF; TGT.kneeR.x += 0.5*pumpF;
  TGT.hipL.x  -= 0.25*pumpF; TGT.hipR.x += 0.1*pumpF;
  TGT.spine.z += carve*0.28; TGT.chest.z += carve*0.15;
  TGT.shoulderL.z += -carve*0.45; TGT.shoulderR.z += -carve*0.45;
  TGT.neck.z += -carve*0.2;
  TGT.spine.x += Math.sin(t*2.2)*0.02;
}
// ---------- animation 2.0: analytic IK, gait lock, springs, ragdoll ----------
const _ikA=new THREE.Vector3(), _ikB=new THREE.Vector3(), _ikC=new THREE.Vector3(),
      _ikD=new THREE.Vector3(), _ikT=new THREE.Vector3(), _ikP=new THREE.Vector3(),
      _ikAx=new THREE.Vector3(), _ikX=new THREE.Vector3(), _ikY=new THREE.Vector3(),
      _ikZ=new THREE.Vector3(), _ikQ1=new THREE.Quaternion(), _ikQ2=new THREE.Quaternion(),
      _ikM=new THREE.Matrix4();
// world quaternion aligning bone local -Y along dirW, local +Z toward poleW
function limbBasis(dirW, poleW, out){
  _ikY.copy(dirW).multiplyScalar(-1).normalize();
  _ikZ.copy(poleW).addScaledVector(_ikY, -poleW.dot(_ikY));
  if (_ikZ.lengthSq() < 1e-8) _ikZ.set(_ikY.y, _ikY.z, _ikY.x);
  _ikZ.normalize();
  _ikX.crossVectors(_ikY, _ikZ);
  _ikM.makeBasis(_ikX, _ikY, _ikZ);
  return out.setFromRotationMatrix(_ikM);
}
// analytic two-bone IK: writes upper+lower bone quaternions (parents must be current)
function solveLimbIK(upper, lower, targetW, poleW, L1, L2){
  upper.getWorldPosition(_ikA);
  _ikT.copy(targetW).sub(_ikA);
  let d = _ikT.length();
  d = clamp(d, Math.abs(L1-L2)+0.003, (L1+L2)*0.999);
  _ikT.normalize();
  const a1 = Math.acos(clamp((L1*L1 + d*d - L2*L2)/(2*L1*d), -1, 1));
  _ikAx.crossVectors(_ikT, _ikP.copy(poleW).normalize());
  if (_ikAx.lengthSq() < 1e-8) _ikAx.set(1,0,0);
  _ikAx.normalize();
  _ikB.copy(_ikT).applyAxisAngle(_ikAx, a1);             // upper segment dir, bent toward pole
  _ikC.copy(_ikA).addScaledVector(_ikB, L1);             // mid joint position
  _ikD.copy(_ikA).addScaledVector(_ikT, d).sub(_ikC).normalize();  // lower segment dir
  limbBasis(_ikB, poleW, _ikQ2);
  upper.parent.getWorldQuaternion(_ikQ1).invert();
  upper.quaternion.copy(_ikQ1).multiply(_ikQ2);
  limbBasis(_ikD, poleW, _ikQ1);
  _ikQ2.invert();                                        // upper world quat inverse
  lower.quaternion.copy(_ikQ2).multiply(_ikQ1);
}
const LIMB = {upperArm:0.30, foreArm:0.27, thigh:0.42, shin:0.40};
const GAIT = {
  lockL:new THREE.Vector3(), lockR:new THREE.Vector3(),
  stL:false, stR:false,
};
const LOOK = {yaw:0, pitch:0};
const SEC = {yawV:0, yawX:0, prevYaw:0, spdPrev:0, spdV:0, spdX:0};
const HAIRS = {x:0, vx:0, z:0, vz:0, prev:new THREE.Vector3(), inited:false};
const STROKE = {cL:0, cR:0};
// expanding water rings (paddle strokes, splashes) — fixed pool, zero alloc
const RINGS = (()=>{
  const N=14, pool=[];
  const geo = new THREE.RingGeometry(0.72, 1.0, 20);
  for (let i=0;i<N;i++){
    const m = new THREE.Mesh(geo, new THREE.MeshBasicMaterial(
      {color:0xdff2f5, transparent:true, opacity:0, depthWrite:false, side:THREE.DoubleSide}));
    m.rotation.x = -Math.PI/2; m.visible=false; m.renderOrder=3;
    m.userData = {born:-1, life:1.1};
    scene.add(m); pool.push(m);
  }
  let head=0;
  return {
    spawn(x,y,z,scale=1){
      const m = pool[head]; head=(head+1)%N;
      m.position.set(x,y+0.03,z);
      m.userData.born = simT; m.userData.life = 0.9+scale*0.3;
      m.visible = true;
    },
    tick(){
      for (const m of pool){
        if (!m.visible) continue;
        const a = (simT - m.userData.born)/m.userData.life;
        if (a>=1){ m.visible=false; continue; }
        const s = 0.25 + a*1.7;
        m.scale.setScalar(s);
        m.material.opacity = 0.42*(1-a);
      }
    },
  };
})();
// pre-ease additive layer: breathing, look-at steering, spring-lagged secondary motion
function animPre(dt, t){
  const P = player;
  // breathing (rate scales with exertion)
  const exert = P.state==='prone' && K.w ? 1 : clamp(P.spd/CFG.run, 0, 1);
  const br = Math.sin(t*(1.7+1.7*exert))*0.018*(1+exert*0.7);
  TGT.chest.x += br; TGT.clavL.z += br*0.4; TGT.clavR.z -= br*0.4;
  // secondary motion: spine + shoulders lag behind yaw rate and acceleration
  let yawRate = shortAngle(SEC.prevYaw, P.yaw)/Math.max(dt,1e-3);
  SEC.prevYaw = P.yaw;
  yawRate = clamp(yawRate, -6, 6);
  SEC.yawV += ((-yawRate*0.055) - SEC.yawX*26 - SEC.yawV*8.5)*dt;
  SEC.yawX = clamp(SEC.yawX + SEC.yawV*dt, -0.3, 0.3);
  TGT.spine2.y += SEC.yawX*0.55; TGT.chest.y += SEC.yawX*0.35;
  TGT.shoulderL.x += SEC.yawX*0.3; TGT.shoulderR.x -= SEC.yawX*0.3;
  const acc = clamp((P.spd - SEC.spdPrev)/Math.max(dt,1e-3), -14, 14);
  SEC.spdPrev = P.spd;
  SEC.spdV += ((-acc*0.006) - SEC.spdX*24 - SEC.spdV*8)*dt;
  SEC.spdX = clamp(SEC.spdX + SEC.spdV*dt, -0.2, 0.2);
  TGT.spine.x += SEC.spdX; TGT.neck.x += SEC.spdX*0.6;
  // head look-at target (applied post-ease via LOOK)
  let lx=0, ly=0, lz=1, want=false;
  if (P.state==='surf' || P.state==='popup'){
    lx=Math.sin(P.hYaw||0); ly=-0.18; lz=Math.cos(P.hYaw||0); want=true;
  } else if (P.state==='catch'){ lx=0; ly=-0.1; lz=1; want=true; }
  else if (P.state==='prone' || P.state==='sit'){
    let best=null, bd=1e9;
    for (const s of swells){ const dz=P.pos.z-s.zc; if (dz>3 && dz<bd && s.wash>0.7){bd=dz;best=s;} }
    if (best){ lx=best.xc-P.pos.x; ly=-0.02; lz=best.zc-P.pos.z; want=true; }
    else { lx=Math.sin(P.yaw); ly=0; lz=Math.cos(P.yaw); want=true; }
  } else if (P.state==='ground'){ lx=Math.sin(CAM.yaw); ly=-0.08; lz=Math.cos(CAM.yaw); want=true; }
  if (want){
    _v3.set(lx,ly,lz).normalize();
    // into character space (undo root yaw; stance/prone offsets approximated by chest)
    const cy = P.yaw + (P.state==='surf' ? -1.1 : 0);
    const sx = _v3.x*Math.cos(-cy) - _v3.z*Math.sin(-cy);
    const sz = _v3.x*Math.sin(-cy) + _v3.z*Math.cos(-cy);
    const wYaw = clamp(Math.atan2(sx, sz), -1.25, 1.25);
    const wPit = clamp(-Math.asin(clamp(_v3.y,-1,1)), -0.7, 0.9);
    LOOK.yaw += (wYaw - LOOK.yaw)*damp(6,dt);
    LOOK.pitch += (wPit - LOOK.pitch)*damp(6,dt);
    TGT.neck.y += LOOK.yaw*0.42; TGT.head.y = (TGT.head.y||0) + LOOK.yaw*0.58;
    TGT.neck.x += LOOK.pitch*0.5; TGT.head.x += LOOK.pitch*0.5;
  }
}
// post-ease layer: hard IK (feet plant, paddle strokes), spring hair
const _hw=new THREE.Vector3(), _hw2=new THREE.Vector3(), _pole=new THREE.Vector3();
function animPost(dt, t){
  const P = player;
  charRoot.updateMatrixWorld(true);
  const st = P.state;
  // ---- foot IK: plant on sand (ground) or deck (surf) ----
  if (st==='ground'){
    yawFwd(P.yaw,_fwd);
    const moving = P.spd > 0.15;
    for (const S of ['L','R']){
      const stance = !moving || (S==='L' ? Math.cos(P.phase) > 0.04 : Math.cos(P.phase) < -0.04);
      const lock = S==='L' ? GAIT.lockL : GAIT.lockR;
      const was = S==='L' ? GAIT.stL : GAIT.stR;
      if (stance && !was){        // heel strike: capture the plant point
        J['ankle'+S].getWorldPosition(lock);
        lock.addScaledVector(_fwd, P.spd*0.06);
        lock.y = terrainY(lock.x, lock.z) + 0.075;
      }
      if (S==='L') GAIT.stL = stance; else GAIT.stR = stance;
      if (stance){
        _pole.copy(_fwd).multiplyScalar(1.5); _pole.y += 0.4;
        solveLimbIK(J['hip'+S], J['knee'+S], lock, _pole, LIMB.thigh, LIMB.shin);
        // level the foot onto the surface, toes along travel
        const e=0.18;
        const gy=terrainY(lock.x,lock.z), gz=terrainY(lock.x,lock.z+e), gx=terrainY(lock.x+e,lock.z);
        _e.set(-Math.atan2(gz-gy,e)*0.8, P.yaw, Math.atan2(gx-gy,e)*0.5, 'YXZ');
        _ikQ2.setFromEuler(_e);
        J['knee'+S].getWorldQuaternion(_ikQ1).invert();
        J['ankle'+S].quaternion.copy(_ikQ1).multiply(_ikQ2);
      }
    }
    // Carrying hand stays planted on the outside deck while the gait moves beneath it.
    boardG.updateMatrixWorld(true);
    _hw.set(0.17*BOARD.wid, 0.060, -0.04).applyMatrix4(boardG.matrixWorld);
    _pole.set(-1.5, 0.35, 0.55).applyQuaternion(charRoot.quaternion);
    solveLimbIK(J.shoulderL, J.elbowL, _hw, _pole, LIMB.upperArm, LIMB.foreArm);
  } else if (st==='surf' || (st==='popup' && P.timer/0.5 > 0.82)){
    // feet locked to the deck: front (left) + back (right), zero slide
    boardG.updateMatrixWorld(true);
    _hw.set(-0.09, 0.09, 0.34*BOARD.stance).applyMatrix4(boardG.matrixWorld);
    _hw2.set(0.08, 0.09, -0.33*BOARD.stance).applyMatrix4(boardG.matrixWorld);
    // Both knees flex toward the toe-side rail, producing the compact diamond
    // silhouette of a real stance instead of bending invisibly along travel.
    _pole.set(0,0.55,1.25).applyQuaternion(charRoot.quaternion);
    solveLimbIK(J.hipL, J.kneeL, _hw, _pole, LIMB.thigh, LIMB.shin);
    solveLimbIK(J.hipR, J.kneeR, _hw2, _pole, LIMB.thigh, LIMB.shin);
    // Keep both soles parallel to the deck after leg IK. The character root is
    // side-on to the board, so its world rotation is also the desired foot heading.
    charRoot.getWorldQuaternion(_ikQ2);
    for(const S of ['L','R']){
      J['knee'+S].getWorldQuaternion(_ikQ1).invert();
      J['ankle'+S].quaternion.copy(_ikQ1).multiply(_ikQ2);
    }
  }
  // ---- paddle-stroke hand IK: strokes reach, dig, exit — splash + rings on entry ----
  if ((st==='prone' || st==='catch') && P.spd > -0.2){
    const padF = (st==='catch' || K.w) ? 1 : 0;
    if (padF > 0){
      boardG.updateMatrixWorld(true);
      for (const S of ['L','R']){
        const ph = P.phase + (S==='R' ? Math.PI : 0);
        const c = (ph % TAU)/TAU;
        const side = S==='L' ? -1 : 1;
        let bx = side*0.30, by, bz;
        if (c < 0.42){ const u=c/0.42; bz = 0.52 - u*0.95; by = -0.16; }          // dig + pull
        else if (c < 0.55){ const u=(c-0.42)/0.13; bz = -0.43 - u*0.05; by = -0.16 + u*0.42; }
        else { const u=(c-0.55)/0.45; bz = -0.48 + u*1.0; by = 0.26 - u*0.1; }    // recovery
        _hw.set(bx, by, bz).applyMatrix4(boardG.matrixWorld);
        _pole.set(side*2.0, -0.4, -0.6).applyQuaternion(boardG.quaternion);
        solveLimbIK(J['shoulder'+S], J['elbow'+S], _hw, _pole, LIMB.upperArm, LIMB.foreArm);
        const prev = S==='L' ? STROKE.cL : STROKE.cR;
        if (c < prev){    // wrapped: catch phase — hand enters the water
          const wy = waterH(_hw.x, _hw.z, t);
          SPRAY.emit(_hw.x, wy+0.05, _hw.z, side*0.4, 1.1, 0.3, 0.25, 4, 0.09, 0.5, 2);
          RINGS.spawn(_hw.x, wy, _hw.z, 0.8);
          SFX.plop();
        }
        if (S==='L') STROKE.cL = c; else STROKE.cR = c;
      }
    }
  }
  // ---- spring-bone hair: lags head motion, streams with speed ----
  J.head.getWorldPosition(_hw);
  if (!HAIRS.inited){ HAIRS.prev.copy(_hw); HAIRS.inited=true; }
  _hw2.copy(_hw).sub(HAIRS.prev).divideScalar(Math.max(dt,1e-3));
  HAIRS.prev.copy(_hw);
  J.head.getWorldQuaternion(_ikQ1).invert();
  _hw2.applyQuaternion(_ikQ1);                       // head-local velocity
  const tx = clamp(_hw2.z*0.10, -0.9, 0.9) + 0.15;   // stream back + slight rest droop
  const tz = clamp(-_hw2.x*0.10, -0.9, 0.9);
  HAIRS.vx += ((tx-HAIRS.x)*38 - HAIRS.vx*7.5)*dt;
  HAIRS.x  += HAIRS.vx*dt;
  HAIRS.vz += ((tz-HAIRS.z)*38 - HAIRS.vz*7.5)*dt;
  HAIRS.z  += HAIRS.vz*dt;
  J.hairRoot.rotation.set(HAIRS.x*0.45, 0, HAIRS.z*0.45);
  J.hairTip.rotation.set(HAIRS.x*0.85, 0, HAIRS.z*0.85);
  if (J.hairTip2) J.hairTip2.rotation.set(HAIRS.x*1.2, 0, HAIRS.z*1.2);   // ponytail whips furthest
  // ragdoll drives the rig after easing so IK wins over pose targets
  if (st==='wipe') RAG.apply(dt, t);
}
// ---------- wipeout ragdoll: verlet chains, per-limb drag + buoyancy ----------
const RAG = (()=>{
  const NAMES = ['head','chest','pelvis','elbowL','wristL','elbowR','wristR','kneeL','ankleL','kneeR','ankleR'];
  const NP = NAMES.length;
  const P=[], Q=[];
  for (let i=0;i<NP;i++){ P.push(new THREE.Vector3()); Q.push(new THREE.Vector3()); }
  const CONS = [
    [0,1,0.24],[1,2,0.33],[0,2,0.55],
    [1,3,0.31],[3,4,0.27],[1,5,0.31],[5,6,0.27],
    [2,7,0.42],[7,8,0.40],[2,9,0.42],[9,10,0.40],
    [3,2,0.50],[5,2,0.50],
  ];
  const DRAG = [3.0,2.1,2.1, 3.6,4.8, 3.6,4.8, 3.6,4.8, 3.6,4.8];
  let spin = 0;
  const _d=new THREE.Vector3(), _up=new THREE.Vector3(), _q=new THREE.Quaternion();
  function seed(vx,vy,vz,dt){
    charRoot.updateMatrixWorld(true);
    const h = clamp(dt||0.016, 1/120, 1/30);   // launch speed in m/s regardless of refresh rate
    for (let i=0;i<NP;i++){
      J[NAMES[i]].getWorldPosition(P[i]);
      Q[i].copy(P[i]);
      Q[i].x -= (vx + rand(-1.5,1.5))*h;
      Q[i].y -= (vy + rand(-0.5,2.5))*h;
      Q[i].z -= (vz + rand(-1.5,1.5))*h;
    }
    spin = rand(-3,3);
  }
  function step(dt, t){
    for (let i=0;i<NP;i++){
      const p=P[i], q=Q[i];
      const wy = waterH(p.x, p.z, t);
      const sub = clamp((wy - p.y)*2.2, 0, 1.4);
      const drag = Math.exp(-DRAG[i]*sub*dt*0.7);
      let vx=(p.x-q.x)*drag, vy=(p.y-q.y)*drag, vz=(p.z-q.z)*drag;
      const f = foamAt(p.x, p.z);
      vz += f*6.5*dt*dt*60;                    // whitewater churn shoreward
      vy += f*Math.sin(t*9+i*2.1)*3.5*dt*dt*60;
      q.copy(p);
      p.x += vx;
      p.y += vy + (-9.81 + 10.8*sub)*dt*dt;    // gravity vs buoyancy
      p.z += vz;
      const ter = terrainY(p.x, p.z) + 0.09;
      if (p.y < ter){ p.y = ter; q.y = p.y + vy*0.3; }
    }
    for (let it=0; it<3; it++){
      for (let ci=0; ci<CONS.length; ci++){
        const c = CONS[ci], a=P[c[0]], b=P[c[1]], rest=c[2];
        _d.copy(b).sub(a);
        const len = Math.max(_d.length(), 1e-5);
        const corr = (len-rest)/len*0.5;
        a.addScaledVector(_d, corr); b.addScaledVector(_d, -corr);
      }
    }
  }
  function apply(dt, t){
    // root follows the pelvis; torso axis from pelvis->chest
    _up.copy(P[1]).sub(P[2]).normalize();
    _q.setFromUnitVectors(_v3.set(0,1,0), _up);
    _e.set(0, spin*t%TAU, 0); _qc.setFromEuler(_e);
    _q.multiply(_qc);
    charRoot.quaternion.copy(_q);
    _v3.set(0,0.98,0).applyQuaternion(_q);
    charRoot.position.copy(P[2]).sub(_v3);
    player.pos.copy(P[2]);
    // spine flail from head-chest deviation
    _d.copy(P[0]).sub(P[1]).normalize();
    const bend = Math.acos(clamp(_d.dot(_up),-1,1));
    TGT.spine.x = bend*0.4; TGT.spine2.x = bend*0.3; TGT.neck.x = bend*0.3;
    charRoot.updateMatrixWorld(true);
    // limbs chase their verlet points
    yawFwd(player.yaw, _pole); _pole.y += 0.5;
    solveLimbIK(J.shoulderL, J.elbowL, P[4], _pole, LIMB.upperArm, LIMB.foreArm);
    solveLimbIK(J.shoulderR, J.elbowR, P[6], _pole, LIMB.upperArm, LIMB.foreArm);
    solveLimbIK(J.hipL, J.kneeL, P[8], _pole, LIMB.thigh, LIMB.shin);
    solveLimbIK(J.hipR, J.kneeR, P[10], _pole, LIMB.thigh, LIMB.shin);
  }
  return {seed, step, apply, P};
})();

// ---------- surfboard ----------
function penguinTexture(){
  const c=document.createElement('canvas'); c.width=c.height=128;
  const g=c.getContext('2d');
  g.clearRect(0,0,128,128);
  g.fillStyle='#101418'; g.beginPath(); g.ellipse(64,70,28,38,0,0,TAU); g.fill();
  g.fillStyle='#f4f7f8'; g.beginPath(); g.ellipse(64,76,19,28,0,0,TAU); g.fill();
  g.fillStyle='#101418'; g.beginPath(); g.arc(64,32,18,0,TAU); g.fill();
  g.fillStyle='#fff'; g.beginPath(); g.arc(57,29,5,0,TAU); g.arc(71,29,5,0,TAU); g.fill();
  g.fillStyle='#101418'; g.beginPath(); g.arc(58,30,2.2,0,TAU); g.arc(70,30,2.2,0,TAU); g.fill();
  g.fillStyle='#ff9d2e'; g.beginPath(); g.moveTo(58,36); g.lineTo(70,36); g.lineTo(64,44); g.closePath(); g.fill();
  g.fillStyle='#101418';
  g.beginPath(); g.ellipse(35,70,6,20,0.35,0,TAU); g.fill();
  g.beginPath(); g.ellipse(93,70,6,20,-0.35,0,TAU); g.fill();
  g.fillStyle='#ff9d2e'; g.beginPath(); g.ellipse(55,108,8,5,0,0,TAU); g.ellipse(73,108,8,5,0,0,TAU); g.fill();
  const t=new THREE.CanvasTexture(c); t.colorSpace=THREE.SRGBColorSpace; return t;
}
const boardG = new THREE.Group(); scene.add(boardG);
const PENG_TEX = penguinTexture();
// board quiver: geometry scales + a physics hook table the state machine reads.
// paddle: prone speed, catchK: fraction of wave celerity needed to catch,
// grip: rail hold, turn: yaw/carve rate, pump: pump gain, pearl: nose-dig risk,
// stance: foot spread on the deck
const BOARDS = {
  short: {key:'short', label:"5'10\" SHORT", paddle:0.95, catchK:0.38, grip:1.12, turn:1.18,
          pump:1.12, pearl:0.85, stance:0.92, len:0.86, wid:0.90, thick:0.15, fins:3,
          deck:0xf4f7f8, stripe:0x19c1d4},
  fun:   {key:'fun', label:"7'2\" FUNBOARD", paddle:1.0, catchK:0.34, grip:1.0, turn:1.0,
          pump:1.0, pearl:1.0, stance:1.0, len:1.0, wid:1.0, thick:0.16, fins:3,
          deck:0xf3efe6, stripe:0x19c1d4},
  long:  {key:'long', label:"9'1\" LOG", paddle:1.14, catchK:0.295, grip:0.82, turn:0.78,
          pump:0.85, pearl:1.35, stance:1.16, len:1.32, wid:1.14, thick:0.18, fins:1,
          deck:0xdcb887, stripe:0x6b4a2f},
};
let BOARD = BOARDS.fun;
function buildBoard(kind){
  BOARD = BOARDS[kind] || BOARDS.fun;
  const B = BOARD;
  for (let i=boardG.children.length-1;i>=0;i--){
    const c = boardG.children[i];
    boardG.remove(c);
    if (c.geometry) c.geometry.dispose();
    if (c.material) c.material.dispose();   // material.dispose() leaves PENG_TEX alone
  }
  const deck = new THREE.MeshStandardMaterial({
    color:B.deck,roughness:0.35,emissive:B.deck,emissiveIntensity:0.30,
  });
  const hull = new THREE.Mesh(new THREE.CapsuleGeometry(0.26*B.wid, 1.42*B.len, 6, 16), deck);
  hull.rotation.x = Math.PI/2;      // long axis -> Z, nose at +Z
  hull.scale.set(1,1,B.thick);      // local z is world-vertical after rotation
  hull.castShadow = true; boardG.add(hull);
  const stripe = new THREE.Mesh(new THREE.BoxGeometry(0.03,0.012,1.85*B.len),
    new THREE.MeshStandardMaterial({color:B.stripe,roughness:0.5,emissive:B.stripe,emissiveIntensity:0.32}));
  stripe.position.y = 0.26*B.wid*B.thick + 0.003; boardG.add(stripe);
  const decal = new THREE.Mesh(
    new THREE.PlaneGeometry(0.30,0.30),
    new THREE.MeshStandardMaterial({map:PENG_TEX, transparent:true, roughness:0.4}));
  decal.rotation.x = -Math.PI/2;
  decal.position.set(0, 0.26*B.wid*B.thick + 0.006, 0.52*B.len); boardG.add(decal);
  const finMat = new THREE.MeshStandardMaterial({color:0x22262c, roughness:0.5});
  const finSpots = B.fins===1 ? [[0,-0.80,1.55]] : [[-0.14,-0.62,1],[0.14,-0.62,1],[0,-0.78,1.15]];
  finSpots.forEach(([x,z,s])=>{
    const f = new THREE.Mesh(new THREE.BoxGeometry(0.015,0.12*s,0.15*s), finMat);
    f.position.set(x*B.wid, -0.075-0.02*(s-1), z*B.len); f.rotation.x = 0.35; boardG.add(f);
  });
  boardG.traverse(o=>o.layers.enable(1));
  setEnvIntensity(boardG, 0.55);   // fresh materials default to 1.0 — match the scene's IBL dim
}
buildBoard('fun');
// surfer + board show up in the wet-sand mirror
charRoot.traverse(o=>o.layers.enable(1));
boardG.traverse(o=>o.layers.enable(1));

// ---------- player controller ----------
const player = {
  state:'ground',
  pos: new THREE.Vector3(6, 0, 62),
  yaw: Math.PI,                 // facing the ocean
  spd: 0, phase: 0, timer: 0,
  push: new THREE.Vector3(),
  swell: null, pumpF: 0,
  duckCd: 0, invuln: 0,
  ride: {t:0, dist:0, vmax:0},
  stats: {rides:0, best:0},
  hYaw: 0, holdDown: 2.3, holdStaged: false, sitRip: 0, sitK: 0,
  // manual takeoff + momentum surfing
  vel: new THREE.Vector3(),
  padT: 0, catchSteep: 0, popGrade: '', pearlRisk: 0, air: false, popSpd: 1, vy: 0,
  onFace: 0, slide: 0, pumpT: -9, pumpY0: 0, lean: 0,
  tube: 0, tubeT: 0, floatT: 0, floated: false,
  trick: {dur:0, dh:0, peak:0, minDz:99},
  session: {waves:0, bestTube:0, bestFace:0},
};
let simT = 0;
const _fwd = new THREE.Vector3(), _v3 = new THREE.Vector3(), _v3b = new THREE.Vector3();
const _e = new THREE.Euler(), _qb = new THREE.Quaternion(), _qc = new THREE.Quaternion();
const yawFwd = (yaw,out)=>out.set(Math.sin(yaw),0,Math.cos(yaw));

function foamAt(x,z){
  let f=0;
  for (const s of swells){
    if (s.bR < s.bL) continue;
    const dz=z-s.zc;
    const inner = sstep(-3, 2.5, Math.min(x-s.bL, s.bR-x));
    const band = Math.exp(-(((dz-1.5)/4.5)**2));
    f = Math.max(f, s.bf*inner*band*s.wash);
  }
  return f;
}

function boardFollowSurface(dt, yaw, extraPitch=0, ySink=0){
  const t=simT, p=boardG.position;
  yawFwd(yaw,_fwd);
  const hC = waterH(p.x, p.z, t);
  const hN = waterH(p.x+_fwd.x*0.8, p.z+_fwd.z*0.8, t);
  const hT = waterH(p.x-_fwd.x*0.8, p.z-_fwd.z*0.8, t);
  const hL = waterH(p.x+_fwd.z*0.35, p.z-_fwd.x*0.35, t);
  const hR = waterH(p.x-_fwd.z*0.35, p.z+_fwd.x*0.35, t);
  p.y += ((hC + ySink) - p.y) * damp(9,dt);
  const pitch = -Math.atan2(hN-hT, 1.6) + extraPitch;
  const roll  =  Math.atan2(hL-hR, 0.7)*0.7;
  _e.set(pitch, yaw, roll, 'YXZ'); _qb.setFromEuler(_e);
  boardG.quaternion.slerp(_qb, damp(7,dt));
}
function placeCharOnBoard(off, pitch, yawAdj, roll, uprightMix=0){
  _v3.copy(off).applyQuaternion(boardG.quaternion).add(boardG.position);
  charRoot.position.copy(_v3);
  _qb.copy(boardG.quaternion);
  if (uprightMix>0){ _e.set(0, player.yaw, 0); _qc.setFromEuler(_e); _qb.slerp(_qc, uprightMix); }
  _e.set(pitch, yawAdj, roll); _qc.setFromEuler(_e);
  charRoot.quaternion.copy(_qb).multiply(_qc);
}
function carryBoard(dt){
  _e.set(0, player.yaw, 0); _qb.setFromEuler(_e);
  // Board plane is vertical, long axis is near-horizontal, and the upper rail is under the arm.
  const pitch = BOARD.len > 1.15 ? -0.035 : -0.085;
  const roll = BOARD.len > 1.15 ? 1.48 : 1.43;
  _v3.set(-0.265, 0.99, 0.04).applyQuaternion(_qb).add(charRoot.position);
  boardG.position.lerp(_v3, damp(14,dt));
  _e.set(pitch, player.yaw, roll, 'YXZ'); _qc.setFromEuler(_e);
  boardG.quaternion.slerp(_qc, damp(14,dt));
}
function endRide(reason){
  const r=player.ride;
  if (r.t >= 3){
    player.stats.rides++;
    if (r.t > player.stats.best) player.stats.best = r.t;
    HUD.stats();
    HUD.saveToast(r);
  }
  HUD.rideHud(false);
  HUD.barrel(null); SFX.setTube(false);
  player.tube = 0; player.tubeT = 0;
  player.swell = null;
}
// grade the takeoff by timing + face steepness at commit
function startPopup(){
  const P=player, age=P.timer, steep=P.catchSteep;
  P.pearlRisk=0; P.air=false; P.popSpd=1; P.vy=0;
  if (age < 0.30 || steep < 0.10){
    P.popGrade='EARLY — BOGGED'; P.popSpd=0.72;
  } else if (steep > 0.60 || age > 2.0){
    P.popGrade='LATE — AIRDROP'; P.air=true; P.vy=0.6;
    P.pearlRisk = clamp((0.4 + (steep-0.60)*1.1 + Math.max(age-2.0,0)*0.12)*BOARD.pearl, 0.3, 0.85);
  } else {
    P.popGrade='SWEET — CLEAN DROP'; P.popSpd=1.1;
  }
  HUD.grade(P.popGrade, P.popGrade[0]!=='S');
  P.state='popup'; P.timer=0; P.ride.t=0.01; HUD.rideHud(true);
}
// hand off to momentum surfing with the line the takeoff earned
function initSurf(s){
  const P=player;
  P.state='surf';
  P.hYaw = P.yaw;
  const v0 = s.c*(0.9 + 0.25*P.catchSteep) * P.popSpd;
  P.vel.set(Math.sin(P.yaw)*v0*0.8, 0, Math.max(Math.cos(P.yaw)*v0, s.c*0.85));
  P.lean=0; P.slide=0; P.pumpT=-9; P.tube=0; P.tubeT=0;
  P.floatT=0; P.floated=false;
  P.trick.dur=0; P.trick.dh=0; P.trick.peak=0; P.trick.minDz=99;
  P.onFace=1;
  P.session.waves++;
  P.session.bestFace = Math.max(P.session.bestFace, Math.round(s.amp*1.75*3.281));
  HUD.stats();
}
function startWipe(){
  const P=player;
  const s = P.swell;                 // capture before endRide clears it
  endRide('wipe');
  P.state='wipe'; P.timer=0;
  // consequence scales with the wave: overhead+ = 5-9s two-stage hold-downs
  const amp = (s && swells.includes(s)) ? s.amp : 0.8;
  P.holdDown = amp>2.2 ? rand(5,9) : amp>1.4 ? rand(3.2,5.2) : rand(1.8,3.0);
  P.holdStaged = false;
  yawFwd(P.yaw,_fwd);
  RAG.seed(_fwd.x*P.spd, 1.2+P.spd*0.2, _fwd.z*P.spd + 2.5, frameDt);
  SFX.wipe();
}

let frameDt = 1/60;
function updatePlayer(dt){
  frameDt = dt;
  const t = simT, P = player;
  P.duckCd = Math.max(0, P.duckCd-dt);
  P.invuln = Math.max(0, P.invuln-dt);
  // seat blend unwinds in EVERY non-sit state so it can't freeze through duck/wipe/ground
  if (P.state !== 'sit') P.sitK = Math.max(P.sitK - dt*3.0, 0);
  P.push.multiplyScalar(Math.exp(-dt*1.6));

  const depth = ()=>Math.max(waterH(P.pos.x,P.pos.z,t) - terrainY(P.pos.x,P.pos.z), 0);

  switch (P.state){

  case 'ground': {
    const d = depth();
    // camera-relative move
    const ix=(K.d?1:0)-(K.a?1:0), iz=(K.w?1:0)-(K.s?1:0);
    const moving = ix||iz;
    let sp = 0;
    if (moving){
      const my = CAM.yaw + Math.atan2(-ix, iz);
      P.yaw += shortAngle(P.yaw, my) * damp(10,dt);
      sp = (K.sh ? CFG.run : CFG.walk) * (1 - clamp(d,0,0.95)*0.62);
    }
    P.spd = lerp(P.spd, sp, damp(8,dt));
    yawFwd(P.yaw,_fwd);
    P.pos.addScaledVector(_fwd, P.spd*dt);
    // whitewater shove on the inside
    const f = foamAt(P.pos.x,P.pos.z);
    if (d>0.15 && f>0.1) P.pos.z += f*6.5*dt;
    // swash sheet: uprush shoves up the beach, backwash drags seaward
    if (P.pos.z > SWH.zBase){
      let push=0, filmH=0;
      for (const b of bores){
        const rel = boreFront(b,t) - P.pos.z;
        if (rel<=0) continue;
        const env = boreEnv(b,P.pos.x);
        filmH += (0.02+SWH.filmK*b.amp)*env;
        push  += boreVel(b,t)*env;
      }
      if (filmH>0.025){
        P.pos.z += push*1.35*dt*clamp(filmH*8,0,1);
        P.spd *= 1 - clamp(filmH*1.6,0,0.5)*dt*2.5;   // wading through the sheet
      }
    }
    P.pos.x = clamp(P.pos.x, -CFG.worldX+30, CFG.worldX-30);
    P.pos.z = clamp(P.pos.z, -240, 190);
    P.pos.y = terrainY(P.pos.x,P.pos.z);
    charRoot.position.copy(P.pos);
    _e.set(0,P.yaw,0); _qb.setFromEuler(_e);
    charRoot.quaternion.slerp(_qb, damp(12,dt));
    // anim
    resetPose();
    if (P.spd>0.15){
      P.phase += dt*(3.1+P.spd*1.55);
      if (d>0.3) poseWade(P.phase*0.7); else poseWalk(P.phase, clamp((P.spd-CFG.walk)/(CFG.run-CFG.walk),0,1));
    } else { setJ('chest', Math.sin(t*1.4)*0.02); setJ('shoulderL',0,0,0.05); setJ('shoulderR',0,0,-0.05); }
    poseCarry();
    carryBoard(dt);
    // prompts + transitions
    if (d>0.8){
      HUD.prompt('deep enough — <kbd>SPACE</kbd> hop on the board');
      if (K.sp){ K.sp=false;
        P.state='prone'; P.spd=0.4; P.phase=0;
        boardG.position.set(P.pos.x, waterH(P.pos.x,P.pos.z,t), P.pos.z);
        _e.set(0,P.yaw,0); boardG.quaternion.setFromEuler(_e);
        SFX.plop();
      }
    } else if (d>0.15) HUD.prompt('wade out — the board goes in past the shorebreak');
    else HUD.prompt('<kbd>W A S D</kbd> move &middot; <kbd>SHIFT</kbd> run &middot; head for the water');
    break;
  }

  case 'prone': {
    const d = depth();
    const turn=(K.a?1:0)-(K.d?1:0);
    P.yaw += turn*1.25*BOARD.turn*dt/(1+P.spd*0.35);
    const paddling = K.w?1:0;
    P.padT = paddling ? P.padT+dt : 0;
    const sprint = 1 + sstep(0.9, 2.2, P.padT)*0.18;      // steady strokes build to a sprint
    const target = paddling ? CFG.paddle*BOARD.paddle*sprint : (K.s ? -0.8 : 0);
    P.spd = lerp(P.spd, target, damp(paddling?1.6:1.1, dt));
    yawFwd(P.yaw,_fwd);
    // the face of a passing wave pulls the board down-slope (this is how you catch one)
    const eS = 0.55;
    const dhdz = (waterH(P.pos.x,P.pos.z+eS,t) - waterH(P.pos.x,P.pos.z-eS,t))/(2*eS);
    P.spd += (-9.81*dhdz*0.62) * Math.max(_fwd.z,0.1) * (dhdz<0?1:0.35) * dt;
    P.spd = clamp(P.spd, -1.2, 15);
    P.pos.addScaledVector(_fwd, P.spd*dt);
    P.pos.addScaledVector(P.push, dt);
    // whitewater
    const f = foamAt(P.pos.x,P.pos.z);
    if (f>0.08 && P.invuln<=0){
      P.push.z += f*9.5*dt*8;   // shoreward shove
      P.spd *= Math.exp(-f*dt*2.5);
      if (f>0.68 && Math.random() < dt*2.4){ startWipe(); break; }
    }
    boardG.position.x = P.pos.x; boardG.position.z = P.pos.z;
    boardFollowSurface(dt, P.yaw, -P.spd*0.03);
    P.pos.y = boardG.position.y;
    // blend back up from a seat (sitK>0 right after leaving the sit state)
    if (P.sitK > 0.001){
      const sk = sstep(0, 1, P.sitK);
      placeCharOnBoard(_v3b.set(0, lerp(0.10,-0.75,sk), lerp(-0.70,-0.10,sk)),
        1.40*(1-sk), 0, 0, 0.85*sk);
    } else placeCharOnBoard(_v3b.set(0,0.10,-0.70), 1.40, 0, 0);
    resetPose();
    if (paddling) P.phase += dt*4.4;
    poseProne(paddling, P.phase);   // stroke splashes come from the hand-IK layer
    // transitions
    if (K.sh && P.duckCd<=0 && P.sitK<0.3){ K.sh=false; P.state='duck'; P.timer=0; P.duckCd=2.2; SFX.duck(); break; }
    if (K.e){ K.e=false; if (Math.abs(P.spd)<0.9){ P.state='sit'; P.spd=0; break; } }
    // SPACE: jump straight to your feet off a face you're already planing on
    if (K.sp){ K.sp=false;
      let hit=null;
      for (const s of swells){
        if (s.wash<0.6 || s.amp<0.32 || s.dying) continue;
        const dzc=P.pos.z-s.zc, LfF=faceLen(s);
        if (dzc<-0.5 || dzc>LfF*1.6) continue;
        if (s.breaking && P.pos.x>s.bL-2 && P.pos.x<s.bR+2) continue;
        const q=(P.pos.x-s.xc)/s.halfW; if (q*q>2.0) continue;
        if (P.spd*_fwd.z >= s.c*(BOARD.catchK+0.02) && _fwd.z>0.35){ hit=s; break; }
      }
      if (hit){
        const dep = Math.max(CFG.seaLevel - terrainY(P.pos.x, hit.zc+1), 0.3);
        P.swell=hit; P.catchSteep=sstep(0.35, 1.0, hit.amp/dep);
        P.ride={t:0,dist:0,vmax:0}; P.timer=0.8;
        startPopup();
        break;
      }
    }
    if (d < 0.5){ P.state='ground'; P.pos.y=terrainY(P.pos.x,P.pos.z); break; }
    // MANUAL takeoff: be in the zone, pointed shoreward, and match the wave's speed
    if (P.invuln<=0 && _fwd.z > 0.45){
      for (const s of swells){
        if (s.wash < 0.6 || s.amp < 0.32 || s.dying) continue;
        const dzc = P.pos.z - s.zc;
        const LfF = faceLen(s);
        if (dzc < 0.2 || dzc > LfF*1.15) continue;             // must sit high on the front face
        if (s.breaking && P.pos.x > s.bL-2 && P.pos.x < s.bR+2) continue;  // whitewater
        const q=(P.pos.x-s.xc)/s.halfW; if (q*q > 1.44) continue;
        const vzB = P.spd*_fwd.z;
        const cw = s.c;
        // catchable anywhere on the face, however far out — fat faces just grade softer
        if (vzB >= cw*BOARD.catchK && vzB <= cw*1.4 && -dhdz > 0.03){
          const dep = Math.max(CFG.seaLevel - terrainY(P.pos.x, s.zc+1), 0.3);
          P.state='catch'; P.swell=s; P.timer=0;
          P.catchSteep = sstep(0.35, 1.0, s.amp/dep);
          P.ride={t:0,dist:0,vmax:0};
          HUD.alert('you\u2019re in \u2014 time the pop-up');
          break;
        }
      }
    }
    HUD.prompt('<kbd>W</kbd> paddle &middot; <kbd>A D</kbd> turn &middot; sprint shoreward as the face lifts you, then <kbd>SPACE</kbd> to jump to your feet &middot; <kbd>SHIFT</kbd> duck dive &middot; <kbd>E</kbd> sit');
    break;
  }

  case 'duck': {
    P.timer += dt;
    const tau = clamp(P.timer/1.45, 0, 1);
    const bellY = Math.exp(-(((tau-0.5)/0.26)**2));
    const pitchA = -0.62*Math.exp(-(((tau-0.28)/0.18)**2)) + 0.42*Math.exp(-(((tau-0.78)/0.16)**2));
    yawFwd(P.yaw,_fwd);
    P.pos.addScaledVector(_fwd, P.spd*dt);
    P.spd *= Math.exp(-dt*0.25);
    const f = foamAt(P.pos.x,P.pos.z);
    P.push.z += f*9.5*dt*8*0.12;   // mostly slips under
    boardG.position.x = P.pos.x; boardG.position.z = P.pos.z;
    boardFollowSurface(dt, P.yaw, pitchA, -1.18*bellY);
    P.pos.y = boardG.position.y;
    placeCharOnBoard(_v3b.set(0,0.10,-0.70), 1.40, 0, 0);
    resetPose(); poseDuck(tau);
    HUD.prompt('under the wave&hellip;');
    if (tau>=1){ P.state='prone'; }
    break;
  }

  case 'sit': {
    P.spd = lerp(P.spd, 0, damp(2,dt));
    const turn=(K.a?1:0)-(K.d?1:0);
    P.yaw += turn*0.9*dt;
    P.pos.addScaledVector(P.push, dt);
    const f = foamAt(P.pos.x,P.pos.z);
    if (f>0.3 && P.invuln<=0){ P.push.z += f*40*dt; P.state='prone'; break; }
    boardG.position.x = P.pos.x; boardG.position.z = P.pos.z;
    // blend prone -> seated so the root glides down instead of snapping ~0.9m
    P.sitK = Math.min(P.sitK + dt*2.2, 1);
    const sk = sstep(0, 1, P.sitK);
    // rider weight sinks the tail; the nose third rides visibly out of the water
    boardFollowSurface(dt, P.yaw, 0.20*sk, -0.14*sk);
    P.pos.y = boardG.position.y;
    // pelvis lands ON the deck (root origin is at the feet, pelvis bone at +0.98)
    placeCharOnBoard(_v3b.set(0, lerp(0.10,-0.75,sk), lerp(-0.70,-0.10,sk)),
      1.40*(1-sk), 0, 0, 0.85*sk);
    resetPose(); poseSit(t);
    // dangling feet kick small rings while the rider waits
    P.sitRip -= dt;
    if (P.sitRip <= 0){
      P.sitRip = rand(1.1, 1.9);
      const sd = Math.random()<0.5?-1:1;
      const rx = P.pos.x + Math.cos(P.yaw)*0.33*sd + Math.sin(P.yaw)*0.25;
      const rz = P.pos.z - Math.sin(P.yaw)*0.33*sd + Math.cos(P.yaw)*0.25;
      RINGS.spawn(rx, waterH(rx, rz, t), rz, 0.45);
    }
    if (K.w || K.sp || K.e){ K.sp=false; K.e=false; P.state='prone'; P.phase=0; break; }
    HUD.prompt('sitting in the lineup &middot; paddle for a wave when a set rolls in &middot; <kbd>W</kbd> lie down');
    break;
  }

  case 'catch': {
    const s = P.swell;
    if (!s || !swells.includes(s) || s.wash<0.5){ P.state='prone'; P.swell=null; break; }
    P.timer += dt;
    // angled takeoff: keep steering while gliding in
    const turn=(K.a?1:0)-(K.d?1:0);
    let ang = shortAngle(0, P.yaw) + turn*1.1*dt;
    P.yaw = clamp(ang, -0.95, 0.95);
    yawFwd(P.yaw,_fwd);
    // the wave carries you; drift toward the sweet spot high on the face
    const LfF = faceLen(s);
    const dzc = P.pos.z - s.zc;
    P.pos.z += (s.c + (LfF*0.34 - dzc)*1.25) * dt;
    P.pos.x += Math.sin(P.yaw)*s.c*0.4*dt;
    P.pos.addScaledVector(P.push, dt*0.3);
    P.spd = s.c;
    // live steepness: shoaling sharpens the face under you
    const dep = Math.max(CFG.seaLevel - terrainY(P.pos.x, s.zc+1), 0.3);
    P.catchSteep = sstep(0.35, 1.0, s.amp/dep);
    boardG.position.x = P.pos.x; boardG.position.z = P.pos.z;
    boardFollowSurface(dt, P.yaw, -0.14 - P.catchSteep*0.30);
    P.pos.y = boardG.position.y;
    if (CAM.mode==='tp' && !dragging) CAM.yaw += shortAngle(CAM.yaw, P.yaw)*damp(1.5,dt);
    placeCharOnBoard(_v3b.set(0,0.10,-0.70), 1.36, 0, 0);
    resetPose(); P.phase += dt*5.2; poseProne(1, P.phase);
    HUD.prompt('<kbd>SPACE</kbd> — POP UP (early bogs &middot; late pearls) &middot; <kbd>A D</kbd> set your line');
    if (K.sp){ K.sp=false; startPopup(); break; }
    if (P.timer > 3.4){
      if (P.catchSteep > 0.68){ HUD.grade('PEARLED — TOO DEEP', true); startWipe(); }
      else { P.state='prone'; P.swell=null; P.spd*=0.5; HUD.grade('MISSED IT', true); }
      break;
    }
    if (Math.max(waterH(P.pos.x,P.pos.z,t)-terrainY(P.pos.x,P.pos.z),0) < 0.8){ P.state='prone'; P.swell=null; }
    break;
  }

  case 'popup': {
    const s = P.swell;
    if (!s || !swells.includes(s)){ endRide('lost'); P.state='prone'; break; }
    P.timer += dt;
    const tau = clamp(P.timer/0.5, 0, 1);
    yawFwd(P.yaw,_fwd);
    P.pos.z += s.c*dt;
    P.pos.x += Math.sin(P.yaw)*s.c*0.4*dt;
    boardG.position.x = P.pos.x; boardG.position.z = P.pos.z;
    if (P.air){
      // airdrop: the board leaves the face and falls to it
      P.vy -= 9.81*dt;
      const faceY = waterH(P.pos.x, P.pos.z, t);
      boardG.position.y += P.vy*dt;
      if (boardG.position.y <= faceY){
        boardG.position.y = faceY; P.air = false; P.vy = 0;
        if (Math.random() < P.pearlRisk){
          HUD.grade('PEARLED', true);
          SPRAY.emit(P.pos.x, faceY+0.2, P.pos.z+0.6, 0,2.5,2.0, 0.8, 16, 0.14, 0.8, 2);
          startWipe(); break;
        }
        HUD.grade('MADE THE DROP');
        P.popSpd = 1.22;
        SPRAY.emit(P.pos.x, faceY+0.1, P.pos.z, 0,1.8,1.2, 0.7, 10, 0.12, 0.7, 2);
      }
      P.pos.y = boardG.position.y;
      _e.set(-0.32, P.yaw, 0, 'YXZ'); _qb.setFromEuler(_e);
      boardG.quaternion.slerp(_qb, damp(6,dt));
    } else {
      boardFollowSurface(dt, P.yaw, -0.12 - P.catchSteep*0.2);
      P.pos.y = boardG.position.y;
    }
    const k = sstep(0.15,0.9,tau);
    _v3b.set(0, lerp(0.10,-0.38,k), lerp(-0.70,-0.06,k));
    placeCharOnBoard(_v3b, 1.40*(1-k), -Math.PI*0.5*k, 0);
    resetPose(); posePopup(tau);
    trackRide(dt, s);
    if (tau>=1 && !P.air){ initSurf(s); SFX.pop(); }
    break;
  }

  case 'surf': {
    const s = P.swell;
    if (!s || !swells.includes(s) || s.wash<0.45){ endRide('faded'); P.state='prone'; break; }
    // ---- momentum surfing: velocity lives on the moving face ----
    const eS = 0.55;
    const dhdx = (waterH(P.pos.x+eS,P.pos.z,t) - waterH(P.pos.x-eS,P.pos.z,t))/(2*eS);
    const dhdz = (waterH(P.pos.x,P.pos.z+eS,t) - waterH(P.pos.x,P.pos.z-eS,t))/(2*eS);
    const LfF = faceLen(s);
    const dzc = P.pos.z - s.zc;
    const dzcRate = P.vel.z - s.c;         // + falling down the face, - climbing it
    // gravity accelerates down-slope
    P.vel.x += -9.81*dhdx*0.78*dt;
    P.vel.z += -9.81*dhdz*0.78*dt;
    // rail carve from lean, with a grip limit -> drift/slide when overpushed
    const leanIn = (K.d?1:0)-(K.a?1:0);
    P.lean = lerp(P.lean, leanIn, damp(5.5,dt));
    let spd = Math.max(P.vel.length(), 0.01);
    _v3.copy(P.vel).divideScalar(spd);
    const aLatWant = 9.81*Math.tan(clamp(P.lean,-1,1)*0.85*BOARD.turn);
    const grip = 9.81*1.5*BOARD.grip*(0.42 + 0.58*sstep(1.5,6.5,spd));
    const aLat = clamp(aLatWant, -grip, grip);
    const over = Math.abs(aLatWant) - grip;
    P.slide = lerp(P.slide, over>0 ? clamp(over/9.81,0,1) : 0, damp(over>0?9:3,dt));
    // D rails toward the rider's (and camera's) right: force is perp-LEFT of travel
    P.vel.x += -_v3.z*aLat*dt;
    P.vel.z +=  _v3.x*aLat*dt;
    if (P.slide>0.05){
      P.vel.multiplyScalar(Math.exp(-P.slide*1.15*dt));
      if (spd>3) SPRAY.emit(P.pos.x-_v3.x*0.8, P.pos.y+0.15, P.pos.z-_v3.z*0.8,
        -_v3.z*P.lean*3.5, 1.7, _v3.x*P.lean*3.5, 0.5, 2, 0.11, 0.55, 0);
    }
    // drag: base + bogging out on the flats + stall (sets up the tube)
    const bog = sstep(LfF*1.6, LfF*2.5, dzc);
    P.vel.multiplyScalar(Math.exp(-(0.085 + bog*0.55 + (K.s?1.2:0))*dt));
    // pump timing: compress (hold W) on the way down, release on the way up
    P.pumpF = lerp(P.pumpF, K.w?1:0, damp(8,dt));
    if (K.w && P.pumpT<-1){ P.pumpT=simT; P.pumpY0=dzcRate; }
    if (!K.w && P.pumpT>0){
      const held = simT-P.pumpT; P.pumpT=-9;
      if (held>0.12 && held<0.95 && P.pumpY0>0.15 && dzcRate<-0.15){
        P.vel.addScaledVector(_v3, (0.6 + s.amp*0.22)*BOARD.pump);   // clean pump
      } else if (held>0.05){
        P.vel.multiplyScalar(0.962);                        // mistimed: bleeds speed
      }
    }
    P.pos.x += P.vel.x*dt;
    P.pos.z += P.vel.z*dt;
    spd = P.vel.length(); P.spd = spd;
    // ---- trick arcs: grade turns by sharpness at their position on the face ----
    const h = Math.atan2(P.vel.x, P.vel.z);
    const rate = shortAngle(P.hYaw, h)/Math.max(dt,1e-3);
    P.hYaw = h;
    const T = P.trick;
    if (Math.abs(rate) > 1.05){
      T.dur += dt; T.dh += rate*dt;
      T.peak = Math.max(T.peak, Math.abs(rate));
      T.minDz = Math.min(T.minDz, dzc);
    } else if (T.dur > 0){
      if (T.dur > 0.34 && Math.abs(T.dh) > 0.55){
        let name = Math.abs(T.dh) > 2.1 ? 'CUTBACK'
                 : T.minDz < LfF*0.5 ? (T.peak > 2.4 ? 'SNAP' : 'TOP TURN') : 'BOTTOM TURN';
        const g = clamp(T.peak*0.28 + spd*0.06, 0.5, 3);
        HUD.grade(name + (g>2.2?' !!!':g>1.4?' !!':' !'));
        SPRAY.emit(P.pos.x-_v3.x, P.pos.y+0.2, P.pos.z-_v3.z,
          _v3.z*Math.sign(T.dh)*spd*0.5, 2.2+g, -_v3.x*Math.sign(T.dh)*spd*0.5,
          0.8, Math.round(5+g*8), 0.13, 0.7, 0);
        SFX.sprayHiss(g);
      }
      T.dur=0; T.dh=0; T.peak=0; T.minDz=99;
    }
    // ---- tube: stall into the pocket under the curtain ----
    const insideFoam = s.breaking && P.pos.x>s.bL+0.8 && P.pos.x<s.bR-0.8;
    const wasTube = P.tube > 0;
    P.tube = 0;
    if (s.brl > 0.5 && s.breaking && !insideFoam){
      let dOut = -1;
      if (s.dir>=0 && P.pos.x >= s.bR) dOut = P.pos.x - s.bR;
      if (s.dir<=0 && P.pos.x <= s.bL) dOut = s.bL - P.pos.x;
      const pocketLen = pocketW(s)*0.85;   // scoring zone tracks the rendered curtain
      if (dOut > 0.2 && dOut < pocketLen && dzc > 0.3 && dzc < LfF*0.95) P.tube = 1;
    }
    if (P.tube > 0){
      P.tubeT += dt;
      P.ride.t += dt*2;               // time in the barrel scores 3x
      if (!wasTube){ HUD.grade('IN THE BARREL'); SFX.setTube(true); }
      HUD.barrel(P.tubeT);
    } else if (wasTube){
      SFX.setTube(false); HUD.barrel(null);
      if (P.tubeT > 0.8){
        HUD.grade('BARRELED — '+P.tubeT.toFixed(1)+'s');
        P.session.bestTube = Math.max(P.session.bestTube, P.tubeT);
        HUD.stats();
        const dirX = s.dir>=0 ? 1 : -1;   // spit blasts out of the opening
        SPRAY.emit(P.pos.x - dirX*2, P.pos.y+s.amp*0.4, P.pos.z+0.5,
          dirX*7, 1.5, s.c*0.6, 1.6, 26, 0.2, 1.2, 1);
        SFX.spit();
        P.vel.addScaledVector(_v3, 0.8);
      }
      P.tubeT = 0;
    }
    // board + rider
    boardG.position.x = P.pos.x; boardG.position.z = P.pos.z;
    if (CAM.mode==='tp' && !dragging) CAM.yaw += shortAngle(CAM.yaw, h)*damp(1.4,dt);
    boardFollowSurface(dt, h + P.slide*P.lean*0.6, -0.05 - spd*0.004);
    P.pos.y = boardG.position.y;
    // Keep the hips inside the legs' reachable arc. Besides preventing hover,
    // this gives the neutral ride a visibly compressed, ready-to-react stance.
    const crouch=0.38+0.12*P.pumpF+0.025*Math.abs(P.lean);
    placeCharOnBoard(_v3b.set(0,-crouch,-0.06), 0, -Math.PI*0.5, P.lean*0.42);
    resetPose(); poseSurf(P.lean, P.pumpF, t);
    trackRide(dt, s, spd);
    HUD.prompt(P.tube>0
      ? '<kbd>S</kbd> stall to stay in the pocket &middot; hold your line to the opening'
      : '<kbd>A D</kbd> rail &middot; <kbd>W</kbd> pump (hold down, release up) &middot; <kbd>S</kbd> stall &middot; <kbd>SHIFT</kbd> kick out');
    // ---- ride enders ----
    const d = Math.max(waterH(P.pos.x,P.pos.z,t)-terrainY(P.pos.x,P.pos.z),0);
    if (K.sh){ K.sh=false; endRide('kickout'); P.state='prone'; P.pos.z = s.zc-3.2; P.spd=0.6; P.invuln=1; break; }
    if (Math.abs(P.pos.x-s.xc) > s.halfW*1.05){ endRide('shoulder'); P.state='prone'; P.spd=1; break; }
    if (insideFoam && P.tube<=0){
      // ride over the broken lip fast + high = floater window
      if (dzc < 1.6 && spd > 4.5 && P.floatT < 1.0){
        P.floatT += dt;
        if (!P.floated){ P.floated=true; HUD.grade('FLOATER'); }
      } else if (s.bf > 0.72){ startWipe(); break; }
      else { endRide('foam'); P.state='prone'; P.push.z += 7; P.spd=0.8; P.invuln=0.8; break; }
    } else { P.floatT = 0; P.floated = false; }
    if (dzc < -2.2){ endRide('faded'); P.state='prone'; P.spd=0.8; P.invuln=0.8; break; }
    if (dzc > LfF*2.8 && spd < s.c*0.5){ endRide('flats'); P.state='prone'; P.spd*=0.6; break; }
    if (d < 0.62){ endRide('shore'); P.state='ground'; P.pos.y=terrainY(P.pos.x,P.pos.z); P.spd=2.2; break; }
    break;
  }

  case 'wipe': {
    P.timer += dt;
    const tau = clamp(P.timer/Math.max(P.holdDown,0.5),0,1);
    // second wave of the set landing on you resets the tumble (once)
    if (!P.holdStaged && tau>0.35){
      for (const s of swells){
        if (s.breaking && Math.abs(P.pos.z - s.zc)<3.5 && s.amp>1.2
            && P.pos.x>s.bL-4 && P.pos.x<s.bR+4){
          P.holdStaged = true; P.timer = Math.min(P.timer, P.holdDown*0.3);
          RAG.seed(rand(-2,2), -1.5, s.c*0.7, dt);
          HUD.alert('held down — second wave');
          SFX.wipe();
          break;
        }
      }
    }
    RAG.step(dt, t);
    resetPose();  // spine flail + limbs come from RAG.apply after easing
    const hs = waterH(P.pos.x,P.pos.z,t);
    // board tumbles nearby on the leash
    boardG.position.lerp(_v3.set(P.pos.x+1.1, hs, P.pos.z+0.8), damp(4,dt));
    boardFollowSurface(dt, P.yaw+1.2, 0.3);
    HUD.prompt(P.holdDown>4 ? 'held down — ride it out&hellip;' : 'washed!');
    if (tau>=1){
      P.state='prone'; P.spd=0; P.phase=0; P.invuln=1.6; P.yaw=Math.PI;
      P.pos.y = waterH(P.pos.x,P.pos.z,t);
      boardG.position.set(P.pos.x, P.pos.y, P.pos.z);
      _e.set(0,P.yaw,0); boardG.quaternion.setFromEuler(_e);
    }
    break;
  }
  }

  // additive layers -> ease -> hard IK / ragdoll
  animPre(dt, t);
  easeJoints(dt, P.state==='popup'||P.state==='wipe' ? 16 : 11);
  animPost(dt, t);
}
function trackRide(dt, s, spd){
  const r=player.ride;
  r.t += dt;
  if (spd){ r.dist += spd*dt; if (spd>r.vmax) r.vmax=spd; }
  HUD.ride(r);
}
function shortAngle(from,to){
  let d=(to-from)%TAU; if(d>Math.PI)d-=TAU; if(d<-Math.PI)d+=TAU; return d;
}

// ---------- camera ----------
const CAM = { mode:'tp', yaw:Math.PI, pitch:0.30, dist:7.5, waveDip:0, tubeK:0 };
function updateCamera(dt){
  charRoot.updateMatrixWorld(true);
  if (CAM.mode==='tp'){
    _v3.copy(charRoot.position);
    // the seat blend moves the root ~0.85m — track it so the look target never snaps
    const seatOff = lerp(0.8, 1.5, sstep(0, 1, player.sitK));
    const riderLook=(player.state==='prone'||player.state==='duck'||player.state==='catch'
           || player.state==='sit') ? seatOff : 1.35;
    // Inside a barrel the chase camera drops beneath the lip instead of
    // hovering above it, while still keeping the rider fully framed.
    _v3.y += lerp(riderLook,0.65,CAM.tubeK);
    let waveWant=0;
    if (!dragging && (player.state==='prone'||player.state==='sit'||player.state==='catch')){
      for(const s of swells){
        const d=player.pos.z-s.zc;
        if (d>7&&d<62&&s.wash>0.55) waveWant=Math.max(waveWant,sstep(60,15,d)*sstep(0.55,1.8,s.amp));
      }
    }
    CAM.waveDip += (waveWant-CAM.waveDip)*damp(waveWant>CAM.waveDip?2.2:1.2,dt);
    CAM.tubeK += ((player.tube>0?1:0)-CAM.tubeK)*damp(player.tube>0?5:2.4,dt);
    const viewPitch=lerp(lerp(CAM.pitch,Math.min(CAM.pitch,0.055),CAM.waveDip),0.035,CAM.tubeK);
    const viewDist=lerp(CAM.dist,4.7,CAM.tubeK);
    const cp = Math.cos(viewPitch), sp = Math.sin(viewPitch);
    _v3b.set(_v3.x - Math.sin(CAM.yaw)*cp*viewDist,
             _v3.y + sp*viewDist,
             _v3.z - Math.cos(CAM.yaw)*cp*viewDist);
    const floor = Math.max(surfY(_v3b.x,_v3b.z,simT), terrainY(_v3b.x,_v3b.z)) + 0.45;
    if (_v3b.y < floor) _v3b.y = floor;
    camera.position.lerp(_v3b, damp(11,dt));
    camera.lookAt(_v3);
    headMeshes.forEach(m=>m.visible=true);
  } else {
    J.head.getWorldPosition(_v3);
    camera.position.copy(_v3);
    camera.rotation.set(-CAM.pitch, CAM.yaw+Math.PI, 0, 'YXZ');
    camera.position.addScaledVector(_v3b.set(Math.sin(CAM.yaw),0,Math.cos(CAM.yaw)), 0.11);
    headMeshes.forEach(m=>m.visible=false);
  }
  const uw = camera.position.y < waterH(camera.position.x,camera.position.z,simT)-0.04;
  if (uwPass){ uwPass.enabled = uw; HUD.underwater(false); }
  else HUD.underwater(uw);
  SFX.setUnderwater(uw);
}

// ---------- input ----------
const K = {w:false,a:false,s:false,d:false,sh:false,sp:false,e:false};
let started=false, paused=false;
const TEST_MODE = new URLSearchParams(location.search).has('test');
const KEYMAP = {KeyW:'w',ArrowUp:'w',KeyA:'a',ArrowLeft:'a',KeyS:'s',ArrowDown:'s',KeyD:'d',ArrowRight:'d',
  ShiftLeft:'sh',ShiftRight:'sh',Space:'sp',KeyE:'e'};
document.getElementById('prompt').style.display='none';
addEventListener('keydown', ev=>{
  if (!started){
    // start-screen shortcuts (buttons stay natively focusable/enter-able);
    // never eat browser chords (Cmd/Ctrl+R reload!) or key auto-repeat
    if (ev.repeat || ev.metaKey || ev.ctrlKey || ev.altKey) return;
    if (ev.code==='Digit1') selectBoard('short');
    else if (ev.code==='Digit2') selectBoard('fun');
    else if (ev.code==='Digit3') selectBoard('long');
    else if (ev.code==='KeyR') selectRider(RIDER.key==='kai' ? 'mara' : 'kai');
    else if (ev.code==='KeyF') toggleFullscreen();
    return;
  }
  if (ev.target && (ev.target.tagName==='INPUT' || ev.target.tagName==='TEXTAREA')) return;
  const k = KEYMAP[ev.code];
  if (k){ if(!ev.repeat) K[k]=true; ev.preventDefault(); return; }
  if (ev.repeat) return;
  if (ev.code==='KeyC') CAM.mode = CAM.mode==='tp'?'fp':'tp';
  if (ev.code==='KeyF') toggleFullscreen();
  if (ev.code==='KeyP' || (ev.code==='Escape' && !document.fullscreenElement)) togglePause();
  if (ev.code==='KeyM') SFX.toggleMute();
  if (ev.code==='KeyL') HUD.lb(true);
});
addEventListener('keyup', ev=>{
  const k=KEYMAP[ev.code]; if(k) K[k]=false;   // clearing a key is always safe
});
addEventListener('blur', ()=>{ for (const k in K) K[k]=false; });
let dragging=false;
const cv = renderer.domElement;
cv.addEventListener('pointerdown', ev=>{ dragging=true; cv.setPointerCapture(ev.pointerId); });
cv.addEventListener('pointerup',   ev=>{ dragging=false; });
cv.addEventListener('pointermove', ev=>{
  if (!dragging) return;
  CAM.yaw   -= ev.movementX*0.0042;
  CAM.pitch += ev.movementY*0.0042;
  CAM.pitch = CAM.mode==='tp' ? clamp(CAM.pitch, 0.03, 1.15) : clamp(CAM.pitch, -1.15, 1.15);
});
addEventListener('wheel', ev=>{ CAM.dist = clamp(CAM.dist*(1+ev.deltaY*0.0011), 3.2, 14); }, {passive:true});
cv.addEventListener('contextmenu', ev=>ev.preventDefault());
document.addEventListener('visibilitychange', ()=>{ if (document.hidden && started && !paused) togglePause(); });
function toggleFullscreen(){
  if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
  else document.documentElement.requestFullscreen().catch(()=>{});
}

// ---------- audio (all procedural web audio, zero asset files) ----------
const SFX = {
  ctx:null, master:null, lp:null, muted:false, oceanGain:null, _noise:null,
  start(){
    if (this.ctx) return;
    try{
      const C = new (window.AudioContext||window.webkitAudioContext)();
      this.ctx=C;
      this.master=C.createGain(); this.master.gain.value=0.9;
      this.lp=C.createBiquadFilter(); this.lp.type='lowpass'; this.lp.frequency.value=19000;
      this.master.connect(this.lp); this.lp.connect(C.destination);
      const len=C.sampleRate*2, buf=C.createBuffer(1,len,C.sampleRate), d=buf.getChannelData(0);
      let last=0; for(let i=0;i<len;i++){ const w=Math.random()*2-1; last=(last+0.04*w)/1.04; d[i]=last*4.2; }
      this._noise=buf;
      const ocean=C.createBufferSource(); ocean.buffer=buf; ocean.loop=true;
      const of=C.createBiquadFilter(); of.type='lowpass'; of.frequency.value=430;
      this.oceanGain=C.createGain(); this.oceanGain.gain.value=0.30;
      ocean.connect(of); of.connect(this.oceanGain); this.oceanGain.connect(this.master); ocean.start();
      const wind=C.createBufferSource(); wind.buffer=buf; wind.loop=true; wind.playbackRate.value=1.4;
      const wf=C.createBiquadFilter(); wf.type='highpass'; wf.frequency.value=2400;
      const wg=C.createGain(); wg.gain.value=0.045;
      wind.connect(wf); wf.connect(wg); wg.connect(this.master); wind.start();
      // barrel interior: feedback-delay reverb the ocean bed gets sent through
      this.rvSend=C.createGain(); this.rvSend.gain.value=0;
      const dl=C.createDelay(0.5); dl.delayTime.value=0.074;
      const fb=C.createGain(); fb.gain.value=0.6;
      const rlp=C.createBiquadFilter(); rlp.type='lowpass'; rlp.frequency.value=850;
      this.rvSend.connect(dl); dl.connect(rlp); rlp.connect(fb); fb.connect(dl);
      const wet=C.createGain(); wet.gain.value=1.1;
      rlp.connect(wet); wet.connect(this.master);
      this.oceanGain.connect(this.rvSend);
    }catch(e){ this.ctx=null; }
  },
  setTube(b){
    if (!this.ctx || !this.rvSend) return;
    this.rvSend.gain.setTargetAtTime(b?1.0:0, this.ctx.currentTime, 0.2);
  },
  spit(){ this.burst(0.7, 2600, 700, 0.34, 'highpass'); this.burst(0.5, 900, 250, 0.2); },
  burst(dur, f0, f1, vol, type='lowpass'){
    const C=this.ctx; if(!C) return;
    const s=C.createBufferSource(); s.buffer=this._noise; s.loop=true;
    const f=C.createBiquadFilter(); f.type=type; f.frequency.setValueAtTime(f0,C.currentTime);
    f.frequency.exponentialRampToValueAtTime(Math.max(f1,40), C.currentTime+dur);
    const g=C.createGain(); g.gain.setValueAtTime(0.0001,C.currentTime);
    g.gain.exponentialRampToValueAtTime(vol, C.currentTime+Math.min(0.12,dur*0.3));
    g.gain.exponentialRampToValueAtTime(0.0001, C.currentTime+dur);
    s.connect(f); f.connect(g); g.connect(this.master);
    s.start(); s.stop(C.currentTime+dur+0.05);
  },
  breakBoom(dist, cls=1){
    const v=clamp(1-dist/(170+cls*60),0,1);
    if(v>0.02) this.burst(1.6+cls*0.45, 950-cls*140, 210-cls*30, (0.42+cls*0.13)*v, 'bandpass');
  },
  dump(cls=1, dist=0){ const v=clamp(1-dist/140,0,1); if(v>0.02) this.burst(0.8, 620, 120, 0.6*v); },
  rumble(){ this.burst(4.5, 130, 42, 0.5); },
  sprayHiss(g=1){ this.burst(0.35+g*0.1, 3200, 1400, 0.10+g*0.05, 'highpass'); },
  plop(){ this.burst(0.10, rand(700,1100), 300, 0.14); },
  duck(){ this.burst(0.6, 1400, 260, 0.22); },
  pop(){ this.burst(0.14, 500, 180, 0.2); },
  wipe(){ this.burst(1.2, 800, 160, 0.5); },
  setUnderwater(b){
    if(!this.ctx) return;
    this.lp.frequency.setTargetAtTime(b?290:19000, this.ctx.currentTime, 0.08);
  },
  tick(t){ if(this.oceanGain) this.oceanGain.gain.value = 0.26 + 0.10*Math.sin(t*0.37) + 0.04*Math.sin(t*0.11); },
  toggleMute(){
    this.muted=!this.muted;
    if(this.master) this.master.gain.value = this.muted?0:0.9;
    HUD.soundBtn(this.muted);
  },
};

// ---------- hud ----------
// predicted barrel quality when this swell reaches the outer bar (z=-85):
// runs the same shoal/ratio math updateSwells will apply when it gets there
function predictHollow(s){
  const depB = Math.max(-terrainY(s.xc, -85), 0.8);
  const ampB = s.amp0*0.55*clamp(Math.pow(7.0/depB, 0.33), 1, 2.35);
  return sstep(0.78, 1.06, ampB/depB) * sstep(0.9, 1.6, ampB) * (s.closeout?0.6:1);
}
const $ = id=>document.getElementById(id);
const HUD = {
  _prompt:'', _alertT:0, pendingRide:null, netDown:false,
  prompt(h){ if(h!==this._prompt){ this._prompt=h; const p=$('prompt'); p.innerHTML=h; p.style.display=h?'':'none'; } },
  alert(msg){ const a=$('alert'); a.textContent=msg; a.classList.add('show'); this._alertT=simT; },
  tickAlert(){
    if(this._alertT && simT-this._alertT>3.2){ $('alert').classList.remove('show'); this._alertT=0; }
    if(this._gradeT && simT-this._gradeT>1.6){ $('grade').classList.remove('show'); this._gradeT=0; }
  },
  grade(msg, bad=false){
    const g=$('grade'); g.textContent=msg;
    g.classList.toggle('bad', !!bad); g.classList.add('show');
    this._gradeT=simT;
  },
  // lineup radar: top-down map of the surf zone — where the waves are, where
  // they're breaking (white), and the takeoff pockets (pulsing dots)
  _radarAcc:0, _radarCtx:null,
  radar(dt){
    const wrap=$('radarWrap');
    if (!started){ wrap.style.display='none'; return; }
    wrap.style.display='block';
    this._radarAcc -= dt;
    if (this._radarAcc > 0) return;
    this._radarAcc = 0.12;
    if (!this._radarCtx) this._radarCtx = $('radar').getContext('2d');
    const g=this._radarCtx, W=150, H=168;
    const px=player.pos.x;
    const X0=px-170, XW=340, Z0=-250, ZW=275;          // world window (z -250..+25)
    const mx=x=>(x-X0)/XW*W, mz=z=>(z-Z0)/ZW*H;
    g.fillStyle='#0a2c40'; g.fillRect(0,0,W,H);
    // depth shading: sandbar + inside (where waves break)
    for (let j=0;j<H;j+=3){
      const z = Z0 + j/H*ZW;
      const dep = -terrainY(0,Math.min(z,-0.01));
      if (z>=0){ g.fillStyle='#c7b489'; g.fillRect(0,j,W,3); continue; }
      if (dep<2.2){ g.fillStyle='rgba(219,196,140,0.30)'; g.fillRect(0,j,W,3); }
      else if (dep<4.6){ g.fillStyle='rgba(120,190,190,0.14)'; g.fillRect(0,j,W,3); }
    }
    // swells: crest lines, white where broken, pocket dots at the peel edges
    for (const s of swells){
      if (s.wash < 0.35) continue;
      const j = mz(s.zc);
      if (j<0||j>H) continue;
      const th = clamp(1.5 + s.amp*1.6, 1.5, 7);
      const hol = !s.closeout && predictHollow(s) > 0.45;   // barrels get their own tint
      const hot = s.cls>=4 ? '#ff7d6e' : hol ? '#b18cff' : s.cls>=3 ? '#ffb45e' : '#37d3e6';
      for (let i=0;i<W;i+=2){
        const x = X0 + i/W*XW;
        const q=(x-s.xc)/s.halfW, q2=q*q, env=Math.exp(-(q2*q2));
        if (env<0.10) continue;
        const broken = s.breaking && x>s.bL && x<s.bR;
        g.fillStyle = broken ? 'rgba(244,251,253,0.95)' : hot;
        g.globalAlpha = (broken?0.95:0.4+0.6*env)*s.wash;
        g.fillRect(i, j-th*env*0.5, 2, Math.max(th*env,1.2));
      }
      g.globalAlpha=1;
      // takeoff pockets just outside the active peel edges
      if (s.breaking && !s.dying){
        const pulse = 2.2+Math.sin(simT*5)*0.8;
        g.fillStyle='#8ff0ff';
        if (s.dir>=0 && s.bR < s.xc+s.halfW*1.2){ g.beginPath(); g.arc(mx(s.bR+4), j, pulse, 0, TAU); g.fill(); }
        if (s.dir<=0 && s.bL > s.xc-s.halfW*1.2){ g.beginPath(); g.arc(mx(s.bL-4), j, pulse, 0, TAU); g.fill(); }
      } else if (!s.breaking){
        g.fillStyle='rgba(143,240,255,0.8)';
        g.beginPath(); g.arc(mx(s.x0), j, 2, 0, TAU); g.fill();
        const eta = (player.pos.z - s.zc - 3)/s.c;   // seconds until it reaches you
        if (eta > 0 && eta < 60){
          g.font='8px sans-serif'; g.fillStyle='rgba(244,251,253,0.75)';
          g.fillText(Math.round(eta)+'s', mx(s.x0)+5, j-3);
        }
      }
    }
    // player: dot + heading tick
    const pj = mz(player.pos.z), pi = mx(px);
    g.strokeStyle='#fff'; g.fillStyle='#ffb45e';
    g.beginPath(); g.arc(pi, pj, 3, 0, TAU); g.fill();
    g.beginPath(); g.moveTo(pi, pj);
    g.lineTo(pi+Math.sin(player.yaw)*7, pj+Math.cos(player.yaw)*7); g.stroke();
  },
  _cardsAcc:0, _cardsHtml:'',
  cards(dt){
    const on = player.state==='prone' || player.state==='sit' || player.state==='duck';
    const el=$('cards');
    el.style.display = on ? 'flex' : 'none';
    if (!on) return;
    this._cardsAcc -= dt;
    if (this._cardsAcc > 0) return;
    this._cardsAcc = 0.25;
    let html='', shown=0, hot=true;
    for (const s of swells){
      if (s.dying || s.wash<0.8) continue;
      const eta = (player.pos.z - s.zc - 3)/s.c;
      if (eta < 0 || eta > 26) continue;
      const call = s.closeout ? 'CLOSEOUT' : s.dir>0 ? 'LEFT' : s.dir<0 ? 'RIGHT' : 'A-FRAME';
      const ft = clamp(Math.round(s.amp0*3.281), 1, 12);
      const hollow = !s.closeout && predictHollow(s) > 0.45;
      html += `<div class="wcard panel ${call}${hot?' hot':''}"><div class="ft">${ft}ft<small>face</small></div>`+
              `<span class="call">${call}</span>${hollow?'<span class="call hollow">HOLLOW</span>':''}`+
              `<span class="eta">${Math.max(Math.round(eta),0)}s</span></div>`;
      hot=false;
      if (++shown>=3) break;
    }
    if (html !== this._cardsHtml){ this._cardsHtml = html; el.innerHTML = html; }
  },
  _rideT:-1, _rideS:-1,
  ride(r){
    const tv = Math.round(r.t*10), sv = Math.round(player.spd*2.237);
    if (tv!==this._rideT){ this._rideT=tv; $('rideTime').textContent = (tv/10).toFixed(1)+'s'; }
    if (sv!==this._rideS){ this._rideS=sv; $('rideSpd').textContent = sv; }
  },
  rideHud(b){ $('ridehud').style.display = b?'block':'none'; },
  stats(){
    const S = player.session;
    $('stWaves').textContent = S.waves;
    $('stBest').textContent = player.stats.best.toFixed(1)+'s';
    $('stTube').textContent = S.bestTube>0 ? S.bestTube.toFixed(1)+'s' : '—';
    $('stFace').textContent = S.bestFace>0 ? S.bestFace+'ft' : '—';
  },
  waveMeter(){
    const on = player.state==='prone'||player.state==='sit';
    $('wavemeter').style.display = on?'block':'none';
    if (!on) return;
    let best=Infinity;
    for (const s of swells){ const d=player.pos.z-s.zc; if(d>2 && d<best && s.wash>0.7) best=d; }
    $('waveDist').textContent = best<Infinity ? Math.round(best)+'m' : '\u2014';
  },
  underwater(b){ $('underwater').style.display = b?'block':'none'; },
  barrel(tv){
    const b=$('barrelhud');
    $('barrelfx').classList.toggle('on',tv!==null);
    if (tv===null){ b.style.display='none'; return; }
    b.style.display='block';
    $('barrelT').textContent = tv.toFixed(1)+'s';
  },
  saveToast(r){
    this.pendingRide = {t:r.t, dist:r.dist, vmax:r.vmax};
    $('toastMsg').textContent = `ride: ${r.t.toFixed(1)}s \u00b7 ${Math.round(r.dist)}m \u00b7 ${Math.round(r.vmax*2.237)} mph max` +
      (this.netDown ? ' (leaderboard offline)' : ' \u2014 post it?');
    $('toast').classList.add('show');
    $('btnSaveRide').style.display = this.netDown?'none':'inline-block';
    clearTimeout(this._toastTo);
    this._toastTo = setTimeout(()=>$('toast').classList.remove('show'), 12000);
  },
  soundBtn(m){ $('btnSound').textContent = 'sound: '+(m?'off':'on'); },
  async lb(open){
    if(!open){ $('lb').classList.remove('show'); return; }
    $('lb').classList.add('show');
    const body=$('lbBody'); body.textContent='loading\u2026';
    try{
      const res = await fetch('?action=leaderboard');
      const j = await res.json();
      if(!j.ok) throw 0;
      body.textContent='';
      if (!j.data.length){ body.textContent='no rides posted yet \u2014 go get one.'; return; }
      const tb=document.createElement('table');
      const hr=tb.insertRow();
      ['#','name','ride','dist','max'].forEach(h=>{const th=document.createElement('th');th.textContent=h;hr.appendChild(th);});
      j.data.forEach((row,i)=>{
        const tr=tb.insertRow();
        const c0=tr.insertCell(); c0.textContent=String(i+1);
        const c1=tr.insertCell(); c1.textContent=row.name;
        const c2=tr.insertCell(); c2.textContent=Number(row.duration).toFixed(1)+'s'; c2.className='num';
        const c3=tr.insertCell(); c3.textContent=Math.round(row.distance)+'m'; c3.className='num';
        const c4=tr.insertCell(); c4.textContent=Math.round(row.max_speed)+' mph'; c4.className='num';
      });
      body.appendChild(tb);
    }catch(e){ this.netDown=true; body.textContent='leaderboard needs the php backend \u2014 run this file with php, not as a static page.'; }
  },
};
$('btnDismiss').onclick = ()=>$('toast').classList.remove('show');
$('btnSaveRide').onclick = async ()=>{
  const r=HUD.pendingRide; if(!r) return;
  const name = $('riderName').value.trim() || 'PENGU';
  try{
    const res = await fetch('?action=save_ride', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf:window.PP_CSRF, name, duration:r.t, distance:r.dist, max_speed:r.vmax*2.237}),
    });
    const j = await res.json();
    HUD.alert(j.ok ? 'ride posted' : ('save failed: '+(j.error||'?')));
  }catch(e){ HUD.netDown=true; HUD.alert('leaderboard offline'); }
  $('toast').classList.remove('show');
};
$('btnLbClose').onclick = ()=>HUD.lb(false);
$('btnLb2').onclick = ()=>{ togglePause(); HUD.lb(true); };
$('btnResume').onclick = ()=>togglePause();
$('btnSound').onclick = ()=>SFX.toggleMute();
const QNAMES = {low:'off', med:'bloom+smaa', high:'full'};
function setQuality(q){
  quality = q;
  $('btnQuality').textContent = 'post-fx: '+QNAMES[q];
  applyQuality(); rebuildPost(); sizeUnderRT();
  buildOcean(); buildSwash(quality!=='low'); buildSuit(quality!=='low');
  setEnvIntensity(charRoot, 0.55);
  REFL.on = quality!=='low';
  // shadow budget follows the tier (map realloc happens on next shadow pass)
  const ss = quality==='high' ? 2048 : 1024;
  if (sun.shadow.mapSize.x !== ss){
    sun.shadow.mapSize.set(ss, ss);
    if (sun.shadow.map){ sun.shadow.map.dispose(); sun.shadow.map = null; }
  }
}
$('btnQuality').onclick = ()=>{
  setQuality(quality==='high' ? 'low' : quality==='low' ? 'med' : 'high');
};
$('btnParticles').onclick = ()=>{
  SET.particles = SET.particles==='high' ? 'low' : SET.particles==='low' ? 'off' : 'high';
  $('btnParticles').textContent = 'spray: '+SET.particles;
};
$('btnRefl').onclick = ()=>{
  SET.refl = !SET.refl;
  $('btnRefl').textContent = 'mirror sand: '+(SET.refl?'on':'off');
};
$('btnMotion').onclick = ()=>{
  SET.motion = SET.motion==='full' ? 'reduced' : 'full';
  $('btnMotion').textContent = 'motion: '+SET.motion;
};
if (SET.motion==='reduced') $('btnMotion').textContent = 'motion: reduced';
function togglePause(){
  if (!started) return;
  paused = !paused;
  $('pause').classList.toggle('show', paused);
}
// ---------- rider + board selection (start screen, persisted) ----------
function selectBoard(k){
  if (!Object.hasOwn(BOARDS, k)) k='fun';
  document.querySelectorAll('#boardSel .selcard').forEach(b=>
    b.classList.toggle('sel', b.dataset.board===k));
  try{ localStorage.setItem('pp2.board', k); }catch(e){}
  if (BOARD.key !== k || !boardG.children.length) buildBoard(k);   // rebuild only on change
}
function selectRider(k){
  if (!Object.hasOwn(RIDERS, k)) k='kai';
  document.querySelectorAll('#riderSel .selcard').forEach(b=>
    b.classList.toggle('sel', b.dataset.rider===k));
  try{ localStorage.setItem('pp2.rider', k); }catch(e){}
  if (RIDER.key !== k || !J.pelvis){
    buildRig(k);
    HAIRS.x=HAIRS.vx=HAIRS.z=HAIRS.vz=0;HAIRS.inited=false;
  }
}
document.querySelectorAll('#boardSel .selcard').forEach(b=>b.onclick = ()=>selectBoard(b.dataset.board));
document.querySelectorAll('#riderSel .selcard').forEach(b=>b.onclick = ()=>selectRider(b.dataset.rider));
{
  let sb='fun', sr='kai';
  try{
    sb = localStorage.getItem('pp2.board') || 'fun';
    sr = localStorage.getItem('pp2.rider') || 'kai';
  }catch(e){}
  selectBoard(sb); selectRider(sr);
}
$('btnStart').onclick = ()=>{
  $('start').style.display='none';
  document.body.classList.add('game-started');
  menuKey.visible=false;
  player.state='ground'; player.pos.set(6,terrainY(6,62),62); player.yaw=Math.PI;
  player.spd=0; player.phase=0; player.push.set(0,0,0);
  charRoot.position.copy(player.pos);
  _e.set(0,player.yaw,0);charRoot.quaternion.setFromEuler(_e);
  // Menu preview foot plants belong to the showcase location. Force a fresh
  // heel capture at the gameplay spawn so IK never reaches back toward the menu.
  GAIT.stL=GAIT.stR=false;
  HAIRS.x=HAIRS.vx=HAIRS.z=HAIRS.vz=0;HAIRS.inited=false;
  boardG.position.set(player.pos.x,player.pos.y+0.9,player.pos.z);
  CAM.mode='tp'; CAM.yaw=Math.PI; CAM.pitch=0.30; CAM.dist=7.5;
  SFX.start(); started=true; HUD.stats();
};

// ---------- main loop ----------
// shadows: manual cadence — every frame at high, every 2nd frame below
renderer.shadowMap.autoUpdate = false;
renderer.shadowMap.needsUpdate = true;
let shadowTick = 0;
window.PP_DBG = ()=>{
  const hw=new THREE.Vector3(), pw=new THREE.Vector3();
  const jw = n=>{const v=new THREE.Vector3(); J[n].getWorldPosition(v); return v.toArray().map(x=>+x.toFixed(2));};
  J.head.getWorldPosition(hw); J.pelvis.getWorldPosition(pw);
  return {state:player.state, pos:player.pos.toArray(), yaw:player.yaw,
    board:BOARD.key, rider:RIDER.key,
    spd:player.spd, rootQ:charRoot.quaternion.toArray(), boardQ:boardG.quaternion.toArray(),
    rootMW:Array.from(charRoot.matrixWorld.elements), headW:hw.toArray(), pelvisW:pw.toArray(),
    rootAuto:charRoot.matrixAutoUpdate, shL:J.shoulderL.quaternion.toArray(),
    swells:swells.length, bores:bores.length,
    sw:swells.map(s=>({zc:+s.zc.toFixed(1), xc:+s.xc.toFixed(0), amp:+s.amp.toFixed(2),
      c:+s.c.toFixed(1), bf:+s.bf.toFixed(2), brl:+s.brl.toFixed(2), dir:s.dir,
      close:s.closeout, bL:+s.bL.toFixed(0), bR:+s.bR.toFixed(0)})),
    limbs:{hipL:jw('hipL'), kneeL:jw('kneeL'), ankleL:jw('ankleL'),
           hipR:jw('hipR'), kneeR:jw('kneeR'), ankleR:jw('ankleR'),
           shL:jw('shoulderL'), elL:jw('elbowL'), wrL:jw('wristL')},
    boardY:+boardG.position.y.toFixed(2), waterY:+waterH(player.pos.x,player.pos.z,simT).toFixed(2),
    _p:player, _c:CAM, _sw:swells, _spawn:spawnSwell,
    _root:charRoot, _suit:suitMesh, _sk:SKEL};
};
window.render_game_to_text = ()=>JSON.stringify({
  coordinates:'world x runs along shore; z<0 is ocean/seaward, +z is beach/shoreward; y is up',
  mode:started ? (paused ? 'paused' : player.state) : 'menu',
  selection:{rider:RIDER.key, board:BOARD.key},
  player:{
    x:+player.pos.x.toFixed(2), y:+player.pos.y.toFixed(2), z:+player.pos.z.toFixed(2),
    yaw:+player.yaw.toFixed(3), speed:+player.spd.toFixed(2),
    velocity:{x:+player.vel.x.toFixed(2),z:+player.vel.z.toFixed(2)},
    onFace:+player.onFace.toFixed(2), tube:player.tube>0, tubeTime:+player.tubeT.toFixed(2),
  },
  ride:{time:+player.ride.t.toFixed(2), distance:+player.ride.dist.toFixed(1), maxSpeed:+player.ride.vmax.toFixed(2)},
  swells:swells.filter(s=>s.wash>0.25).map(s=>({
    x:+s.xc.toFixed(1),z:+s.zc.toFixed(1),amp:+s.amp.toFixed(2),speed:+s.c.toFixed(2),
    break:+s.bf.toFixed(2),barrel:+s.brl.toFixed(2),direction:s.dir,closeout:s.closeout,
    broken:[+s.bL.toFixed(1),+s.bR.toFixed(1)],
  })),
  camera:{mode:CAM.mode,yaw:+CAM.yaw.toFixed(3),pitch:+CAM.pitch.toFixed(3),distance:+CAM.dist.toFixed(2)},
  session:{...player.session},
});
window.PP_STAGE = name=>{
  if (!TEST_MODE) return false;
  started=true; paused=false; $('start').style.display='none';
  document.body.classList.add('game-started'); menuKey.visible=false;
  swells.length=0; bores.length=0;
  player.state='sit'; player.spd=0; player.vel.set(0,0,0); player.push.set(0,0,0);
  player.swell=null; player.tube=0; player.tubeT=0; player.sitK=1; player.yaw=Math.PI;
  CAM.mode='tp'; CAM.yaw=Math.PI; CAM.pitch=0.22; CAM.dist=7.5; CAM.waveDip=0;
  let s=null;
  if (name==='forming'){
    player.pos.set(0,waterH(0,-79,simT),-79);
    s=spawnSwell(2.9,0,78,3,1,false);
    if(s){s.zc=-98;s.c=0;s.peelRate=0;s.breaking=false;s.bL=9999;s.bR=-9999;s.wash=1;}
  } else if (name==='barrel'||name==='ride'||name==='ridebarrel'){
    player.pos.set(10,waterH(10,-76,simT),-76);
    s=spawnSwell(name==='ride'?1.45:3.4,0,82,name==='ride'?2:4,1,false);
    if(s){s.zc=-85;s.c=0;s.peelRate=0;s.breaking=true;s.bL=-18;s.bR=0;s.x0=0;s.bf=1;s.brl=name==='ride'?0:1;s.tBrk=1.2;s.wash=1;}
  } else return false;
  if((name==='ride'||name==='ridebarrel')&&s){
    player.state='surf'; player.sitK=0; player.swell=s; player.onFace=1;
    player.pos.set(10,waterH(10,-83.3,simT),-83.3);
    player.yaw=Math.PI/2; player.hYaw=Math.PI/2; player.vel.set(6.2,0,0.10); player.spd=6.2;
    player.ride.t=0.01; player.ride.dist=0; player.ride.vmax=0;
    CAM.yaw=Math.PI/2; CAM.pitch=0.13; CAM.dist=6.2;
  }
  boardG.position.copy(player.pos); charRoot.position.copy(player.pos);
  return true;
};
setEnvIntensity(scene, 0.55);
const clock = new THREE.Clock();
camera.position.set(6, 5, 78); camera.lookAt(6, 1, 0);
function updateMenuPreview(dt){
  const P=player, z=58, y=terrainY(0,z);
  P.state='ground'; P.pos.set(0,y,z); P.spd=0;
  P.yaw = 0.72+Math.sin(simT*0.42)*0.10;  // three-quarter view reveals the board tucked under the arm
  charRoot.position.copy(P.pos);
  _e.set(0,P.yaw,0); charRoot.quaternion.setFromEuler(_e);
  resetPose();
  setJ('chest',Math.sin(simT*1.2)*0.018);
  setJ('neck',-0.025,Math.sin(simT*0.35)*0.08,0);
  setJ('shoulderR',0,0,-0.06);
  poseCarry();
  carryBoard(dt);
  animPre(dt,simT);
  easeJoints(dt,9);
  animPost(dt,simT);
  // Shoreward camera puts the ocean behind the live model and leaves UI-safe side gutters.
  menuKey.visible=true;
  menuKey.position.set(-3,y+5.5,z+4.5); menuKey.target.position.set(0,y+1,z);
  camera.position.set(1.35,y+1.38,z+3.65);
  camera.lookAt(_v3.set(0,y+0.92,z));
}
function simulateStep(dt){
  if (!paused){
    simT += dt;
    updateSpawner(dt);
    updateSwells(dt, simT);
    BARREL_VIS.update(simT);
    CREST_VIS.update(simT);
    if (started) updatePlayer(dt); else updateMenuPreview(dt);
    updateFace(dt);
    oceanUniforms.uTime.value = simT;
    if (terrain.material.userData.sh) terrain.material.userData.sh.uniforms.uT.value = simT;
    for (const p of palms){ p.userData.fronds.rotation.z = Math.sin(simT*0.85+p.userData.ph)*0.045; }
    sun.position.copy(player.pos).addScaledVector(CFG.sunDir, 190);
    sun.target.position.copy(player.pos);
    if (started) updateCamera(dt);
    updateCrestSpray(dt);
    SPRAY.tick();
    RINGS.tick();
    WETSAND.update(dt, simT);
    updateUnder();
    updateReflection();
    updatePost(dt);
    HUD.waveMeter(); HUD.cards(dt); HUD.radar(dt); HUD.tickAlert();
    SFX.tick(simT);
  }
}
function renderFrame(){
  // flag shadows only for the MAIN render (prepasses must not consume the update)
  shadowTick ^= 1;
  renderer.shadowMap.needsUpdate = quality==='high' || shadowTick===0;
  if (composer) composer.render(); else renderer.render(scene, camera);
}
// Deterministic hook used by automated playthroughs. Normal play remains clock-driven.
window.advanceTime = ms=>{
  const steps=Math.max(1,Math.round(clamp(Number(ms)||0,0,10000)/(1000/60)));
  for(let i=0;i<steps;i++) simulateStep(1/60);
  renderFrame();
};
renderer.setAnimationLoop(()=>{
  const dt = Math.min(clock.getDelta(), 0.05);
  if (!TEST_MODE) simulateStep(dt);
  renderFrame();
});
</script>
</body>
</html>
