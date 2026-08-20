<?php
/**
 * Kurage HR Post 自己テスト。ブラウザ無しで機械検証する。
 * 実行: php scripts/check_khrpost.php
 *
 * 検証するのは「壊れたら製品の信頼が崩れる」ところだけ:
 *   関門(khp_can) / 所有チェック / 達成率と等級 / 実績=やったこと件数 /
 *   職スコアの平均 / 空席判定 / 省略表示 / 監査ログ / 構造の単純さ
 */

define('KHP_SKIP_CONFIG', true);
$tmp = sys_get_temp_dir() . '/khrpost_test_' . getmypid();
@mkdir($tmp, 0700, true);
// 社員マスタは外部JSONが正。テストでも本番と同じ形式のファイルを置いて読ませる。
file_put_contents($tmp . '/employees.json', json_encode(array('employees' => array(
    array('id' => 'e1', 'no' => 'E001', 'name' => '山田 太郎', 'status' => 'active'),
    array('id' => 'e2', 'no' => 'E002', 'name' => '田中 美咲', 'status' => 'active'),
    array('id' => 'e3', 'no' => 'E003', 'name' => '高橋 健',  'status' => 'retired'),
)), JSON_UNESCAPED_UNICODE));
define('KHP_DATA_DIR', $tmp);
define('KHP_TITLE', 'Kurage HR Post (test)');
define('KHP_PASSWORD', 'test');
define('KHP_STAFF_PASSWORD', 'staff');
define('KHP_EMPLOYEES_DIR', $tmp);
define('KHP_GRADE_TABLE', 'S:110,A:100,B:85,C:70');
define('KHP_RATE_CAP', 120);

require __DIR__ . '/../public/khrpost.php';

