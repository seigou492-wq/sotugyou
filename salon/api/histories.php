<?php
/* =====================================================
   施術履歴API
   GET  ?scope=mine            … 自分の施術履歴（お客様）
   GET  ?q=検索語              … 全履歴一覧（管理者）
   POST {action:"create", ...} … 手動登録（管理者）
   POST {action:"delete", id}  … 削除（管理者）
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const HIST_SELECT = "
    SELECT h.id, h.user_id, h.user_name,
           DATE_FORMAT(h.date, '%Y-%m-%d') AS date,
           h.stylist_name, h.menu_name, h.price, h.memo
    FROM histories h";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $me = require_login();

    if (($_GET['scope'] ?? '') === 'mine') {
        $st = pdo()->prepare(HIST_SELECT . ' WHERE h.user_id = ? ORDER BY h.date DESC, h.id DESC');
        $st->execute([$me['id']]);
        json_out(['histories' => $st->fetchAll()]);
    }

    require_admin();
    $q      = trim((string)($_GET['q'] ?? ''));
    $sql    = HIST_SELECT;
    $params = [];
    if ($q !== '') {
        $sql .= " WHERE (h.user_name LIKE ? OR h.menu_name LIKE ? OR DATE_FORMAT(h.date, '%Y-%m-%d') LIKE ?)";
        $like = "%{$q}%";
        $params = [$like, $like, $like];
    }
    $sql .= ' ORDER BY h.date DESC, h.id DESC';
    $st = pdo()->prepare($sql);
    $st->execute($params);
    json_out(['histories' => $st->fetchAll()]);
}

require_admin();
$b      = body();
$action = (string)($b['action'] ?? '');

switch ($action) {

    case 'create': {
        $userId   = (string)($b['user_id'] ?? '');
        $date     = (string)($b['date'] ?? '');
        $menuName = trim((string)($b['menu_name'] ?? ''));
        if ($userId === '' || !valid_date($date) || $menuName === '') {
            fail('お客様・施術日・メニューは必須です');
        }
        $st = pdo()->prepare("SELECT name FROM users WHERE id = ? AND role = 'customer'");
        $st->execute([$userId]);
        $user = $st->fetch();
        if (!$user) fail('顧客が見つかりません', 404);

        pdo()->prepare(
            'INSERT INTO histories (user_id, user_name, date, stylist_name, menu_name, price, memo)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $user['name'], $date,
            trim((string)($b['stylist_name'] ?? '')),
            $menuName,
            (int)($b['price'] ?? 0),
            trim((string)($b['memo'] ?? '')),
        ]);
        json_out(['ok' => true]);
    }

    case 'delete': {
        $id = (int)($b['id'] ?? 0);
        pdo()->prepare('DELETE FROM histories WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }

    default:
        fail('不正なリクエストです', 404);
}
