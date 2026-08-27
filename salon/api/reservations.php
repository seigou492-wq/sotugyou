<?php
/* =====================================================
   予約API
   GET  ?action=slots&stylist_id&date&menu_id[&ignore_id] … 空き時間取得
   GET  ?action=list&scope=mine                           … 自分の予約一覧
   GET  ?action=list&date=YYYY-MM-DD[&status=]            … 指定日の予約一覧（管理者）
   GET  ?action=counts&year&month                         … 月内の日別予約件数（管理者）
   GET  ?action=stats                                     … ダッシュボード統計（管理者）
   POST {action:"create", stylist_id, menu_id, date, time} … 予約作成
   POST {action:"change", id, date, time}                  … 日時変更
   POST {action:"cancel", id}                              … キャンセル
   POST {action:"done", id, menu_name, price, memo}        … 来店済み処理＋履歴登録（管理者）
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? (body()['action'] ?? '');

/* ---------- 空き時間計算 ----------
   同じスタイリスト・同じ日の予約中(active)の時間帯と重ならない
   開始時刻のリストを返す。$forUpdate=true なら行ロックを取得
   （予約確定時のダブルブッキング防止用） */
function calc_slots(int $stylistId, string $date, int $menuId, ?int $ignoreId, bool $forUpdate = false): array {
    $st = pdo()->prepare('SELECT minutes FROM menus WHERE id = ?');
    $st->execute([$menuId]);
    $menu = $st->fetch();
    if (!$menu) fail('メニューが見つかりません');
    $dur = (int)$menu['minutes'];

    $sql = "SELECT r.time, m.minutes
            FROM reservations r
            JOIN menus m ON m.id = r.menu_id
            WHERE r.stylist_id = ? AND r.date = ? AND r.status = 'active'";
    $params = [$stylistId, $date];
    if ($ignoreId !== null) {
        $sql .= ' AND r.id <> ?';
        $params[] = $ignoreId;
    }
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $st = pdo()->prepare($sql);
    $st->execute($params);

    $busy = [];
    foreach ($st->fetchAll() as $row) {
        $s = time_to_min($row['time']);
        $busy[] = [$s, $s + (int)$row['minutes']];
    }

    $isToday = ($date === date('Y-m-d'));
    $nowMin  = (int)date('G') * 60 + (int)date('i');

    $slots = [];
    for ($t = OPEN_MIN; $t + $dur <= CLOSE_MIN; $t += SLOT_MIN) {
        $overlap = false;
        foreach ($busy as [$bs, $be]) {
            if ($t < $be && $t + $dur > $bs) { $overlap = true; break; }
        }
        $past = $isToday && $t <= $nowMin;
        $slots[] = ['time' => min_to_time($t), 'ok' => !$overlap && !$past];
    }
    return $slots;
}

/* 予約日・時刻の共通チェック（過去日・定休日・営業時間） */
function validate_slot_request(string $date, string $time): void {
    if (!valid_date($date) || !valid_time($time)) fail('日時の形式が不正です');
    if ($date < date('Y-m-d')) fail('過去の日付は指定できません');
    if (is_closed_day($date)) fail('定休日（火曜）は予約できません');
    $min = time_to_min($time);
    if ($min < OPEN_MIN || $min >= CLOSE_MIN || $min % SLOT_MIN !== 0) fail('営業時間外です');
}

/* 一覧用の共通SELECT（会員名・スタイリスト名・メニュー情報をJOIN） */
const RESV_SELECT = "
    SELECT r.id, r.user_id, r.stylist_id, r.menu_id,
           DATE_FORMAT(r.date, '%Y-%m-%d') AS date,
           DATE_FORMAT(r.time, '%H:%i')    AS time,
           r.status,
           u.name  AS user_name, u.phone AS user_phone,
           s.name  AS stylist_name,
           m.name  AS menu_name, m.minutes, m.price
    FROM reservations r
    JOIN users    u ON u.id = r.user_id
    JOIN stylists s ON s.id = r.stylist_id
    JOIN menus    m ON m.id = r.menu_id";

