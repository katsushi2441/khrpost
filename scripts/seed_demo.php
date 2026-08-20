<?php
/**
 * Kurage HR Post のデモ用サンプルデータ。
 * 実行: php scripts/seed_demo.php [データディレクトリ]
 *
 * 「職が主役」が一目で伝わる状態を作る:
 *   - 担当者がいる職（品質管理責任者・個人情報保護管理者）
 *   - 担当者がいない職（安全衛生推進者＝ホームに警告。前任の記録は残っている）
 *   - 前任→後任が交代した職（経理責任者＝後任が前任の記録をそのまま読める）
 */

define('KHP_SKIP_CONFIG', true);
$dir = isset($argv[1]) ? $argv[1] : (__DIR__ . '/../public/khrpost_data');
@mkdir($dir, 0700, true);
define('KHP_DATA_DIR', $dir);
define('KHP_TITLE', 'Kurage HR Post');
define('KHP_PASSWORD', 'seed');
define('KHP_EMPLOYEES_DIR', $dir);   // デモは kvgwc を持たないので、同じ形式のJSONを自前で置く

require __DIR__ . '/../public/khrpost.php';

$pdo = khp_pdo();
foreach (array('tasks', 'duties', 'assignments', 'posts') as $t) {
    $pdo->exec("DELETE FROM $t");
}

