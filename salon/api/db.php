<?php
/* =====================================================
   共通ブートストラップ
   - PDO接続（プリペアドステートメント使用）
   - セッションによるログイン管理
   - JSONレスポンス / 権限チェックのヘルパー
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/* ---------- セッションCookieの保護 ----------
   httponly: JavaScriptからCookieを盗めないようにする（XSS対策）
   samesite: 他サイトからのリクエストにCookieを乗せない（CSRF対策）
   secure  : HTTPS接続時のみCookieを送る */
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

/* ---------- セキュリティヘッダー ---------- */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/* ---------- 書き込みリクエストの防御 ----------
   ・Content-Type を application/json に限定
     （他サイトのフォームから偽リクエストを送るCSRF攻撃を防ぐ。
       通常のフォーム送信では application/json を名乗れない）
   ・Origin ヘッダーがある場合は自サイトからのリクエストか検証
   ・巨大なボディは拒否（DB荒らし対策） */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        fail('不正なリクエスト形式です', 415);
    }
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        $selfHost   = strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':');
        if ($originHost !== null && $selfHost !== '' && $originHost !== $selfHost) {
            fail('不正なリクエスト元です', 403);
        }
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 64 * 1024) {
        fail('リクエストが大きすぎます', 413);
    }
}

/* 営業設定 */
const OPEN_MIN       = 10 * 60;  // 開店 10:00
const CLOSE_MIN      = 19 * 60;  // 閉店 19:00
const SLOT_MIN       = 30;       // 30分刻み
const CLOSED_WEEKDAY = 2;        // 火曜定休（0=日,1=月,2=火,...）

function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            fail('データベースに接続できません。install.php を実行済みか、MySQLが起動しているか確認してください。', 500);
        }
    }
    return $pdo;
}

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never {
    json_out(['error' => $msg], $code);
}

/* リクエストボディ(JSON)を配列で取得 */
function body(): array {
    static $cache = null;
    if ($cache === null) {
        $raw   = file_get_contents('php://input');
        $d     = json_decode($raw !== false && $raw !== '' ? $raw : 'null', true);
        $cache = is_array($d) ? $d : [];
    }
    return $cache;
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = pdo()->prepare('SELECT id, role, name, kana, phone, email, note FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) fail('ログインが必要です', 401);
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') fail('管理者権限が必要です', 403);
    return $u;
}

/* ---------- 日付・時刻バリデーション ---------- */
function valid_date(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function valid_time(string $t): bool {
    return (bool)preg_match('/^\d{2}:\d{2}$/', $t);
}

function time_to_min(string $t): int {
    [$h, $m] = array_map('intval', explode(':', substr($t, 0, 5)));
    return $h * 60 + $m;
}

function min_to_time(int $min): string {
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

function is_closed_day(string $date): bool {
    return (int)date('w', strtotime($date)) === CLOSED_WEEKDAY;
}

/* ---------- 入力の長さ制限（DB荒らし・巨大データ対策） ---------- */
function check_len(string $v, int $max, string $label): void {
    if (mb_strlen($v) > $max) fail("{$label}は{$max}文字以内で入力してください");
}

/* ---------- レート制限（総当たり攻撃・連投対策) ----------
   IPアドレスごとに時間窓内の試行回数をDBで数え、超えたら429を返す。
   rate_limits テーブルが無い環境では初回アクセス時に自動作成する。 */
function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function rate_limit(string $bucket, int $max, int $windowSec): void {
    $bucket = substr($bucket, 0, 64);
    $now    = time();
    try {
        $p  = pdo();
        $st = $p->prepare('SELECT cnt, window_start FROM rate_limits WHERE bucket = ?');
        $st->execute([$bucket]);
        $row = $st->fetch();
        if (!$row || $now - (int)$row['window_start'] >= $windowSec) {
            $p->prepare('REPLACE INTO rate_limits (bucket, cnt, window_start) VALUES (?, 1, ?)')
              ->execute([$bucket, $now]);
            return;
        }
        if ((int)$row['cnt'] >= $max) {
            fail('試行回数が多すぎます。しばらく時間をおいてからやり直してください。', 429);
        }
        $p->prepare('UPDATE rate_limits SET cnt = cnt + 1 WHERE bucket = ?')->execute([$bucket]);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {   // テーブル未作成なら作って初回カウント
            try {
                pdo()->exec('CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket       VARCHAR(64) NOT NULL PRIMARY KEY,
                    cnt          INT NOT NULL DEFAULT 1,
                    window_start INT NOT NULL
                ) ENGINE=InnoDB');
                pdo()->prepare('REPLACE INTO rate_limits (bucket, cnt, window_start) VALUES (?, 1, ?)')
                     ->execute([$bucket, $now]);
            } catch (PDOException $e2) { /* 制限より本来の処理を優先 */ }
        }
    }
}

function reset_rate_limit(string $bucket): void {
    try {
        pdo()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([substr($bucket, 0, 64)]);
    } catch (PDOException $e) { /* 無視してよい */ }
}

/* ---------- デモモード保護 ----------
   ポートフォリオとしてID/パスワードを公開しているため、
   ログインできた人が壊せないように以下を制限する。
   （config.php の DEMO_MODE を false にすると解除される）
   - デモ用アカウント(123/000)のパスワード変更・削除を禁止
   - 初期登録のスタイリスト・メニューの削除を禁止
   - テーブルごとの最大行数を制限（データ流し込み対策） */
const DEMO_USER_IDS       = ['123', '000'];
const DEMO_SEED_STYLISTS  = 3;   // id 1〜3 は初期データ
const DEMO_SEED_MENUS     = 6;   // id 1〜6 は初期データ

function is_demo_mode(): bool {
    return defined('DEMO_MODE') && DEMO_MODE;
}

function demo_guard_user(string $id, string $msg = 'デモ用アカウントのため、この操作はできません'): void {
    if (is_demo_mode() && in_array($id, DEMO_USER_IDS, true)) fail($msg, 403);
}

function demo_cap_rows(string $table, int $max): void {
    if (!is_demo_mode()) return;
    static $allowed = ['users', 'reservations', 'menus', 'stylists', 'histories'];
    if (!in_array($table, $allowed, true)) return;
    $cnt = (int)pdo()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($cnt >= $max) fail('デモ環境のため、これ以上は登録できません', 429);
}
