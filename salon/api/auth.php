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
        check_len($id, 20, 'ID');
        check_len($pw, 72, 'パスワード');

        // 総当たり攻撃対策: 同一IPからのログイン試行を10分間に20回まで
        rate_limit('login:' . client_ip(), 20, 600);

        $st = pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();

        if (!$u || !password_verify($pw, $u['password_hash'])) {
            usleep(300000);                   // 失敗時は0.3秒待たせて機械的な連打を遅くする
            fail('IDまたはパスワードが違います', 401);
        }
        reset_rate_limit('login:' . client_ip());   // 成功したら失敗カウントを消す
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
        check_len($name, 50, 'お名前');
        $kana  = trim((string)($b['kana'] ?? ''));
        $phone = trim((string)($b['phone'] ?? ''));
        $email = trim((string)($b['email'] ?? ''));
        check_len($kana, 50, 'フリガナ');
        check_len($phone, 20, '電話番号');
        check_len($email, 100, 'メールアドレス');

        $sql    = 'UPDATE users SET name = ?, kana = ?, phone = ?, email = ?';
        $params = [$name, $kana, $phone, $email];

        $pw = (string)($b['password'] ?? '');
        if ($pw !== '') {                     // 空欄なら現在のパスワードを維持
            // 公開デモ用アカウントのパスワードを変えられると全員がログイン不能になるため禁止
            demo_guard_user($me['id'], 'デモ用アカウントのパスワードは変更できません');
            check_len($pw, 72, 'パスワード');
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