switch ($action) {

    case 'slots': {
        require_login();
        $stylistId = (int)($_GET['stylist_id'] ?? 0);
        $menuId    = (int)($_GET['menu_id'] ?? 0);
        $date      = (string)($_GET['date'] ?? '');
        $ignoreId  = isset($_GET['ignore_id']) ? (int)$_GET['ignore_id'] : null;
        if (!valid_date($date)) fail('日付の形式が不正です');
        if (is_closed_day($date)) json_out(['slots' => []]);
        json_out(['slots' => calc_slots($stylistId, $date, $menuId, $ignoreId)]);
    }

    case 'list': {
        $me = require_login();
        if (($_GET['scope'] ?? '') === 'mine') {
            $st = pdo()->prepare(RESV_SELECT . ' WHERE r.user_id = ? ORDER BY r.date DESC, r.time DESC');
            $st->execute([$me['id']]);
            json_out(['reservations' => $st->fetchAll()]);
        }
        // 日付指定の一覧は管理者のみ
        require_admin();
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!valid_date($date)) fail('日付の形式が不正です');
        $sql    = RESV_SELECT . ' WHERE r.date = ?';
        $params = [$date];
        $status = (string)($_GET['status'] ?? '');
        if (in_array($status, ['active', 'cancelled', 'done'], true)) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.time';
        $st = pdo()->prepare($sql);
        $st->execute($params);
        json_out(['reservations' => $st->fetchAll()]);
    }

    case 'counts': {
        require_admin();
        $year  = (int)($_GET['year'] ?? 0);
        $month = (int)($_GET['month'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12) fail('年月が不正です');
        $st = pdo()->prepare(
            "SELECT DATE_FORMAT(date, '%Y-%m-%d') AS d, COUNT(*) AS c
             FROM reservations
             WHERE status = 'active' AND YEAR(date) = ? AND MONTH(date) = ?
             GROUP BY date"
        );
        $st->execute([$year, $month]);
        $counts = [];
        foreach ($st->fetchAll() as $row) $counts[$row['d']] = (int)$row['c'];
        json_out(['counts' => $counts]);
    }

    case 'stats': {
        require_admin();
        $today = date('Y-m-d');
        $ym    = date('Y-m');
        $p = pdo();
        $st = $p->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'active' AND date = ?");
        $st->execute([$today]);
        $todayCnt = (int)$st->fetchColumn();
        $st = $p->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'active' AND date >= ?");
        $st->execute([$today]);
        $futureCnt = (int)$st->fetchColumn();
        $customerCnt = (int)$p->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
        $st = $p->prepare("SELECT COALESCE(SUM(price), 0) FROM histories WHERE DATE_FORMAT(date, '%Y-%m') = ?");
        $st->execute([$ym]);
        $monthSales = (int)$st->fetchColumn();
        json_out(['today' => $todayCnt, 'future' => $futureCnt,
                  'customers' => $customerCnt, 'month_sales' => $monthSales]);
    }

    case 'create': {
        $me = require_login();
        $b  = body();
        $stylistId = (int)($b['stylist_id'] ?? 0);
        $menuId    = (int)($b['menu_id'] ?? 0);
        $date      = (string)($b['date'] ?? '');
        $time      = (string)($b['time'] ?? '');
        validate_slot_request($date, $time);

        $st = pdo()->prepare('SELECT COUNT(*) FROM stylists WHERE id = ? AND active = 1');
        $st->execute([$stylistId]);
        if ((int)$st->fetchColumn() === 0) fail('スタイリストが見つかりません');
        demo_cap_rows('reservations', 1000);

        // トランザクション内で行ロックを取り、ダブルブッキングを防止
        pdo()->beginTransaction();
        try {
            $slots = calc_slots($stylistId, $date, $menuId, null, true);
            $slot  = null;
            foreach ($slots as $s) if ($s['time'] === $time) { $slot = $s; break; }
            if (!$slot || !$slot['ok']) {
                pdo()->rollBack();
                fail('申し訳ありません。その時間は埋まってしまいました。', 409);
            }
            pdo()->prepare(
                'INSERT INTO reservations (user_id, stylist_id, menu_id, date, time) VALUES (?, ?, ?, ?, ?)'
            )->execute([$me['id'], $stylistId, $menuId, $date, $time . ':00']);
            pdo()->commit();
        } catch (PDOException $e) {
            pdo()->rollBack();
            fail('予約の登録に失敗しました', 500);
        }
        json_out(['ok' => true]);
    }

    case 'change': {
        $me = require_login();
        $b  = body();
        $id   = (int)($b['id'] ?? 0);
        $date = (string)($b['date'] ?? '');
        $time = (string)($b['time'] ?? '');
        validate_slot_request($date, $time);

        $st = pdo()->prepare('SELECT * FROM reservations WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) fail('予約が見つかりません', 404);
        if ($me['role'] !== 'admin' && $r['user_id'] !== $me['id']) fail('権限がありません', 403);
        if ($r['status'] !== 'active') fail('この予約は変更できません');

        pdo()->beginTransaction();
        try {
            $slots = calc_slots((int)$r['stylist_id'], $date, (int)$r['menu_id'], $id, true);
            $slot  = null;
            foreach ($slots as $s) if ($s['time'] === $time) { $slot = $s; break; }
            if (!$slot || !$slot['ok']) {
                pdo()->rollBack();
                fail('その時間は埋まってしまいました', 409);
            }
            pdo()->prepare('UPDATE reservations SET date = ?, time = ? WHERE id = ?')
                 ->execute([$date, $time . ':00', $id]);
            pdo()->commit();
        } catch (PDOException $e) {
            pdo()->rollBack();
            fail('予約の変更に失敗しました', 500);
        }
        json_out(['ok' => true]);
    }

    case 'cancel': {
        $me = require_login();
        $id = (int)(body()['id'] ?? 0);
        $st = pdo()->prepare('SELECT * FROM reservations WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) fail('予約が見つかりません', 404);
        if ($me['role'] !== 'admin' && $r['user_id'] !== $me['id']) fail('権限がありません', 403);
        if ($r['status'] !== 'active') fail('この予約はキャンセルできません');
        pdo()->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        json_out(['ok' => true]);
    }

    case 'done': {
        require_admin();
        $b  = body();
        $id = (int)($b['id'] ?? 0);
        $st = pdo()->prepare(RESV_SELECT . ' WHERE r.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) fail('予約が見つかりません', 404);
        if ($r['status'] !== 'active') fail('この予約は処理済みです');

        $menuName = trim((string)($b['menu_name'] ?? '')) ?: $r['menu_name'];
        $price    = (int)($b['price'] ?? $r['price']);
        $memo     = trim((string)($b['memo'] ?? ''));
        check_len($menuName, 100, 'メニュー名');
        check_len($memo, 1000, 'メモ');
        if ($price < 0 || $price > 1000000) fail('料金が正しくありません');

        // 予約を来店済みにし、施術履歴（カルテ）を同時登録
        pdo()->beginTransaction();
        try {
            pdo()->prepare("UPDATE reservations SET status = 'done' WHERE id = ?")->execute([$id]);
            pdo()->prepare(
                'INSERT INTO histories (user_id, user_name, date, stylist_name, menu_name, price, memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$r['user_id'], $r['user_name'], $r['date'], $r['stylist_name'], $menuName, $price, $memo]);
            pdo()->commit();
        } catch (PDOException $e) {
            pdo()->rollBack();
            fail('来店済み処理に失敗しました', 500);
        }
        json_out(['ok' => true]);
    }

    default:
        fail('不正なリクエストです', 404);
}
