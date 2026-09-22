<?php
/**
 * Kurage HR Post (khrpost) — 職（ポジション）を主役にした 職務・職責・目標・タスク の管理。
 *
 * 【核はこれだけ】
 *   職（品質管理責任者 など）に「職務・職責・目標」（常に3つ一緒に登録）がぶら下がり、
 *   目標に対して日々のタスク（やること／やったこと）を記録する。
 *   実績 = やったことを書いたタスクの件数。達成率 = 実績 ÷ 目標値。
 *
 * 【画面】
 *   ホーム（職の一覧） → 職の画面（職務・職責・目標の一覧と登録・編集・削除）
 *                          → タスクの画面（その目標のタスクの一覧と登録・編集・削除）
 *   管理者のみ: 任命（社員をいつからいつまでその職に就けたかの記録）
 *
 * 【設計の芯】
 *   1. 職は人がいなくても存在する — 空席（必要人数と任命の差）を警告する。
 *   2. タスクには記録者が残るので、後任は前任の記録をそのまま読める（それが引き継ぎ）。
 *   3. 達成率はコードが決定的に計算する。AIに採点させない。
 *   4. 全操作は khp_can() の関門1か所を通り、担当者は自分の職しか触れない。
 *   5. 社員マスタは持たない — 外部の employees.json を読むだけ（kvgwc か、自作のJSON）。
 *
 * 構成: khrpost.php(本体) + khrpost_config.php(設定) + khrpost_assets/ + khrpost_data/
 * PHP 7.0+ / pdo_sqlite。
 */

date_default_timezone_set('Asia/Tokyo');

if (!defined('KHP_SKIP_CONFIG')) {
    $cfg = __DIR__ . '/khrpost_config.php';
    if (!is_file($cfg)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'khrpost_config.php がありません。khrpost_config.php.example をコピーして作成してください。';
        exit;
    }
    require $cfg;
}

if (!defined('KHP_TITLE'))          { define('KHP_TITLE', 'Kurage HR Post'); }
if (!defined('KHP_BRAND_COLOR'))    { define('KHP_BRAND_COLOR', '#0089a1'); }
if (!defined('KHP_PASSWORD'))       { define('KHP_PASSWORD', ''); }
if (!defined('KHP_PASSWORD_HASH'))  { define('KHP_PASSWORD_HASH', ''); }
if (!defined('KHP_STAFF_PASSWORD')) { define('KHP_STAFF_PASSWORD', ''); }
if (!defined('KHP_EMPLOYEES_DIR')) { define('KHP_EMPLOYEES_DIR', ''); }
if (!defined('KHP_GRADE_TABLE'))    { define('KHP_GRADE_TABLE', 'S:110,A:100,B:85,C:70'); }
if (!defined('KHP_RATE_CAP'))       { define('KHP_RATE_CAP', 120); }
if (!defined('KHP_DEMO'))           { define('KHP_DEMO', false); }
if (!defined('KHP_DATA_DIR'))       { define('KHP_DATA_DIR', __DIR__ . '/khrpost_data'); }

/* ================= ユーティリティ ================= */

function khp_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function khp_now() { return date('Y-m-d H:i:s'); }
function khp_today() { return date('Y-m-d'); }

class KhpDenied extends Exception {}

/** 一覧用の省略表示。概要の先頭 $len 文字（改行より前）だけを出す。 */
function khp_snip($text, $len = 20) {
    $t = trim(strtok((string)$text, "\n"));
    return mb_strlen($t) > $len ? mb_substr($t, 0, $len) . '…' : $t;
}

/* ================= DB ================= */

