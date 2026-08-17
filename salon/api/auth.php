<?php
/* =====================================================
   認証API
   POST {action:"login", id, password} … ログイン
   POST {action:"logout"}              … ログアウト
   GET  ?action=me                     … ログイン中ユーザー取得
   POST {action:"update_me", ...}      … 自分の会員情報変更
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? (body()['action'] ?? '');

switch ($action) {

    case 'login': {
        $b  = body();
        $id = trim((string)($b['id'] ?? ''));
        $pw = (string)($b['password'] ?? '');
        if ($id === '' || $pw === '') fail('IDとパスワードを入力してください');

        $st = pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();

        if (!$u || !password_verify($pw, $u['password_hash'])) {
            fail('IDまたはパスワードが違います', 401);
        }
        session_regenerate_id(true);          // セッション固定化攻撃対策
        $_SESSION['uid'] = $u['id'];
        unset($u['password_hash']);
        json_out(['user' => $u]);
    }

    case 'logout': {
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);
    }

    case 'me': {
        json_out(['user' => current_user()]);
    }

    case 'update_me': {
        $me = require_login();
        $b  = body();
        $name = trim((string)($b['name'] ?? ''));
        if ($name === '') fail('お名前を入力してください');

        $sql    = 'UPDATE users SET name = ?, kana = ?, phone = ?, email = ?';
        $params = [$name,
                   trim((string)($b['kana'] ?? '')),
                   trim((string)($b['phone'] ?? '')),
                   trim((string)($b['email'] ?? ''))];

        $pw = (string)($b['password'] ?? '');
        if ($pw !== '') {                     // 空欄なら現在のパスワードを維持
            $sql .= ', password_hash = ?';
            $params[] = password_hash($pw, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $me['id'];

        pdo()->prepare($sql)->execute($params);
        json_out(['user' => current_user()]);
    }

    default:
        fail('不正なリクエストです', 404);
}
