<?php
/* =====================================================
   セットアップスクリプト
   ブラウザで http://localhost/salon/api/install.php を
   1回開くと、データベース・テーブル・初期データを作成します。
   （何度実行してもデータは二重登録されません）
   ===================================================== */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$messages = [];
$errors   = [];

try {
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    try {
        // まずDB名ありで接続（レンタルサーバーはDBが用意済みなのでこちらで成功する）
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, $opt
        );
    } catch (PDOException $e) {
        // DBがまだ無い場合（XAMPP初回）はDB名なしで接続して作成する
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
            DB_USER, DB_PASS, $opt
        );
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
        $messages[] = 'データベース ' . DB_NAME . ' を作成しました';
    }

    // セットアップ済みなら何もしない
    // （公開サーバーで誰でも実行できると攻撃の足がかりになるため）
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        if ((int)$st->fetchColumn() > 0) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">',
                 '<title>セットアップ済み</title></head><body style="font-family:sans-serif;max-width:640px;margin:60px auto;">',
                 '<h1>セットアップ済みです</h1><p>このシステムは既にセットアップが完了しています。</p>',
                 '<p><a href="../index.html">アプリを開く</a></p></body></html>';
            exit;
        }
    } catch (PDOException $e) { /* テーブル未作成＝未セットアップなので続行 */ }

    // スキーマ（sql/setup.sql）を実行
    // CREATE DATABASE / USE は上で処理済みのためスキップする
    // （レンタルサーバーではDB作成権限がなくエラーになるため）
    $sqlFile = __DIR__ . '/../sql/setup.sql';
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('sql/setup.sql が見つかりません');
    }
    // コメント行を除去し、セミコロン区切りで1文ずつ実行
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) continue;
        $pdo->exec($stmt);
    }
    $messages[] = 'テーブルを作成しました（既にある場合はそのまま）';

    // ---- 初期データ投入（存在しない場合のみ） ----
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO users (id, password_hash, role, name, kana, phone, email, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute(['123', password_hash('123', PASSWORD_DEFAULT), 'admin',
                   '永瀬 店長', 'ナガセ テンチョウ', '', '', '管理者アカウント']);
    $ins->execute(['000', password_hash('000', PASSWORD_DEFAULT), 'customer',
                   '山田 花子', 'ヤマダ ハナコ', '090-0000-0000', 'hanako@example.com', 'テスト用のお客様アカウント']);
    $messages[] = 'テストアカウントを登録しました（管理者 123/123、お客様 000/000）';

    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM stylists')->fetchColumn();
    if ($cnt === 0) {
        $st = $pdo->prepare('INSERT INTO stylists (name, title) VALUES (?, ?)');
        $st->execute(['永瀬 貴之', '店長 / トップスタイリスト']);
        $st->execute(['佐藤 美咲', 'スタイリスト']);
        $st->execute(['田中 玲奈', 'カラーリスト']);
        $messages[] = 'スタイリスト3名を登録しました';
    }

    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM menus')->fetchColumn();
    if ($cnt === 0) {
        $st = $pdo->prepare('INSERT INTO menus (name, minutes, price) VALUES (?, ?, ?)');
        $st->execute(['カット', 60, 4400]);
        $st->execute(['カット + カラー', 120, 9900]);
        $st->execute(['カット + パーマ', 150, 11000]);
        $st->execute(['カラーのみ', 90, 7150]);
        $st->execute(['トリートメント', 30, 3300]);
        $st->execute(['ヘッドスパ', 45, 4950]);
        $messages[] = '施術メニュー6件を登録しました';
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="UTF-8"><title>セットアップ - 永瀬美容室</title>
<style>
  body{font-family:sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#3b3230;}
  .ok{background:#e8f1ea;color:#2f6b3c;padding:12px 16px;border-radius:8px;margin-bottom:10px;}
  .ng{background:#f7e6e5;color:#a33;padding:12px 16px;border-radius:8px;margin-bottom:10px;}
  a.btn{display:inline-block;margin-top:20px;background:#8a6d5c;color:#fff;
        padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;}
</style></head>
<body>
<h1>セットアップ</h1>
<?php foreach ($messages as $m): ?><div class="ok">✅ <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $m): ?><div class="ng">❌ <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<?php if (!$errors): ?>
  <p>セットアップが完了しました。下のボタンからアプリを開いてください。</p>
  <a class="btn" href="../index.html">アプリを開く</a>
<?php else: ?>
  <p>XAMPPの場合: コントロールパネルで MySQL が起動しているか確認してから、ページを再読み込みしてください。<br>
     レンタルサーバーの場合: api/config.php の DB_NAME・DB_USER・DB_PASS がサーバー管理画面の値と一致しているか確認してください。</p>
<?php endif; ?>
</body>
</html>