$ok = 0; $ng = 0;
function t($name, $cond, $extra = '') {
    global $ok, $ng;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $ng++; echo "  NG   $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

echo "== 1. 権限の関門 ==\n";
t('担当者は職務・職責・目標を登録できる', khp_can('staff', 'duty.create'));
t('担当者は職務・職責・目標を編集・削除できる', khp_can('staff', 'duty.edit'));
t('担当者はタスクを記録できる',                   khp_can('staff', 'task.create'));
t('担当者はタスクを編集・削除できる',             khp_can('staff', 'task.edit'));
t('AI連携は編集・削除できない',                   !khp_can('ai', 'task.edit') && !khp_can('ai', 'duty.edit'));
t('担当者は職そのものを作れない',                 !khp_can('staff', 'post.manage'));
t('担当者は任命できない',                         !khp_can('staff', 'assignment.manage'));
t('管理者は職を作れる',                           khp_can('admin', 'post.manage'));
t('管理者は任命できる',                           khp_can('admin', 'assignment.manage'));
t('AI連携はタスクの下書きだけ',                   khp_can('ai', 'task.create'));
t('AI連携はセットを登録できない',                 !khp_can('ai', 'duty.create'));
t('未知のロールは何もできない',                   !khp_can('guest', 'task.create'));

echo "== 2. 達成率と等級 ==\n";
t('実績5/目標6 = 83.3%',      abs(khp_rate(6, 5) - 83.333) < 0.01);
t('上限120%で頭打ち',          abs(khp_rate(6, 12) - 120.0) < 0.001);
t('目標値0は達成率を出さない',  khp_rate(0, 5) === null);
t('マイナスは0%',              abs(khp_rate(6, -1) - 0.0) < 0.001);
t('110%はS（境界）', khp_grade(110) === 'S');
t('100%はA（境界）', khp_grade(100) === 'A');
t('85%はB（境界）',  khp_grade(85) === 'B');
t('70%はC（境界）',  khp_grade(70) === 'C');
t('69%はD',          khp_grade(69) === 'D');
t('評価不能は -',    khp_grade(null) === '-');

echo "== 3. 職・任命・所有チェック ==\n";
$pdo = khp_pdo();
$pdo->prepare('INSERT INTO posts(name,memo,headcount,created_at) VALUES(?,?,?,?)')
    ->execute(array('品質管理責任者', '製品品質を保つ職', 1, khp_now()));
$postId = (int)$pdo->lastInsertId();
$post = $pdo->query('SELECT * FROM posts WHERE id=' . $postId)->fetch(PDO::FETCH_ASSOC);
t('任命が無ければ担当者なし(vacant)', khp_post_status($post)['state'] === 'vacant');

$empKey = 'kv:e1';   // 社員は employees.json 側にいる
$pdo->prepare('INSERT INTO assignments(post_id,emp_key,from_date,to_date,created_at) VALUES(?,?,?,?,?)')
    ->execute(array($postId, $empKey, date('Y-m-d', strtotime('-30 days')), '', khp_now()));
t('任命があれば充足(filled)', khp_post_status($post)['state'] === 'filled');
$pdo->prepare('INSERT INTO assignments(post_id,emp_key,from_date,to_date,created_at) VALUES(?,?,?,?,?)')
    ->execute(array($postId, $empKey, date('Y-m-d', strtotime('-400 days')), date('Y-m-d', strtotime('-200 days')), khp_now()));
t('終了した任命は数えない', khp_post_status($post)['state'] === 'filled');
t('現任の名前が出る', khp_post_holders($postId) === array('山田 太郎'));

t('任命された社員は自分の職を所有', khp_owns_post($empKey, $postId));
t('無関係の社員は所有しない',       !khp_owns_post('kv:zzz', $postId));
$denied = false;
try { khp_assert_post('staff', 'kv:zzz', $postId); } catch (KhpDenied $e) { $denied = true; }
t('他人の職への登録は関門で拒否',   $denied);
t('管理者は制限なく通る', (function () use ($postId) {
    try { khp_assert_post('admin', '', $postId); return true; } catch (Exception $e) { return false; }
})());

echo "== 4. セット(職務・職責・目標)と実績 ==\n";
$pdo->prepare('INSERT INTO duties(post_id,job_memo,resp_memo,goal_memo,target_value,unit,created_at) VALUES(?,?,?,?,?,?,?)')
    ->execute(array($postId, '品質記録の管理。記録を集めて点検する。', '記録の欠落をなくす。', '月次点検を続ける。', 6, '件', khp_now()));
$d1 = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO duties(post_id,job_memo,resp_memo,goal_memo,target_value,unit,created_at) VALUES(?,?,?,?,?,?,?)')
    ->execute(array($postId, '是正処置の統括。', '期限内に終わらせる。', '是正を完了させる。', 8, '件', khp_now()));
$d2 = (int)$pdo->lastInsertId();

for ($i = 0; $i < 5; $i++) {
    $pdo->prepare("INSERT INTO tasks(post_id,duty_id,done_on,todo,done,emp_key,created_at) VALUES(?,?,?,?,?,?,?)")
        ->execute(array($postId, $d1, khp_today(), '記録を点検する', '点検した', $empKey, khp_now()));
}
t('実績＝やったことを書いた件数（5）', khp_duty_actual($d1) === 5);
$pdo->prepare("INSERT INTO tasks(post_id,duty_id,done_on,todo,done,emp_key,created_at) VALUES(?,?,?,?,?,?,?)")
    ->execute(array($postId, $d1, khp_today(), 'やることだけ（未実施）', '', $empKey, khp_now()));
t('やったことが空の記録は数えない', khp_duty_actual($d1) === 5);

$sc = khp_post_score($postId);
// セット1: 5/6=83.33% / セット2: 0/8=0% → 平均 41.67%
t('職スコア＝セット達成率の平均(41.7%)', abs($sc['score'] - 41.666) < 0.01, (string)$sc['score']);
t('41.7%はD', $sc['grade'] === 'D');
for ($i = 0; $i < 8; $i++) {
    $pdo->prepare("INSERT INTO tasks(post_id,duty_id,done_on,todo,done,emp_key,created_at) VALUES(?,?,?,?,?,?,?)")
        ->execute(array($postId, $d2, khp_today(), '是正する', '是正した', $empKey, khp_now()));
}
$sc2 = khp_post_score($postId);
// (83.33 + 100) / 2 = 91.67%
t('タスクを積むとスコアが上がる(91.7%)', abs($sc2['score'] - 91.666) < 0.01, (string)$sc2['score']);
t('91.7%はB', $sc2['grade'] === 'B');

// 目標値なしのセットは平均に入れない
$pdo->prepare('INSERT INTO duties(post_id,job_memo,resp_memo,goal_memo,target_value,unit,created_at) VALUES(?,?,?,?,?,?,?)')
    ->execute(array($postId, '雑務。', '丁寧にやる。', '特に数値目標なし。', 0, '', khp_now()));
$sc3 = khp_post_score($postId);
t('目標値なしのセットは平均から除外', abs($sc3['score'] - 91.666) < 0.01);

echo "== 5. 前任の記録が後任から見える（引き継ぎの実体） ==\n";
// 職のタスクは職(post_id)にひもづくので、担当が代わっても同じ画面に全履歴が出る
$st = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE post_id=? AND emp_key=?");
$st->execute(array($postId, $empKey));
t('前任の記録が職に残っている', (int)$st->fetchColumn() >= 13);
t('記録者名を解決できる', khp_emp_name($empKey) === '山田 太郎');
t('管理者の記録は「管理者」', khp_emp_name('') === '管理者');
t('マスタから消えた社員の記録も名前欄が壊れない', khp_emp_name('kv:zzz') === '（マスタにない社員）');

echo "== 6. 省略表示 ==\n";
t('省略表示は先頭20文字', khp_snip('あいうえおかきくけこさしすせそたちつてとなにぬねの') === 'あいうえおかきくけこさしすせそたちつてと…');
t('20文字以内はそのまま', khp_snip('品質記録の管理') === '品質記録の管理');
t('改行より前だけを出す', khp_snip("1行目\n2行目") === '1行目');

echo "== 7. 社員マスタは外部JSONが正（khrpostは持たない） ==\n";
t('社員マスタを読めている',   khp_emp_master_ok());
t('読み元は employees.json', basename(khp_emp_source()) === 'employees.json');
t('社員コードで引ける',       khp_emp_by_code('E001') !== null);
t('無いコードはnull',         khp_emp_by_code('X999') === null);
t('idで引ける（任命の紐づけ）', khp_emp('kv:e1') !== null && khp_emp('kv:e1')['name'] === '山田 太郎');
t('退職者もマスタには残る',   khp_emp_by_code('E003') !== null);
t('退職者のstatusはactiveでない', khp_emp_by_code('E003')['status'] !== 'active');
t('社員に部門を持たない',     !array_key_exists('dept', khp_emp('kv:e1')));

echo "== 8. 構造の単純さ（勝手に複雑化しないための機械チェック） ==\n";
$src = file_get_contents(__DIR__ . '/../public/khrpost.php');
t('評価期間(periods)テーブルが無い',   strpos($src, 'periods') === false);
t('引き継ぎ(handovers)テーブルが無い', strpos($src, 'handovers') === false);
t('監査(audit)テーブルが無い',         strpos($src, 'audit') === false);
t('「セット」という言葉が無い',        strpos($src, 'セット') === false);
t('重み(weight)が無い',                !preg_match('/weight\s+(REAL|INTEGER)/i', $src));
t('社員(employees)テーブルを作らない', !preg_match('/CREATE TABLE IF NOT EXISTS employees/', $src));
t('社員を書き込むSQLが無い',           !preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+employees/i', $src));
t('社員に部門(dept)の項目が無い',      strpos($src, "'dept'") === false);
$tables = array();
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)\(/', $src, $tm);
t('テーブルは4つだけ(posts/assignments/duties/tasks)',
    $tm[1] === array('posts', 'assignments', 'duties', 'tasks'), implode(',', $tm[1]));
preg_match('/CREATE TABLE IF NOT EXISTS duties\((.*?)\)"/s', $src, $m);
t('セット(duties)は8列以下', substr_count($m[1], ',') + 1 <= 8, (string)(substr_count($m[1], ',') + 1));
preg_match('/CREATE TABLE IF NOT EXISTS tasks\((.*?)\)"/s', $src, $m2);
t('タスクは8列以下',        substr_count($m2[1], ',') + 1 <= 8);
t('タスクに証跡URL・予定列が無い', !preg_match('/evidence_url|planned/', $src));
$pages = substr_count($src, "\$page === '");
t('画面は5つ以下(home/post/tasks/assign/logout)', $pages <= 5, (string)$pages);

foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);
echo "\n==== 結果: 成功 {$ok} / 失敗 {$ng} ====\n";
exit($ng === 0 ? 0 : 1);
