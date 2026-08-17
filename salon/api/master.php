<?php
/* =====================================================
   マスタAPI（施術メニュー・スタイリスト）
   GET                                  … 一覧取得（要ログイン）
   POST {action:"save_menu", ...}       … メニュー追加/更新（管理者）
   POST {action:"delete_menu", id}      … メニュー削除＝論理削除（管理者）
   POST {action:"save_stylist", ...}    … スタイリスト追加/更新（管理者）
   POST {action:"delete_stylist", id}   … スタイリスト削除＝論理削除（管理者）
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    json_out([
        'menus'    => pdo()->query('SELECT id, name, minutes, price FROM menus    WHERE active = 1 ORDER BY id')->fetchAll(),
        'stylists' => pdo()->query('SELECT id, name, title          FROM stylists WHERE active = 1 ORDER BY id')->fetchAll(),
    ]);
}

require_admin();
$b      = body();
$action = (string)($b['action'] ?? '');

switch ($action) {

    case 'save_menu': {
        $name    = trim((string)($b['name'] ?? ''));
        $minutes = (int)($b['minutes'] ?? 0);
        $price   = (int)($b['price'] ?? 0);
        if ($name === '' || $minutes <= 0) fail('メニュー名と所要時間は必須です');
        if ($minutes % 15 !== 0) fail('所要時間は15分単位で入力してください');

        $id = (int)($b['id'] ?? 0);
        if ($id > 0) {
            pdo()->prepare('UPDATE menus SET name = ?, minutes = ?, price = ? WHERE id = ?')
                 ->execute([$name, $minutes, $price, $id]);
        } else {
            pdo()->prepare('INSERT INTO menus (name, minutes, price) VALUES (?, ?, ?)')
                 ->execute([$name, $minutes, $price]);
        }
        json_out(['ok' => true]);
    }

    case 'delete_menu': {
        $id = (int)($b['id'] ?? 0);
        $st = pdo()->prepare(
            "SELECT COUNT(*) FROM reservations WHERE menu_id = ? AND status = 'active' AND date >= CURDATE()"
        );
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('このメニューの予約が入っているため削除できません');
        pdo()->prepare('UPDATE menus SET active = 0 WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }

    case 'save_stylist': {
        $name  = trim((string)($b['name'] ?? ''));
        $title = trim((string)($b['title'] ?? 'スタイリスト'));
        if ($name === '') fail('氏名は必須です');

        $id = (int)($b['id'] ?? 0);
        if ($id > 0) {
            pdo()->prepare('UPDATE stylists SET name = ?, title = ? WHERE id = ?')
                 ->execute([$name, $title, $id]);
        } else {
            pdo()->prepare('INSERT INTO stylists (name, title) VALUES (?, ?)')
                 ->execute([$name, $title]);
        }
        json_out(['ok' => true]);
    }

    case 'delete_stylist': {
        $id = (int)($b['id'] ?? 0);
        $st = pdo()->prepare(
            "SELECT COUNT(*) FROM reservations WHERE stylist_id = ? AND status = 'active' AND date >= CURDATE()"
        );
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) fail('このスタイリストの予約が入っているため削除できません');
        pdo()->prepare('UPDATE stylists SET active = 0 WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }

    default:
        fail('不正なリクエストです', 404);
}
