<?php
/* =====================================================
   顧客管理API（すべて管理者専用）
   GET  ?q=検索語                    … 顧客一覧（来店回数付き）
   GET  ?action=detail&id=           … カルテ（顧客情報＋履歴＋今後の予約）
   POST {action:"create", ...}       … 新規顧客登録
   POST {action:"update", ...}       … 顧客情報更新
   POST {action:"delete", id}        … 顧客削除
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (($_GET['action'] ?? '') === 'detail') {
        $id = (string)($_GET['id'] ?? '');
        $st = pdo()->prepare(
            "SELECT id, name, kana, phone, email, note FROM users WHERE id = ? AND role = 'customer'"
        );
        $st->execute([$id]);
        $user = $st->fetch();
        if (!$user) fail('顧客が見つかりません', 404);

        $st = pdo()->prepare(
            "SELECT DATE_FORMAT(h.date, '%Y-%m-%d') AS date, h.stylist_name, h.menu_name, h.price, h.memo
             FROM histories h WHERE h.user_id = ? ORDER BY h.date DESC, h.id DESC"
        );
        $st->execute([$id]);
        $histories = $st->fetchAll();

        $st = pdo()->prepare(
            "SELECT DATE_FORMAT(r.date, '%Y-%m-%d') AS date, DATE_FORMAT(r.time, '%H:%i') AS time,
                    s.name AS stylist_name, m.name AS menu_name
             FROM reservations r
             JOIN stylists s ON s.id = r.stylist_id
             JOIN menus    m ON m.id = r.menu_id
             WHERE r.user_id = ? AND r.status = 'active' AND r.date >= CURDATE()
             ORDER BY r.date, r.time"
        );
        $st->execute([$id]);
        json_out(['user' => $user, 'histories' => $histories, 'upcoming' => $st->fetchAll()]);
    }

    // 顧客一覧（検索対応）
    $q      = trim((string)($_GET['q'] ?? ''));
    $sql    = "SELECT u.id, u.name, u.kana, u.phone, u.email, u.note,
                      (SELECT COUNT(*) FROM histories h WHERE h.user_id = u.id) AS visits
               FROM users u WHERE u.role = 'customer'";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (u.id LIKE ? OR u.name LIKE ? OR u.kana LIKE ? OR u.phone LIKE ?)';
        $like = "%{$q}%";
        $params = [$like, $like, $like, $like];
    }
    $sql .= ' ORDER BY u.id';
    $st = pdo()->prepare($sql);
    $st->execute($params);
    json_out(['customers' => $st->fetchAll()]);
}

$b      = body();
$action = (string)($b['action'] ?? '');

switch ($action) {

    case 'create': {
        $id   = trim((string)($b['id'] ?? ''));
        $pw   = (string)($b['password'] ?? '');
        $name = trim((string)($b['name'] ?? ''));
        if ($id === '' || $pw === '' || $name === '') fail('ID・パスワード・氏名は必須です');
        if (!preg_match('/^[0-9A-Za-z_-]{1,20}$/', $id)) fail('IDは半角英数字20文字以内で入力してください');
        check_len($pw, 72, 'パスワード');
        check_len($name, 50, '氏名');
        check_len(trim((string)($b['kana'] ?? '')), 50, 'フリガナ');
        check_len(trim((string)($b['phone'] ?? '')), 20, '電話番号');
        check_len(trim((string)($b['email'] ?? '')), 100, 'メールアドレス');
        check_len(trim((string)($b['note'] ?? '')), 1000, 'メモ');
        demo_cap_rows('users', 300);

        $st = pdo()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('そのIDは既に使われています');

        pdo()->prepare(
            "INSERT INTO users (id, password_hash, role, name, kana, phone, email, note)
             VALUES (?, ?, 'customer', ?, ?, ?, ?, ?)"
        )->execute([
            $id, password_hash($pw, PASSWORD_DEFAULT), $name,
            trim((string)($b['kana'] ?? '')),
            trim((string)($b['phone'] ?? '')),
            trim((string)($b['email'] ?? '')),
            trim((string)($b['note'] ?? '')),
        ]);
        json_out(['ok' => true]);
    }

    case 'update': {
        $id   = (string)($b['id'] ?? '');
        $name = trim((string)($b['name'] ?? ''));
        if ($name === '') fail('氏名は必須です');
        check_len($name, 50, '氏名');
        $kana  = trim((string)($b['kana'] ?? ''));
        $phone = trim((string)($b['phone'] ?? ''));
        $email = trim((string)($b['email'] ?? ''));
        $note  = trim((string)($b['note'] ?? ''));
        check_len($kana, 50, 'フリガナ');
        check_len($phone, 20, '電話番号');
        check_len($email, 100, 'メールアドレス');
        check_len($note, 1000, 'メモ');

        $st = pdo()->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'customer'");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() === 0) fail('顧客が見つかりません', 404);

        $sql    = 'UPDATE users SET name = ?, kana = ?, phone = ?, email = ?, note = ?';
        $params = [$name, $kana, $phone, $email, $note];
        $pw = (string)($b['password'] ?? '');
        if ($pw !== '') {                     // 空欄なら現在のパスワードを維持
            // 公開デモ用アカウントのパスワード変更は禁止（全員がログイン不能になるため）
            demo_guard_user($id, 'デモ用アカウントのパスワードは変更できません');
            check_len($pw, 72, 'パスワード');
            $sql .= ', password_hash = ?';
            $params[] = password_hash($pw, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        pdo()->prepare($sql)->execute($params);
        json_out(['ok' => true]);
    }

    case 'delete': {
        $id = (string)($b['id'] ?? '');
        demo_guard_user($id, 'デモ用アカウントは削除できません');
        $st = pdo()->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'customer'");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() === 0) fail('顧客が見つかりません', 404);
        // 予約はFK(ON DELETE CASCADE)で削除、履歴はSET NULLで氏名スナップショットが残る
        pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }

    default:
        fail('不正なリクエストです', 404);
}