/* 社員マスタ（kvgwc の employees.json と同じ形式。khrpost は読むだけ） */
$empJson = array('employees' => array());
function emp($code, $name) {
    global $empJson;
    $id = 'e' . (count($empJson['employees']) + 1);
    $empJson['employees'][] = array('id' => $id, 'no' => $code, 'name' => $name, 'status' => 'active');
    return 'kv:' . $id;
}
function post_add($name, $memo, $need = 1) {
    khp_pdo()->prepare('INSERT INTO posts(name,memo,headcount,created_at) VALUES(?,?,?,?)')
        ->execute(array($name, $memo, $need, khp_now()));
    return (int)khp_pdo()->lastInsertId();
}
function assign_add($postId, $empKey, $from, $to = '') {
    khp_pdo()->prepare('INSERT INTO assignments(post_id,emp_key,from_date,to_date,created_at) VALUES(?,?,?,?,?)')
        ->execute(array($postId, $empKey, $from, $to, khp_now()));
    return (int)khp_pdo()->lastInsertId();
}
function duty_add($postId, $job, $resp, $goal, $target, $unit) {
    khp_pdo()->prepare('INSERT INTO duties(post_id,job_memo,resp_memo,goal_memo,target_value,unit,created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(array($postId, $job, $resp, $goal, $target, $unit, khp_now()));
    return (int)khp_pdo()->lastInsertId();
}
function task_add($postId, $dutyId, $empKey, $daysAgo, $todo, $done = '') {
    khp_pdo()->prepare('INSERT INTO tasks(post_id,duty_id,done_on,todo,done,emp_key,created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(array($postId, $dutyId, date('Y-m-d', strtotime("-$daysAgo days")), $todo, $done, $empKey, khp_now()));
}

/* 社員（JSONを先に書き出す。以降 khp_employees() が読めるようになる） */
$e1 = emp('E001', '山田 太郎');
$e2 = emp('E002', '佐藤 花子');
$e3 = emp('E003', '鈴木 一郎');
$e4 = emp('E004', '田中 美咲');
$e5 = emp('E005', '高橋 健');
file_put_contents($dir . '/employees.json', json_encode($empJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/* 1) 品質管理責任者（担当者あり） */
$p1 = post_add('品質管理責任者', '製品品質を保ち、不適合が出たときの是正を統括する職です。');
assign_add($p1, $e1, date('Y-m-d', strtotime('-400 days')));
$d11 = duty_add($p1,
    '品質記録の管理。各工程の記録を集めて点検する。',
    '記録の欠落をなくす。月次締めから5営業日以内に点検を終える。',
    '月次の品質記録の点検を続ける。', 6, '件');
$d12 = duty_add($p1,
    '是正処置の統括。不適合の原因究明と対策を指示する。',
    '期限内に是正を終わらせる。発生から30日以内に完了させる。',
    '是正処置を期限内に完了させる。', 8, '件');
foreach (array(120, 90, 60, 30, 10) as $d) {
    task_add($p1, $d11, $e1, $d, date('n', strtotime("-$d days")) . '月度の品質記録を点検する',
        '全12工程を点検した。欠落2件を差し戻して再提出を確認。');
}
foreach (array(110, 95, 70, 55, 40, 25) as $i => $d) {
    task_add($p1, $d12, $e1, $d, '是正処置 CA-' . (101 + $i) . ' を完了させる',
        '原因究明から対策の有効性確認まで完了した。');
}
task_add($p1, $d11, $e1, 3, '来月の点検計画をつくる');   // やったこと未記入＝未実施

/* 2) 個人情報保護管理者（担当者あり） */
$p2 = post_add('個人情報保護管理者', '個人情報の取扱いを統括し、開示・削除の請求に対応する職です。');
assign_add($p2, $e2, date('Y-m-d', strtotime('-200 days')));
$d21 = duty_add($p2,
    '取扱台帳の維持。個人データの取得・保管・廃棄を台帳で管理する。',
    '台帳を最新に保つ。四半期ごとに棚卸しする。',
    '台帳の棚卸しを続ける。', 2, '回');
$d22 = duty_add($p2,
    '研修と周知。全社員への研修を行う。',
    '全員に受講させる。未受講者には督促する。',
    '全社研修を実施する。', 2, '回');
task_add($p2, $d21, $e2, 100, '第1四半期の台帳棚卸し', '12業務38項目を確認。廃棄漏れ1件を是正した。');
task_add($p2, $d21, $e2, 15, '第2四半期の台帳棚卸し', '新規業務2件を台帳に追加した。');
task_add($p2, $d22, $e2, 60, '上期の全社研修を実施する', '対象82名中74名が受講。未受講者へ督促した。');
task_add($p2, $d22, $e2, 20, '未受講者向けの補講を実施する', '残り8名全員が受講し、受講率100%になった。');

/* 3) 安全衛生推進者（担当者がいない＝警告。前任の記録は残る） */
$p3 = post_add('安全衛生推進者', '職場の安全衛生を進め、労働災害を防ぐ職です。前任の異動後、後任が決まっていません。');
assign_add($p3, $e5, date('Y-m-d', strtotime('-500 days')), date('Y-m-d', strtotime('-45 days')));
$d31 = duty_add($p3,
    '職場巡視。毎月1回以上、作業場を回って危険箇所を記録する。',
    '危険箇所を放置しない。見つけたら当月中に対策する。',
    '毎月の職場巡視を続ける。', 12, '回');
task_add($p3, $d31, $e5, 80, '6月の職場巡視', '第2工場で配線の露出を発見。当日中に絶縁処理を依頼した。');
task_add($p3, $d31, $e5, 50, '7月の職場巡視', '指摘事項なし。');

/* 4) 経理責任者（前任→後任。後任は前任の記録をそのまま読める） */
$p4 = post_add('経理責任者', '月次・年次の決算をまとめ、資金繰りを見る職です。');
assign_add($p4, $e3, date('Y-m-d', strtotime('-800 days')), date('Y-m-d', strtotime('-90 days')));
assign_add($p4, $e4, date('Y-m-d', strtotime('-90 days')));
$d41 = duty_add($p4,
    '月次決算。毎月の締めを行い、試算表を経営層に提出する。',
    '期限内に締める。翌月10営業日以内に試算表を出す。',
    '月次決算を期限内に提出し続ける。', 6, '件');
$d42 = duty_add($p4,
    '資金繰り管理。資金繰り表を更新し、不足の兆候を早めに報告する。',
    '資金ショートを起こさない。3か月先まで見る。',
    '資金繰り表を毎月更新する。', 6, '件');
// 前任(鈴木)の記録
foreach (array(160, 130, 100) as $d) {
    task_add($p4, $d41, $e3, $d, date('n', strtotime("-$d days")) . '月度の月次決算を締める', '9営業日で試算表を提出した。');
}
task_add($p4, $d42, $e3, 100, '引き継ぎ前の資金繰り確認', '3か月先まで不足なしを確認。銀行提出用と社内用の様式の違いをメモに残した。');
// 後任(田中)の記録
foreach (array(75, 45, 15) as $d) {
    task_add($p4, $d41, $e4, $d, date('n', strtotime("-$d days")) . '月度の月次決算を締める', '8営業日で試算表を提出した。');
    task_add($p4, $d42, $e4, $d, date('n', strtotime("-$d days")) . '月度の資金繰り表を更新する', '3か月先まで更新。不足の見込みなし。');
}

$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');   // 配布用に WAL をたたむ

$c = array('employees=' . count($empJson['employees']) . '(json)');
foreach (array('posts', 'assignments', 'duties', 'tasks') as $t) {
    $c[] = "$t=" . (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
echo "サンプルデータ投入完了: " . implode(' ', $c) . "\n";