function khp_pdo() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    if (!is_dir(KHP_DATA_DIR)) { @mkdir(KHP_DATA_DIR, 0700, true); }
    $ht = KHP_DATA_DIR . '/.htaccess';
    if (!is_file($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
    $pdo = new PDO('sqlite:' . KHP_DATA_DIR . '/khrpost.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=8000');
    khp_schema($pdo);
    return $pdo;
}

function khp_schema($pdo) {
    // 職: 人がいなくても存在する
    $pdo->exec("CREATE TABLE IF NOT EXISTS posts(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, memo TEXT NOT NULL DEFAULT '',
        headcount INTEGER NOT NULL DEFAULT 1,
        active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL)");
    // 社員テーブルは持たない（社員マスタは外部の employees.json が正）
    // 任命: いつからいつまで誰がその職か（履歴）
    $pdo->exec("CREATE TABLE IF NOT EXISTS assignments(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL, emp_key TEXT NOT NULL,
        from_date TEXT NOT NULL, to_date TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    // 職務・職責・目標（1行に3つを一緒に登録する）
    $pdo->exec("CREATE TABLE IF NOT EXISTS duties(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        job_memo TEXT NOT NULL,          -- 職務の概要
        resp_memo TEXT NOT NULL,         -- 職責の概要
        goal_memo TEXT NOT NULL,         -- 目標の概要
        target_value REAL NOT NULL DEFAULT 0,
        unit TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    // タスク: 目標に対する やること／やったこと。記録者が残る。
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL, duty_id INTEGER NOT NULL DEFAULT 0,
        done_on TEXT NOT NULL, todo TEXT NOT NULL DEFAULT '',
        done TEXT NOT NULL DEFAULT '', emp_key TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
}

/* ================= 権限（宣言関門・ここ1か所で判定） ================= */

function khp_can($actor, $action) {
    $rules = array(
        // 担当者: 任命された自分の職について、職務・職責・目標とタスクを登録・編集・削除できる
        'staff' => array('duty.create', 'duty.edit', 'task.create', 'task.edit'),
        'admin' => array('duty.create', 'duty.edit', 'task.create', 'task.edit',
                         'post.manage', 'assignment.manage'),
        'ai'    => array('task.create'),   // AI連携はタスクの下書きだけ
    );
    return isset($rules[$actor]) && in_array($action, $rules[$actor], true);
}

function khp_assert($actor, $action) {
    if (!khp_can($actor, $action)) {
        throw new KhpDenied($actor . ' は ' . $action . ' を許可されていません');
    }
}

/** いま任命されている職のID一覧（担当者の所有範囲）。 */
function khp_my_post_ids($empKey, $today = null) {
    if ($empKey === '') { return array(); }
    $today = $today ? $today : khp_today();
    $st = khp_pdo()->prepare("SELECT post_id FROM assignments
        WHERE emp_key=? AND from_date<=? AND (to_date='' OR to_date>=?)");
    $st->execute(array($empKey, $today, $today));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[] = (int)$r['post_id']; }
    return $out;
}

function khp_owns_post($empKey, $postId) {
    return in_array((int)$postId, khp_my_post_ids($empKey), true);
}

/** 担当者は自分の職だけ。管理者は制限なし。それ以外は拒否。 */
function khp_assert_post($actor, $empKey, $postId) {
    if ($actor === 'admin') { return; }
    if ($actor === 'staff' && khp_owns_post($empKey, $postId)) { return; }
    throw new KhpDenied('自分が任命されている職についてのみ操作できます');
}

/* ================= 社員マスタ（外部の employees.json を読むだけ） =================
 *
 * khrpost は社員を持たない。誰がいるかは会社の情報であって、職の管理システムが
 * 二重に抱えると必ずズレる（退職者が残る・氏名が食い違う）。
 *
 * KHP_EMPLOYEES_DIR に、employees.json のあるディレクトリを指す。
 *   - kvgwc を使うなら、その kvgwc_data をそのまま指すだけ
 *   - kvgwc が無いなら、同じ形式の JSON を自分で書き出せばよい
 * 形式: {"employees":[{"id":"e1","no":"E001","name":"山田 太郎","status":"active"}]}
 *   id     … 社員を一意に識別する文字列（任命・記録者はこれで紐づく。変えないこと）
 *   no     … 社員コード。担当者ログインのIDになる
 *   status … active 以外（休職・退職）はログインできない
 * 部署はここでは扱わない（部署は人事側で見る情報で、職の管理には要らない）。
 */

function khp_emp_source() {
    return KHP_EMPLOYEES_DIR === '' ? '' : rtrim(KHP_EMPLOYEES_DIR, '/') . '/employees.json';
}

function khp_employees() {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = array();
    $path = khp_emp_source();
    if ($path === '' || !is_file($path)) { return $cache; }
    $json = json_decode((string)file_get_contents($path), true);
    $rows = (is_array($json) && isset($json['employees'])) ? $json['employees'] : array();
    foreach ($rows as $e) {
        if (!isset($e['id']) || $e['id'] === '') { continue; }   // idが無い行は紐づけられない
        $cache[] = array('key' => 'kv:' . $e['id'],
                         'code' => isset($e['no']) ? (string)$e['no'] : '',
                         'name' => isset($e['name']) ? (string)$e['name'] : '',
                         'status' => isset($e['status']) ? (string)$e['status'] : 'active');
    }
    return $cache;
}

function khp_emp($key) {
    foreach (khp_employees() as $e) { if ($e['key'] === $key) { return $e; } }
    return null;
}

function khp_emp_name($key) {
    if ($key === '') { return '管理者'; }
    $e = khp_emp($key);
    // マスタから消された社員の記録も、記録そのものは残す（履歴を欠かさないため）
    return $e ? $e['name'] : '（マスタにない社員）';
}

function khp_emp_by_code($code) {
    $code = trim($code);
    if ($code === '') { return null; }
    foreach (khp_employees() as $e) { if ($e['code'] === $code) { return $e; } }
    return null;
}

/** 社員マスタが読める状態か。読めなければ khrpost は誰も任命できない。 */
function khp_emp_master_ok() {
    $path = khp_emp_source();
    return $path !== '' && is_file($path);
}

/* ================= 達成率（決定的。実績＝やったことの件数） ================= */

function khp_rate($target, $actual, $cap = null) {
    $cap = ($cap === null) ? (float)KHP_RATE_CAP : (float)$cap;
    $target = (float)$target; $actual = (float)$actual;
    if ($target <= 0.0) { return null; }              // 目標値なしは達成率を出さない
    $r = $actual / $target * 100.0;
    if ($r < 0) { $r = 0.0; }
    return min($r, $cap);
}

/** 目標の実績: やったことが書かれたタスクの件数。 */
function khp_duty_actual($dutyId) {
    $st = khp_pdo()->prepare("SELECT COUNT(*) FROM tasks WHERE duty_id=? AND done<>''");
    $st->execute(array($dutyId));
    return (int)$st->fetchColumn();
}

/** スコア(%)→等級。KHP_GRADE_TABLE = 'S:110,A:100,B:85,C:70'（下回ればD）。 */
function khp_grade($score) {
    if ($score === null) { return '-'; }
    $pairs = array();
    foreach (explode(',', KHP_GRADE_TABLE) as $p) {
        $kv = explode(':', trim($p));
        if (count($kv) === 2) { $pairs[] = array(trim($kv[0]), (float)$kv[1]); }
    }
    usort($pairs, function ($a, $b) { return ($a[1] < $b[1]) ? 1 : -1; });
    foreach ($pairs as $p) { if ($score >= $p[1]) { return $p[0]; } }
    return 'D';
}

/** 職のスコア: 各目標の達成率の単純平均（目標値の無いものは除外）。 */
function khp_post_score($postId) {
    $rates = array(); $duties = array();
    $st = khp_pdo()->prepare('SELECT * FROM duties WHERE post_id=? ORDER BY id');
    $st->execute(array($postId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $d['actual'] = khp_duty_actual($d['id']);
        $d['rate'] = khp_rate($d['target_value'], $d['actual']);
        if ($d['rate'] !== null) { $rates[] = $d['rate']; }
        $duties[] = $d;
    }
    $score = $rates ? array_sum($rates) / count($rates) : null;
    return array('score' => $score, 'grade' => khp_grade($score), 'duties' => $duties);
}

/** 職の充足状態（保存せず算出）。 */
function khp_post_status($post, $today = null) {
    $today = $today ? $today : khp_today();
    $st = khp_pdo()->prepare("SELECT COUNT(*) FROM assignments
        WHERE post_id=? AND from_date<=? AND (to_date='' OR to_date>=?)");
    $st->execute(array($post['id'], $today, $today));
    $n = (int)$st->fetchColumn();
    $need = (int)$post['headcount'];
    if ($n < $need) { return array('state' => 'vacant', 'filled' => $n, 'need' => $need); }
    if ($n > $need) { return array('state' => 'over',   'filled' => $n, 'need' => $need); }
    return array('state' => 'filled', 'filled' => $n, 'need' => $need);
}

/** いまの担当者名（複数可）。 */
function khp_post_holders($postId, $today = null) {
    $today = $today ? $today : khp_today();
    $st = khp_pdo()->prepare("SELECT emp_key FROM assignments
        WHERE post_id=? AND from_date<=? AND (to_date='' OR to_date>=?) ORDER BY id");
    $st->execute(array($postId, $today, $today));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[] = khp_emp_name($r['emp_key']); }
    return $out;
}

/* ================= 認証 ================= */

function khp_session_start() {
    if (session_status() === PHP_SESSION_NONE) { session_name('khrpost'); session_start(); }
}
function khp_login_admin($password) {
    if (KHP_PASSWORD_HASH !== '') { return password_verify($password, KHP_PASSWORD_HASH); }
    return KHP_PASSWORD !== '' && hash_equals(KHP_PASSWORD, (string)$password);
}
function khp_actor() { khp_session_start(); return isset($_SESSION['actor']) ? $_SESSION['actor'] : ''; }
function khp_me()    { khp_session_start(); return isset($_SESSION['emp_key']) ? $_SESSION['emp_key'] : ''; }
function khp_csrf() {
    khp_session_start();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function khp_csrf_check() {
    khp_session_start();
    $t = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        throw new KhpDenied('CSRFトークンが不正です');
    }
}

/* ================= 画面部品 ================= */

function khp_head($title) {
    $t = khp_h(KHP_TITLE . ' — ' . $title);
    $c = khp_h(KHP_BRAND_COLOR);
    echo "<!doctype html><html lang=\"ja\"><head><meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">
<meta name=\"robots\" content=\"noindex,nofollow\"><title>$t</title><style>
:root{
  --brand:$c;--brand-dark:#00707f;--brand-weak:#e9f5f7;
  --ink:#1b2b33;--muted:#66777f;--line:#e2e9ec;--bg:#f4f7f8;--surface:#ffffff;
  --danger:#c0392b;--danger-weak:#fdeeec;--ok:#2e7d32;--ok-weak:#e9f5ea;
  --warn-ink:#8a6d00;--warn-weak:#fff7df;
  --radius:12px;--shadow:0 1px 3px rgba(20,40,50,.06),0 1px 2px rgba(20,40,50,.04);
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:'Helvetica Neue',Arial,'Hiragino Kaku Gothic ProN','Hiragino Sans','Noto Sans JP',Meiryo,system-ui,sans-serif;
  color:var(--ink);background:var(--bg);font-size:14.5px;line-height:1.65}
a{color:var(--brand);text-decoration:none}
a:hover{text-decoration:underline}
/* ---- ヘッダー ---- */
.app{position:sticky;top:0;z-index:10;background:var(--brand);color:#fff;
  display:flex;align-items:center;gap:10px;padding:10px 20px;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.app img{width:32px;height:32px;border-radius:8px;background:#fff}
.app h1{font-size:15.5px;margin:0;font-weight:700;letter-spacing:.02em}
.app nav{margin-left:auto;display:flex;gap:4px}
.app nav a{color:#fff;font-size:13px;padding:6px 10px;border-radius:7px;opacity:.92}
.app nav a:hover{background:rgba(255,255,255,.14);text-decoration:none;opacity:1}
/* ---- レイアウト ---- */
.page{max-width:1040px;margin:0 auto;padding:22px 20px 40px}
.crumb{font-size:12.5px;color:var(--muted);margin:2px 0 10px}
.crumb a{color:var(--muted)}
.page-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 14px}
.page-head h1{font-size:21px;margin:0;font-weight:700;letter-spacing:.01em}
.page-head .spacer{flex:1}
.section{display:flex;align-items:baseline;gap:8px;margin:26px 0 10px}
.section h2{font-size:15.5px;margin:0;font-weight:700}
.section h2::before{content:'';display:inline-block;width:4px;height:14px;background:var(--brand);
  border-radius:2px;margin-right:8px;vertical-align:-2px}
.section .count{font-size:12.5px;color:var(--muted)}
/* ---- カード・表 ---- */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:16px 18px;margin:10px 0;box-shadow:var(--shadow)}
.table-wrap{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow);overflow-x:auto;margin:10px 0}
table{width:100%;border-collapse:collapse;font-size:13.8px}
th{font-size:12px;color:var(--muted);font-weight:600;text-align:left;
  padding:10px 14px;border-bottom:2px solid var(--line);background:#fbfdfd;white-space:nowrap}
td{padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#fafcfc}
/* ---- バッジ・進捗 ---- */
.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;
  background:var(--brand-weak);color:var(--brand-dark);white-space:nowrap}
.badge.ok{background:var(--ok-weak);color:var(--ok)}
.badge.warn{background:var(--warn-weak);color:var(--warn-ink)}
.badge.danger{background:var(--danger-weak);color:var(--danger)}
.bar{height:6px;background:#e8eef0;border-radius:999px;overflow:hidden;margin-top:6px;min-width:80px}
.bar>span{display:block;height:100%;background:var(--brand);border-radius:999px}
.alert{background:var(--danger-weak);border:1px solid #f3cdc7;color:var(--danger);
  border-radius:var(--radius);padding:12px 16px;margin:10px 0;font-size:13.8px}
.alert a{color:var(--danger);font-weight:600}
/* ---- フォーム ---- */
label{display:block;margin:10px 0 4px;font-size:12.5px;color:var(--muted);font-weight:600}
input,select,textarea{font:inherit;color:var(--ink);padding:8px 10px;border:1px solid #cfdade;
  border-radius:8px;background:#fff;max-width:100%}
input:focus,select:focus,textarea:focus{outline:2px solid var(--brand-weak);border-color:var(--brand)}
textarea{width:100%;resize:vertical}
.form-title{font-size:14.5px;font-weight:700;margin:0 0 2px}
.form-note{font-size:12.5px;color:var(--muted);margin:0 0 4px}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
/* ---- ボタン ---- */
.btn{display:inline-block;background:var(--brand);color:#fff;border:0;border-radius:8px;
  padding:9px 16px;font-size:13.8px;font-weight:600;cursor:pointer;text-decoration:none;line-height:1.4}
.btn:hover{background:var(--brand-dark);text-decoration:none}
.btn.ghost{background:#fff;color:var(--brand-dark);border:1px solid #c6dde2}
.btn.ghost:hover{background:var(--brand-weak)}
.btn.danger{background:#fff;color:var(--danger);border:1px solid #eac5bf}
.btn.danger:hover{background:var(--danger-weak)}
.btn.sm{padding:4px 10px;font-size:12.5px;border-radius:7px}
.actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.actions form{margin:0}
.muted{color:var(--muted);font-size:12.8px}
.empty{border:1.5px dashed var(--line);border-radius:var(--radius);padding:22px;
  text-align:center;color:var(--muted);background:#fafcfc;margin:10px 0}
.tabs{display:flex;gap:4px;border-bottom:2px solid var(--line);margin-bottom:14px}
.tabs button{font:inherit;font-size:13.5px;font-weight:600;color:var(--muted);background:none;border:0;
  border-bottom:2px solid transparent;margin-bottom:-2px;padding:8px 12px;cursor:pointer;border-radius:6px 6px 0 0}
.tabs button:hover{color:var(--brand-dark);background:var(--brand-weak)}
.tabs button.active{color:var(--brand-dark);border-bottom-color:var(--brand)}
.tabpane{display:none}
.tabpane.active{display:block}
.ref-item{border-bottom:1px solid var(--line);padding:10px 2px;display:flex;gap:12px;align-items:flex-start}
.ref-item:last-child{border-bottom:0}
.ref-main{flex:1;min-width:0;font-size:13.5px}
.ref-meta{font-size:12px;color:var(--muted);margin-bottom:2px}
footer{max-width:1040px;margin:0 auto;padding:0 20px 26px;color:var(--muted);font-size:12px}
@media(max-width:640px){.page{padding:16px 12px 32px}.page-head h1{font-size:18px}}
</style></head><body>";
}

function khp_nav() {
    $actor = khp_actor();
    echo '<header class="app"><img src="khrpost_assets/kurage_mascot.png" alt=""><h1>' . khp_h(KHP_TITLE) . '</h1><nav>';
    if ($actor !== '') {
        echo '<a href="?">ホーム</a>';
        if ($actor === 'admin') { echo '<a href="?p=assign">任命</a>'; }
        echo '<a href="?p=logout">ログアウト</a>';
    }
    echo '</nav></header><div class="page">';
}

function khp_foot() {
    echo '</div><footer>' . khp_h(KHP_TITLE)
        . ' — 職務・職責・目標・タスクの管理 / Kurage</footer>
<script>
function khpTab(btn){
  var card=btn.closest(".card");
  card.querySelectorAll(".tabs button").forEach(function(b){b.classList.remove("active")});
  btn.classList.add("active");
  card.querySelectorAll(".tabpane").forEach(function(p){p.classList.remove("active")});
  card.querySelector("[data-pane=\'"+btn.dataset.tab+"\']").classList.add("active");
}
/* 参照行の内容を、同じカード内の入力欄へ転記して入力タブに戻る */
function khpFill(btn){
  var card=btn.closest(".card"), d=btn.dataset;
  ["todo","job","resp","goal","target","unit"].forEach(function(k){
    if(d[k]!==undefined){
      var el=card.querySelector("[data-f=\'"+k+"\']");
      if(el){ el.value=decodeURIComponent(d[k]); }
    }
  });
  var first=card.querySelector(".tabs button");
  if(first){ khpTab(first); }
  var f=card.querySelector("[data-f]");
  if(f){ f.focus(); }
}
</script>' . ((($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp') ? '<p style="text-align:center;font-size:13px;margin:14px 0;color:#5d6b7a">これはデモです。<a href="https://kappstore.exbridge.jp/app.php?id=56bf3ddd46b5a457&amp;ref=khrpost" target="_blank" rel="noopener">この製品をオンプレミスで導入する（商品ページ）</a></p>' : '') . '</body></html>';
}

function khp_section($title, $count = null) {
    echo '<div class="section"><h2>' . khp_h($title) . '</h2>'
        . ($count !== null ? '<span class="count">' . (int)$count . '件</span>' : '') . '</div>';
}

function khp_login_page($msg = '') {
    khp_head('ログイン');
    echo '<header class="app"><img src="khrpost_assets/kurage_mascot.png" alt=""><h1>' . khp_h(KHP_TITLE) . '</h1></header><div class="page">';
    echo '<div class="card" style="max-width:420px;margin:48px auto">';
    echo '<p class="form-title" style="margin-bottom:8px">ログイン</p>';
    if ($msg !== '') { echo '<div class="alert">' . khp_h($msg) . '</div>'; }
    echo '<form method="post"><input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '">
        <input type="hidden" name="a" value="login">
        <label>社員コード（担当者）</label><input name="code" style="width:100%">
        <label>パスワード</label><input type="password" name="password" style="width:100%" required>
        <p class="muted" style="margin-top:10px">管理者はコードを空欄にして管理者パスワードを入力してください。</p>
        <p style="margin-top:14px"><button class="btn" style="width:100%">ログイン</button></p></form></div>';
    khp_foot();
}

/** 削除ボタン（確認つきの小さなフォーム） */
function khp_delete_btn($action, $hidden, $label, $confirm) {
    echo '<form method="post" onsubmit="return confirm(' . khp_h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')">'
        . '<input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '">'
        . '<input type="hidden" name="a" value="' . khp_h($action) . '">';
    foreach ($hidden as $k => $v) {
        echo '<input type="hidden" name="' . khp_h($k) . '" value="' . khp_h($v) . '">';
    }
    echo '<button class="btn danger sm">' . khp_h($label) . '</button></form>';
}

/* ================= ここから画面 ================= */

if (PHP_SAPI === 'cli') { return; }

// 社員マスタが無ければ誰も任命できない。黙って空の画面を出さず、設置手順を出す。
if (!khp_emp_master_ok()) {
    khp_head('設定が必要です');
    echo '<header class="app"><img src="khrpost_assets/kurage_mascot.png" alt=""><h1>' . khp_h(KHP_TITLE) . '</h1></header><div class="page">';
    echo '<div class="card" style="max-width:660px;margin:48px auto">
      <p class="form-title">社員マスタが見つかりません</p>
      <p class="muted">khrpost は社員を持ちません。社員の一覧は外部の <code>employees.json</code> を読みます。
      khrpost_config.php の <code>KHP_EMPLOYEES_DIR</code> に、そのファイルがあるディレクトリを指定してください。</p>'
      . (khp_emp_source() !== '' ? '<div class="alert">指定された場所に employees.json がありません。</div>'
                                 : '<div class="alert">KHP_EMPLOYEES_DIR が空です。</div>') . '
      <p class="muted">用意のしかたは2通りです。</p>
      <ul class="muted">
        <li>Kurage Vibe Groupware Core（kvgwc）を使う → その <code>kvgwc_data</code> のパスを指定するだけ</li>
        <li>kvgwc を使わない → 下の形式の JSON を自分で置く（バイブコーディングで作れます）</li>
      </ul>
      <pre style="background:#f6f8f9;padding:12px;border-radius:8px;overflow:auto;font-size:12.5px">{"employees":[
  {"id":"e1","no":"E001","name":"山田 太郎","status":"active"},
  {"id":"e2","no":"E002","name":"佐藤 花子","status":"active"}
]}</pre>
      <p class="muted"><b>id</b> は社員を一意に識別する文字列で、任命と記録者がこれで紐づきます（後から変えないこと）。
      <b>no</b> は担当者ログインのID。<b>status</b> が active 以外の人はログインできません。</p>
    </div>';
    khp_foot();
    exit;
}

khp_session_start();
$pdo = khp_pdo();
$page = isset($_GET['p']) ? $_GET['p'] : 'home';
$action = isset($_POST['a']) ? $_POST['a'] : '';

try {
    if ($action === 'login') {
        khp_csrf_check();
        $code = isset($_POST['code']) ? trim($_POST['code']) : '';
        $pw   = isset($_POST['password']) ? (string)$_POST['password'] : '';
        if ($code === '') {
            if (!khp_login_admin($pw)) { khp_login_page('管理者パスワードが違います'); exit; }
            $_SESSION['actor'] = 'admin'; $_SESSION['emp_key'] = '';
        } else {
            $emp = khp_emp_by_code($code);
            if (!$emp || $emp['status'] !== 'active') { khp_login_page('社員コードが見つからないか、在籍中ではありません'); exit; }
            if (KHP_STAFF_PASSWORD === '' || !hash_equals(KHP_STAFF_PASSWORD, $pw)) {
                khp_login_page('パスワードが違います'); exit;
            }
            $_SESSION['actor'] = 'staff'; $_SESSION['emp_key'] = $emp['key'];
        }
        header('Location: ?'); exit;
    }
    if ($page === 'logout') { session_destroy(); header('Location: ?'); exit; }
    if (khp_actor() === '') { khp_login_page(); exit; }
    $actor = khp_actor();
    $me = khp_me();

    /* ---------- 更新（登録・編集・削除） ---------- */
    if ($action !== '') {
        khp_csrf_check();

        /* --- 職 --- */
        if ($action === 'post.save' || $action === 'post.update') {
            khp_assert($actor, 'post.manage');
            $name = trim($_POST['name']); $memo = trim($_POST['memo']);
            $hc = max(0, (int)$_POST['headcount']);
            if ($action === 'post.save') {
                $pdo->prepare('INSERT INTO posts(name,memo,headcount,created_at) VALUES(?,?,?,?)')
                    ->execute(array($name, $memo, $hc, khp_now()));
                header('Location: ?p=post&id=' . (int)$pdo->lastInsertId()); exit;
            }
            $pdo->prepare('UPDATE posts SET name=?, memo=?, headcount=? WHERE id=?')
                ->execute(array($name, $memo, $hc, (int)$_POST['id']));
            header('Location: ?p=post&id=' . (int)$_POST['id']); exit;
        }
        if ($action === 'post.delete') {
            khp_assert($actor, 'post.manage');
            $id = (int)$_POST['id'];
            $pdo->prepare('DELETE FROM tasks WHERE post_id=?')->execute(array($id));
            $pdo->prepare('DELETE FROM duties WHERE post_id=?')->execute(array($id));
            $pdo->prepare('DELETE FROM assignments WHERE post_id=?')->execute(array($id));
            $pdo->prepare('DELETE FROM posts WHERE id=?')->execute(array($id));
            header('Location: ?'); exit;
        }

        /* --- 職務・職責・目標 --- */
        if ($action === 'duty.save' || $action === 'duty.update') {
            khp_assert($actor, $action === 'duty.save' ? 'duty.create' : 'duty.edit');
            $postId = (int)$_POST['post_id'];
            khp_assert_post($actor, $me, $postId);
            $job = trim($_POST['job_memo']); $resp = trim($_POST['resp_memo']); $goal = trim($_POST['goal_memo']);
            if ($job === '' || $resp === '' || $goal === '') {
                throw new KhpDenied('職務・職責・目標は3つそろえて入力してください');
            }
            $tv = max(0, (float)$_POST['target_value']); $unit = trim($_POST['unit']);
            if ($action === 'duty.save') {
                $pdo->prepare('INSERT INTO duties(post_id,job_memo,resp_memo,goal_memo,target_value,unit,created_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute(array($postId, $job, $resp, $goal, $tv, $unit, khp_now()));
            } else {
                $pdo->prepare('UPDATE duties SET job_memo=?, resp_memo=?, goal_memo=?, target_value=?, unit=? WHERE id=? AND post_id=?')
                    ->execute(array($job, $resp, $goal, $tv, $unit, (int)$_POST['duty_id'], $postId));
            }
            header('Location: ?p=post&id=' . $postId); exit;
        }
        if ($action === 'duty.delete') {
            khp_assert($actor, 'duty.edit');
            $dutyId = (int)$_POST['duty_id'];
            $dq = $pdo->prepare('SELECT post_id FROM duties WHERE id=?');
            $dq->execute(array($dutyId));
            $postId = (int)$dq->fetchColumn();
            if ($postId === 0) { throw new KhpDenied('対象が見つかりません'); }
            khp_assert_post($actor, $me, $postId);
            $pdo->prepare('DELETE FROM tasks WHERE duty_id=?')->execute(array($dutyId));
            $pdo->prepare('DELETE FROM duties WHERE id=?')->execute(array($dutyId));
            header('Location: ?p=post&id=' . $postId); exit;
        }

        /* --- タスク --- */
        if ($action === 'task.save' || $action === 'task.update') {
            khp_assert($actor, $action === 'task.save' ? 'task.create' : 'task.edit');
            $dutyId = (int)$_POST['duty_id'];
            $dq = $pdo->prepare('SELECT post_id FROM duties WHERE id=?');
            $dq->execute(array($dutyId));
            $postId = (int)$dq->fetchColumn();
            if ($postId === 0) { throw new KhpDenied('目標が見つかりません'); }
            khp_assert_post($actor, $me, $postId);
            $todo = trim($_POST['todo']); $done = trim($_POST['done']);
            if ($todo === '' && $done === '') { throw new KhpDenied('「やること」か「やったこと」のどちらかを書いてください'); }
            if ($action === 'task.save') {
                $pdo->prepare('INSERT INTO tasks(post_id,duty_id,done_on,todo,done,emp_key,created_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute(array($postId, $dutyId, $_POST['done_on'], $todo, $done, $me, khp_now()));
            } else {
                $pdo->prepare('UPDATE tasks SET done_on=?, todo=?, done=? WHERE id=? AND duty_id=?')
                    ->execute(array($_POST['done_on'], $todo, $done, (int)$_POST['task_id'], $dutyId));
            }
            header('Location: ?p=tasks&id=' . $dutyId); exit;
        }
        if ($action === 'task.delete') {
            khp_assert($actor, 'task.edit');
            $tq = $pdo->prepare('SELECT duty_id, post_id FROM tasks WHERE id=?');
            $tq->execute(array((int)$_POST['task_id']));
            $trow = $tq->fetch(PDO::FETCH_ASSOC);
            if (!$trow) { throw new KhpDenied('対象が見つかりません'); }
            khp_assert_post($actor, $me, (int)$trow['post_id']);
            $pdo->prepare('DELETE FROM tasks WHERE id=?')->execute(array((int)$_POST['task_id']));
            header('Location: ?p=tasks&id=' . (int)$trow['duty_id']); exit;
        }

        /* --- 任命 --- */
        if ($action === 'assign.save') {
            khp_assert($actor, 'assignment.manage');
            $pdo->prepare('INSERT INTO assignments(post_id,emp_key,from_date,to_date,created_at) VALUES(?,?,?,?,?)')
                ->execute(array((int)$_POST['post_id'], $_POST['emp_key'], $_POST['from_date'],
                                trim($_POST['to_date']), khp_now()));
            header('Location: ?p=assign'); exit;
        }
        if ($action === 'assign.end') {
            khp_assert($actor, 'assignment.manage');
            $pdo->prepare('UPDATE assignments SET to_date=? WHERE id=?')
                ->execute(array($_POST['to_date'], (int)$_POST['id']));
            header('Location: ?p=assign'); exit;
        }
        if ($action === 'assign.delete') {
            khp_assert($actor, 'assignment.manage');
            $pdo->prepare('DELETE FROM assignments WHERE id=?')->execute(array((int)$_POST['id']));
            header('Location: ?p=assign'); exit;
        }
    }

    /* ---------- ホーム: 職の一覧 ---------- */
    if ($page === 'home') {
        khp_head('ホーム'); khp_nav();
        echo '<div class="page-head"><h1>職の一覧</h1></div>';
        $posts = $pdo->query('SELECT * FROM posts WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $vac = array();
        foreach ($posts as $p) {
            if (khp_post_status($p)['state'] === 'vacant') { $vac[] = $p; }
        }
        if ($vac) {
            $links = array();
            foreach ($vac as $p) { $links[] = '<a href="?p=post&id=' . (int)$p['id'] . '">' . khp_h($p['name']) . '</a>'; }
            echo '<div class="alert"><b>担当者がいない職が ' . count($vac) . ' 件あります</b>： ' . implode('、 ', $links) . '</div>';
        }
        if ($posts) {
            echo '<div class="table-wrap"><table><tr><th>職</th><th>担当</th><th style="width:180px">達成率</th></tr>';
            foreach ($posts as $p) {
                $sc = khp_post_score($p['id']);
                $holders = khp_post_holders($p['id']);
                echo '<tr><td><a href="?p=post&id=' . (int)$p['id'] . '"><b>' . khp_h($p['name']) . '</b></a>'
                    . ($p['memo'] !== '' ? '<div class="muted">' . khp_h(khp_snip($p['memo'], 44)) . '</div>' : '') . '</td>'
                    . '<td>' . ($holders ? khp_h(implode('、', $holders)) : '<span class="badge danger">担当者がいません</span>') . '</td>'
                    . '<td>' . ($sc['score'] === null
                        ? '<span class="muted">目標未設定</span>'
                        : '<b>' . number_format($sc['score'], 0) . '%</b> <span class="badge">' . khp_h($sc['grade']) . '</span>'
                          . '<div class="bar"><span style="width:' . min(100, $sc['score']) . '%"></span></div>') . '</td></tr>';
            }
            echo '</table></div>';
        } else {
            echo '<div class="empty">まだ職が登録されていません。</div>';
        }
        if ($actor === 'admin') {
            khp_section('職を追加');
            echo '<div class="card"><form method="post">
              <input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '"><input type="hidden" name="a" value="post.save">
              <div class="row"><div style="flex:1;min-width:240px"><label>職の名前 *</label><input name="name" required placeholder="品質管理責任者" style="width:100%"></div>
              <div><label>必要人数</label><input type="number" name="headcount" value="1" min="0" style="width:90px"></div></div>
              <label>概要</label><textarea name="memo" rows="3" placeholder="どんな職か、ひとことで。"></textarea>
              <p style="margin-top:12px"><button class="btn">追加する</button></p></form></div>';
        }
        khp_foot();
    }
    /* ---------- 職の画面: 職務・職責・目標の一覧と登録・編集 ---------- */
    elseif ($page === 'post') {
        $id = (int)$_GET['id'];
        $st = $pdo->prepare('SELECT * FROM posts WHERE id=?'); $st->execute(array($id));
        $post = $st->fetch(PDO::FETCH_ASSOC);
        if (!$post) { throw new Exception('職が見つかりません'); }
        $canEdit = ($actor === 'admin') || ($actor === 'staff' && khp_owns_post($me, $id));
        $sc = khp_post_score($id);
        $holders = khp_post_holders($id);
        $editDuty = null;
        if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
            $eq = $pdo->prepare('SELECT * FROM duties WHERE id=? AND post_id=?');
            $eq->execute(array((int)$_GET['edit'], $id));
            $editDuty = $eq->fetch(PDO::FETCH_ASSOC);
        }
        $editPost = ($actor === 'admin' && isset($_GET['edit']) && $_GET['edit'] === 'post');

        /* --- 歴代の担当者から開く「この担当者の記録」ビュー --- */
        if (isset($_GET['who']) && $_GET['who'] !== '') {
            $who = (string)$_GET['who'];
            $whoName = khp_emp_name($who);
            $aq = $pdo->prepare('SELECT * FROM assignments WHERE post_id=? AND emp_key=? ORDER BY from_date');
            $aq->execute(array($id, $who));
            $terms = $aq->fetchAll(PDO::FETCH_ASSOC);
            $tq = $pdo->prepare('SELECT t.*, d.job_memo FROM tasks t LEFT JOIN duties d ON d.id=t.duty_id
                WHERE t.post_id=? AND t.emp_key=? ORDER BY t.done_on DESC, t.id DESC');
            $tq->execute(array($id, $who));
            $wrows = $tq->fetchAll(PDO::FETCH_ASSOC);
            khp_head($whoName . ' の記録'); khp_nav();
            echo '<div class="crumb"><a href="?">ホーム</a> › <a href="?p=post&id=' . $id . '">' . khp_h($post['name']) . '</a> › ' . khp_h($whoName) . ' の記録</div>';
            echo '<div class="page-head"><h1>' . khp_h($whoName) . ' の記録</h1>';
            foreach ($terms as $tm) {
                echo '<span class="badge">' . khp_h($tm['from_date']) . ' 〜 ' . ($tm['to_date'] !== '' ? khp_h($tm['to_date']) : '現任') . '</span>';
            }
            echo '</div>';
            echo '<p class="muted" style="margin:0 0 8px">' . khp_h($post['name']) . ' としての在任中の記録です。後任はここを読めば、前任が何をしていたか分かります。</p>';
            if ($wrows) {
                echo '<div class="table-wrap"><table><tr><th style="width:100px">日付</th><th style="width:180px">職務</th><th>やること</th><th>やったこと</th></tr>';
                foreach ($wrows as $t) {
                    echo '<tr><td style="white-space:nowrap">' . khp_h($t['done_on']) . '</td>'
                        . '<td>' . khp_h(khp_snip($t['job_memo'], 16)) . '</td>'
                        . '<td>' . nl2br(khp_h($t['todo'])) . '</td>'
                        . '<td>' . ($t['done'] !== '' ? nl2br(khp_h($t['done'])) : '<span class="badge warn">未実施</span>') . '</td></tr>';
                }
                echo '</table></div>';
            } else {
                echo '<div class="empty">この担当者の記録はありません。</div>';
            }
            khp_foot();
            exit;
        }

        khp_head($post['name']); khp_nav();
        echo '<div class="crumb"><a href="?">ホーム</a> › ' . khp_h($post['name']) . '</div>';
        echo '<div class="page-head"><h1>' . khp_h($post['name']) . '</h1>';
        echo $holders
            ? '<span class="badge ok">担当: ' . khp_h(implode('、', $holders)) . '</span>'
            : '<span class="badge danger">担当者がいません</span>';
        if ($sc['score'] !== null) {
            echo '<span class="badge">' . number_format($sc['score'], 0) . '% ' . khp_h($sc['grade']) . '</span>';
        }
        echo '<span class="spacer"></span>';
        if ($actor === 'admin' && !$editPost) {
            echo '<a class="btn ghost sm" href="?p=post&id=' . $id . '&edit=post">職を編集</a>';
        }
        echo '</div>';

        if ($editPost) {
            echo '<div class="card"><form method="post">
              <p class="form-title">職を編集</p>
              <input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '"><input type="hidden" name="a" value="post.update">
              <input type="hidden" name="id" value="' . $id . '">
              <div class="row"><div style="flex:1;min-width:240px"><label>職の名前 *</label><input name="name" required value="' . khp_h($post['name']) . '" style="width:100%"></div>
              <div><label>必要人数</label><input type="number" name="headcount" value="' . (int)$post['headcount'] . '" min="0" style="width:90px"></div></div>
              <label>概要</label><textarea name="memo" rows="3">' . khp_h($post['memo']) . '</textarea>
              <p style="margin-top:12px"><button class="btn">保存する</button>
              <a class="btn ghost" href="?p=post&id=' . $id . '">キャンセル</a></p></form>';
            khp_delete_btn('post.delete', array('id' => $id), 'この職を削除する',
                'この職と、職務・職責・目標・タスク・任命の記録をすべて削除します。よろしいですか？');
            echo '</div>';
        } elseif ($post['memo'] !== '') {
            echo '<div class="card" style="padding:12px 18px">' . nl2br(khp_h($post['memo'])) . '</div>';
        }

        khp_section('職務・職責・目標', count($sc['duties']));
        if ($sc['duties']) {
            echo '<div class="table-wrap"><table><tr><th>職務</th><th>職責</th><th>目標</th><th>目標値</th><th>実績</th><th style="width:120px">達成率</th><th style="width:170px">操作</th></tr>';
            foreach ($sc['duties'] as $d) {
                $tc = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE duty_id=?');
                $tc->execute(array($d['id']));
                echo '<tr><td>' . nl2br(khp_h($d['job_memo'])) . '</td>'
                    . '<td>' . nl2br(khp_h($d['resp_memo'])) . '</td>'
                    . '<td>' . nl2br(khp_h($d['goal_memo'])) . '</td>'
                    . '<td style="white-space:nowrap">' . ((float)$d['target_value'] > 0 ? (float)$d['target_value'] . khp_h($d['unit']) : '-') . '</td>'
                    . '<td style="white-space:nowrap">' . (int)$d['actual'] . khp_h($d['unit']) . '</td>'
                    . '<td>' . ($d['rate'] === null ? '<span class="muted">-</span>' : '<b>' . number_format($d['rate'], 0) . '%</b>'
                        . '<div class="bar"><span style="width:' . min(100, $d['rate']) . '%"></span></div>') . '</td>'
                    . '<td><div class="actions"><a class="btn ghost sm" href="?p=tasks&id=' . (int)$d['id'] . '">タスク ' . (int)$tc->fetchColumn() . '</a>';
                if ($canEdit) {
                    echo '<a class="btn ghost sm" href="?p=post&id=' . $id . '&edit=' . (int)$d['id'] . '">編集</a>';
                    khp_delete_btn('duty.delete', array('duty_id' => $d['id']), '削除',
                        'この職務・職責・目標と、そのタスクをすべて削除します。よろしいですか？');
                }
                echo '</div></td></tr>';
            }
            echo '</table></div>';
            echo '<p class="muted">実績＝「やったこと」を書いたタスクの件数。達成率＝実績÷目標値。タスクの記録は各行の「タスク」から。</p>';
        } else {
            echo '<div class="empty">まだ登録がありません。下のフォームで職務・職責・目標を登録してください。</div>';
        }

        if ($canEdit) {
            $isEdit = $editDuty !== null;
            echo '<div class="card">';
            if (!$isEdit && $sc['duties']) {
                echo '<div class="tabs">
                  <button type="button" class="active" data-tab="in" onclick="khpTab(this)">入力</button>
                  <button type="button" data-tab="ref" onclick="khpTab(this)">既存の職務からコピー（' . count($sc['duties']) . '）</button>
                </div>';
            }
            echo '<div class="tabpane active" data-pane="in"><form method="post">
              <p class="form-title">' . ($isEdit ? '職務・職責・目標を編集' : '職務・職責・目標を登録') . '</p>'
              . (!$isEdit && $sc['duties'] ? '<p class="form-note">似た職務を作るときは「既存の職務からコピー」から転記すると早いです。</p>' : '') . '
              <input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '">
              <input type="hidden" name="a" value="' . ($isEdit ? 'duty.update' : 'duty.save') . '">
              <input type="hidden" name="post_id" value="' . $id . '">'
              . ($isEdit ? '<input type="hidden" name="duty_id" value="' . (int)$editDuty['id'] . '">' : '') . '
              <label>職務（担当する業務）*</label>
              <textarea name="job_memo" data-f="job" rows="4" required placeholder="各工程の品質記録を集めて点検する。">' . ($isEdit ? khp_h($editDuty['job_memo']) : '') . '</textarea>
              <label>職責（その職務で負う責任）*</label>
              <textarea name="resp_memo" data-f="resp" rows="4" required placeholder="記録の欠落をなくす。月次締めから5営業日以内に点検を終える。">' . ($isEdit ? khp_h($editDuty['resp_memo']) : '') . '</textarea>
              <label>目標 *</label>
              <textarea name="goal_memo" data-f="goal" rows="4" required placeholder="月次の品質記録の点検を続ける。">' . ($isEdit ? khp_h($editDuty['goal_memo']) : '') . '</textarea>
              <div class="row"><div><label>目標値</label><input type="number" step="0.01" name="target_value" data-f="target" value="' . ($isEdit ? (float)$editDuty['target_value'] : 0) . '" style="width:120px"></div>
              <div><label>単位</label><input name="unit" data-f="unit" value="' . ($isEdit ? khp_h($editDuty['unit']) : '') . '" style="width:90px" placeholder="件"></div></div>
              <p style="margin-top:12px"><button class="btn">' . ($isEdit ? '保存する' : '登録する') . '</button>'
              . ($isEdit ? ' <a class="btn ghost" href="?p=post&id=' . $id . '">キャンセル</a>' : '') . '</p></form></div>';
            if (!$isEdit && $sc['duties']) {
                echo '<div class="tabpane" data-pane="ref">
                  <p class="form-note">この職の既存の職務です。「コピー」で3つの欄と目標値がまとめて入力欄に入ります。</p>';
                foreach ($sc['duties'] as $d) {
                    echo '<div class="ref-item"><div class="ref-main">
                        <div><b>' . khp_h(khp_snip($d['job_memo'], 30)) . '</b></div>
                        <div class="muted">職責: ' . khp_h(khp_snip($d['resp_memo'], 40)) . '</div>
                        <div class="muted">目標: ' . khp_h(khp_snip($d['goal_memo'], 40))
                        . ((float)$d['target_value'] > 0 ? '（' . (float)$d['target_value'] . khp_h($d['unit']) . '）' : '') . '</div>
                        </div>
                        <button type="button" class="btn ghost sm" onclick="khpFill(this)"
                          data-job="' . khp_h(rawurlencode($d['job_memo'])) . '"
                          data-resp="' . khp_h(rawurlencode($d['resp_memo'])) . '"
                          data-goal="' . khp_h(rawurlencode($d['goal_memo'])) . '"
                          data-target="' . khp_h(rawurlencode((string)(float)$d['target_value'])) . '"
                          data-unit="' . khp_h(rawurlencode($d['unit'])) . '">コピー</button></div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        khp_section('歴代の担当者');
        $as = $pdo->prepare('SELECT * FROM assignments WHERE post_id=? ORDER BY from_date DESC');
        $as->execute(array($id));
        $arows = $as->fetchAll(PDO::FETCH_ASSOC);
        if ($arows) {
            echo '<div class="table-wrap"><table><tr><th>担当</th><th>期間</th><th style="width:150px">記録</th></tr>';
            foreach ($arows as $a) {
                $cq = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE post_id=? AND emp_key=?');
                $cq->execute(array($id, $a['emp_key']));
                $cnt = (int)$cq->fetchColumn();
                echo '<tr><td>' . khp_h(khp_emp_name($a['emp_key'])) . '</td>'
                    . '<td>' . khp_h($a['from_date']) . ' 〜 ' . ($a['to_date'] !== '' ? khp_h($a['to_date']) : '<span class="badge ok">現任</span>') . '</td>'
                    . '<td><a class="btn ghost sm" href="?p=post&id=' . $id . '&who=' . khp_h(urlencode($a['emp_key'])) . '">記録を見る（' . $cnt . '件）</a></td></tr>';
            }
            echo '</table></div>';
            echo '<p class="muted">後任は前任の「記録を見る」から、在任中に何をしていたかをそのまま読めます。</p>';
        } else {
            echo '<div class="empty">まだ任命がありません。' . ($actor === 'admin' ? '<a href="?p=assign">任命ページ</a>で担当者を決められます。' : '') . '</div>';
        }
        khp_foot();
    }
    /* ---------- タスクの画面: その目標のタスクの一覧と登録・編集 ---------- */
    elseif ($page === 'tasks') {
        $did = (int)$_GET['id'];
        $dq = $pdo->prepare('SELECT d.*, p.name AS post_name FROM duties d LEFT JOIN posts p ON p.id=d.post_id WHERE d.id=?');
        $dq->execute(array($did));
        $duty = $dq->fetch(PDO::FETCH_ASSOC);
        if (!$duty) { throw new Exception('目標が見つかりません'); }
        $pid = (int)$duty['post_id'];
        $canEdit = ($actor === 'admin') || ($actor === 'staff' && khp_owns_post($me, $pid));
        $actual = khp_duty_actual($did);
        $rate = khp_rate($duty['target_value'], $actual);
        $editTask = null;
        if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
            $tq = $pdo->prepare('SELECT * FROM tasks WHERE id=? AND duty_id=?');
            $tq->execute(array((int)$_GET['edit'], $did));
            $editTask = $tq->fetch(PDO::FETCH_ASSOC);
        }

        khp_head('タスク'); khp_nav();
        echo '<div class="crumb"><a href="?">ホーム</a> › <a href="?p=post&id=' . $pid . '">' . khp_h($duty['post_name']) . '</a> › タスク</div>';
        echo '<div class="page-head"><h1>タスクの記録</h1>';
        if ((float)$duty['target_value'] > 0) {
            echo '<span class="badge">実績 ' . $actual . khp_h($duty['unit']) . ' / ' . (float)$duty['target_value'] . khp_h($duty['unit']) . '</span>';
            echo '<span class="badge">' . ($rate === null ? '-' : '達成率 ' . number_format($rate, 0) . '%') . '</span>';
        }
        echo '</div>';

        echo '<div class="card" style="padding:12px 18px"><table style="font-size:13.5px">
            <tr><th style="width:70px;background:none;border-bottom:1px solid var(--line)">職務</th>
                <td style="border-bottom:1px solid var(--line)">' . nl2br(khp_h($duty['job_memo'])) . '</td></tr>
            <tr><th style="background:none;border-bottom:1px solid var(--line)">職責</th>
                <td style="border-bottom:1px solid var(--line)">' . nl2br(khp_h($duty['resp_memo'])) . '</td></tr>
            <tr><th style="background:none;border-bottom:0">目標</th>
                <td style="border-bottom:0">' . nl2br(khp_h($duty['goal_memo'])) . '</td></tr></table></div>';

        if ($canEdit) {
            $isEdit = $editTask !== null;
            // 参照用: この目標のこれまでのタスク（前任の記録を見ながらコピーして入力できる）
            $refq = $pdo->prepare('SELECT * FROM tasks WHERE duty_id=? ORDER BY done_on DESC, id DESC LIMIT 30');
            $refq->execute(array($did));
            $refs = $refq->fetchAll(PDO::FETCH_ASSOC);
            echo '<div class="card">';
            if (!$isEdit && $refs) {
                echo '<div class="tabs">
                  <button type="button" class="active" data-tab="in" onclick="khpTab(this)">入力</button>
                  <button type="button" data-tab="ref" onclick="khpTab(this)">これまでの記録からコピー（' . count($refs) . '）</button>
                </div>';
            }
            echo '<div class="tabpane active" data-pane="in"><form method="post">
              <p class="form-title">' . ($isEdit ? 'タスクを編集' : 'タスクを登録') . '</p>
              <p class="form-note">やったことを書くと実績が1件増えます。毎回書くのが面倒な定型タスクは「これまでの記録からコピー」が早いです。</p>
              <input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '">
              <input type="hidden" name="a" value="' . ($isEdit ? 'task.update' : 'task.save') . '">
              <input type="hidden" name="duty_id" value="' . $did . '">'
              . ($isEdit ? '<input type="hidden" name="task_id" value="' . (int)$editTask['id'] . '">' : '') . '
              <div class="row"><div><label>日付</label><input type="date" name="done_on" value="' . khp_h($isEdit ? $editTask['done_on'] : khp_today()) . '" required></div></div>
              <label>やること</label><textarea name="todo" data-f="todo" rows="3">' . ($isEdit ? khp_h($editTask['todo']) : '') . '</textarea>
              <label>やったこと</label><textarea name="done" rows="4">' . ($isEdit ? khp_h($editTask['done']) : '') . '</textarea>
              <p style="margin-top:12px"><button class="btn">' . ($isEdit ? '保存する' : '登録する') . '</button>'
              . ($isEdit ? ' <a class="btn ghost" href="?p=tasks&id=' . $did . '">キャンセル</a>' : '') . '</p></form></div>';
            if (!$isEdit && $refs) {
                echo '<div class="tabpane" data-pane="ref">
                  <p class="form-note">前任・過去の記録です。「コピー」を押すと、やることが入力欄に入ります。</p>';
                foreach ($refs as $rt) {
                    echo '<div class="ref-item"><div class="ref-main">
                        <div class="ref-meta">' . khp_h($rt['done_on']) . ' ・ ' . khp_h(khp_emp_name($rt['emp_key'])) . '</div>'
                        . '<div>' . nl2br(khp_h($rt['todo'])) . '</div>'
                        . ($rt['done'] !== '' ? '<div class="muted">' . nl2br(khp_h(khp_snip($rt['done'], 60))) . '</div>' : '')
                        . '</div>
                        <button type="button" class="btn ghost sm" onclick="khpFill(this)"
                          data-todo="' . khp_h(rawurlencode($rt['todo'])) . '">コピー</button></div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        $ts = $pdo->prepare('SELECT * FROM tasks WHERE duty_id=? ORDER BY done_on DESC, id DESC LIMIT 300');
        $ts->execute(array($did));
        $rows = $ts->fetchAll(PDO::FETCH_ASSOC);
        khp_section('タスク', count($rows));
        if ($rows) {
            echo '<div class="table-wrap"><table><tr><th style="width:100px">日付</th><th>やること</th><th>やったこと</th><th style="width:90px">記録者</th>'
                . ($canEdit ? '<th style="width:120px">操作</th>' : '') . '</tr>';
            foreach ($rows as $t) {
                echo '<tr><td style="white-space:nowrap">' . khp_h($t['done_on']) . '</td>'
                    . '<td>' . nl2br(khp_h($t['todo'])) . '</td>'
                    . '<td>' . ($t['done'] !== '' ? nl2br(khp_h($t['done'])) : '<span class="badge warn">未実施</span>') . '</td>'
                    . '<td>' . khp_h(khp_emp_name($t['emp_key'])) . '</td>';
                if ($canEdit) {
                    echo '<td><div class="actions"><a class="btn ghost sm" href="?p=tasks&id=' . $did . '&edit=' . (int)$t['id'] . '">編集</a>';
                    khp_delete_btn('task.delete', array('task_id' => $t['id']), '削除', 'このタスクを削除します。よろしいですか？');
                    echo '</div></td>';
                }
                echo '</tr>';
            }
            echo '</table></div>';
        } else {
            echo '<div class="empty">まだタスクがありません。</div>';
        }
        khp_foot();
    }
    /* ---------- 任命（管理者） ---------- */
    elseif ($page === 'assign') {
        khp_assert($actor, 'assignment.manage');
        khp_head('任命'); khp_nav();
        echo '<div class="crumb"><a href="?">ホーム</a> › 任命</div>';
        echo '<div class="page-head"><h1>任命</h1></div>';

        $arows = $pdo->query('SELECT a.*, p.name AS post_name FROM assignments a LEFT JOIN posts p ON p.id=a.post_id ORDER BY a.from_date DESC')->fetchAll(PDO::FETCH_ASSOC);
        if ($arows) {
            echo '<div class="table-wrap"><table><tr><th>職</th><th>担当</th><th>期間</th><th style="width:250px">操作</th></tr>';
            foreach ($arows as $a) {
                echo '<tr><td>' . khp_h($a['post_name']) . '</td><td>' . khp_h(khp_emp_name($a['emp_key'])) . '</td>'
                    . '<td style="white-space:nowrap">' . khp_h($a['from_date']) . ' 〜 '
                    . ($a['to_date'] !== '' ? khp_h($a['to_date']) : '<span class="badge ok">現任</span>') . '</td><td><div class="actions">';
                if ($a['to_date'] === '') {
                    echo '<form method="post" class="actions"><input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '">
                      <input type="hidden" name="a" value="assign.end"><input type="hidden" name="id" value="' . (int)$a['id'] . '">
                      <input type="date" name="to_date" value="' . khp_h(khp_today()) . '" style="padding:4px 6px;font-size:12.5px">
                      <button class="btn ghost sm">任命を終了</button></form>';
                }
                khp_delete_btn('assign.delete', array('id' => $a['id']), '削除', 'この任命の記録を削除します。よろしいですか？');
                echo '</div></td></tr>';
            }
            echo '</table></div>';
        } else {
            echo '<div class="empty">まだ任命がありません。</div>';
        }

        khp_section('任命する');
        echo '<div class="card"><form method="post">
          <input type="hidden" name="csrf" value="' . khp_h(khp_csrf()) . '"><input type="hidden" name="a" value="assign.save">
          <div class="row"><div><label>職 *</label><select name="post_id" required>';
        foreach ($pdo->query('SELECT id,name FROM posts WHERE active=1 ORDER BY name') as $p) {
            echo '<option value="' . (int)$p['id'] . '">' . khp_h($p['name']) . '</option>';
        }
        echo '</select></div><div><label>社員 *</label><select name="emp_key" required>';
        foreach (khp_employees() as $e) {
            if ($e['status'] !== 'active') { continue; }
            echo '<option value="' . khp_h($e['key']) . '">'
                . khp_h($e['name'] . ($e['code'] !== '' ? '（' . $e['code'] . '）' : '')) . '</option>';
        }
        echo '</select></div><div><label>開始日 *</label><input type="date" name="from_date" value="' . khp_h(khp_today()) . '" required></div>
          <div><label>終了日（空欄=現任）</label><input type="date" name="to_date"></div>
          <div><button class="btn">任命する</button></div></div></form></div>';
        echo '<p class="muted" style="margin-top:10px">社員（' . count(khp_employees()) . '名）は外部の '
            . '<code>employees.json</code> から読んでいます。追加・退職の処理はそちら（kvgwc など）で行ってください。</p>';
        khp_foot();
    } else {
        header('Location: ?'); exit;
    }
} catch (KhpDenied $e) {
    http_response_code(403);
    khp_head('権限エラー'); khp_nav();
    echo '<div class="alert"><b>' . khp_h($e->getMessage()) . '</b></div>';
    khp_foot();
} catch (Exception $e) {
    http_response_code(500);
    khp_head('エラー'); khp_nav();
    echo '<div class="alert"><b>' . khp_h($e->getMessage()) . '</b></div>';
    khp_foot();
}
