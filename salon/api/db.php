<?php
/* =====================================================
   共通ブートストラップ
   - PDO接続（プリペアドステートメント使用）
   - セッションによるログイン管理
   - JSONレスポンス / 権限チェックのヘルパー
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

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
