-- =====================================================
-- 永瀬美容室 予約・顧客管理システム【レンタルサーバー用】
-- テーブル定義 + 初期データ（CREATE DATABASE なし版）
--
-- レンタルサーバー（エックスサーバー・XREA・スターサーバー・
-- ロリポップ等）では新しいデータベースを作る権限がないため、
-- サーバー側で用意されたデータベースの中にテーブルを作ります。
--
-- 【phpMyAdmin でのセットアップ手順】
--   1. 画面左のデータベース一覧から、自分のデータベース名
--      （例: dora4205_nagase）を必ずクリックして選択する
--   2. 「インポート」タブでこのファイルを選択して実行
--   3. salon/api/config.php の DB_NAME・DB_USER・DB_PASS を
--      サーバーの管理画面に書いてある値に書き換える
--   4. salon フォルダをサーバーにアップロードしてアクセス
--      管理者: ID 123 / パスワード 123
--      お客様: ID 000 / パスワード 000
--
-- ※ ローカルのXAMPPで動かす場合は setup.sql の方を使ってください。
-- =====================================================

-- 日本語の文字化け防止（クライアント接続の文字コードを明示）
SET NAMES utf8mb4;
-- 会員（お客様・管理者）
CREATE TABLE IF NOT EXISTS users (
  id            VARCHAR(20)  NOT NULL COMMENT '会員ID（ログインID）',
  password_hash VARCHAR(255) NOT NULL COMMENT 'パスワード（bcryptハッシュ）',
  role          ENUM('admin','customer') NOT NULL DEFAULT 'customer' COMMENT '権限',
  name          VARCHAR(50)  NOT NULL COMMENT '氏名',
  kana          VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'フリガナ',
  phone         VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '電話番号',
  email         VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'メールアドレス',
  note          TEXT         NULL COMMENT 'カルテメモ（髪質・アレルギー等）',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB COMMENT='会員';

-- スタイリスト
CREATE TABLE IF NOT EXISTS stylists (
  id     INT          NOT NULL AUTO_INCREMENT,
  name   VARCHAR(50)  NOT NULL COMMENT '氏名',
  title  VARCHAR(100) NOT NULL DEFAULT 'スタイリスト' COMMENT '肩書き',
  active TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=表示 0=削除済み(論理削除)',
  PRIMARY KEY (id)
) ENGINE=InnoDB COMMENT='スタイリスト';

-- 施術メニュー
CREATE TABLE IF NOT EXISTS menus (
  id      INT          NOT NULL AUTO_INCREMENT,
  name    VARCHAR(100) NOT NULL COMMENT 'メニュー名',
  minutes INT          NOT NULL COMMENT '所要時間（分）',
  price   INT          NOT NULL DEFAULT 0 COMMENT '料金（円・税込）',
  active  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=表示 0=削除済み(論理削除)',
  PRIMARY KEY (id)
) ENGINE=InnoDB COMMENT='施術メニュー';

-- 予約
CREATE TABLE IF NOT EXISTS reservations (
  id         INT         NOT NULL AUTO_INCREMENT,
  user_id    VARCHAR(20) NOT NULL COMMENT '予約者',
  stylist_id INT         NOT NULL COMMENT '担当スタイリスト',
  menu_id    INT         NOT NULL COMMENT '施術メニュー',
  date       DATE        NOT NULL COMMENT '予約日',
  time       TIME        NOT NULL COMMENT '開始時刻',
  status     ENUM('active','cancelled','done') NOT NULL DEFAULT 'active' COMMENT '状態',
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stylist_date (stylist_id, date),
  KEY idx_user (user_id),
  CONSTRAINT fk_resv_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_resv_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id),
  CONSTRAINT fk_resv_menu    FOREIGN KEY (menu_id)    REFERENCES menus(id)
) ENGINE=InnoDB COMMENT='予約';

-- 施術履歴（カルテ）
-- 顧客が退会・削除されても履歴は残すため user_id は SET NULL、氏名はスナップショット保存
CREATE TABLE IF NOT EXISTS histories (
  id           INT          NOT NULL AUTO_INCREMENT,
  user_id      VARCHAR(20)  NULL COMMENT '顧客（削除時はNULL）',
  user_name    VARCHAR(50)  NOT NULL COMMENT '顧客名（記録時点のスナップショット）',
  date         DATE         NOT NULL COMMENT '施術日',
  stylist_name VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '担当（記録時点）',
  menu_name    VARCHAR(100) NOT NULL COMMENT '施術メニュー',
  price        INT          NOT NULL DEFAULT 0 COMMENT '料金（円）',
  memo         TEXT         NULL COMMENT 'カルテメモ（薬剤・仕上がり等）',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_date (date),
  CONSTRAINT fk_hist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='施術履歴';

-- =====================================================
-- 初期データ（既に登録済みの場合はスキップされます）
-- =====================================================

-- テストアカウント
--   管理者:  ID 123 / パスワード 123
--   お客様:  ID 000 / パスワード 000
-- パスワードは PHP の password_hash()（bcrypt）で生成したハッシュ値
INSERT IGNORE INTO users (id, password_hash, role, name, kana, phone, email, note) VALUES
  ('123', '$2y$12$VbiKjjrE6SJIF8kyf81dW.iNIgOMgeUWiLgvvDoM5IvhdxayUi.H.', 'admin',
   '永瀬 店長', 'ナガセ テンチョウ', '', '', '管理者アカウント'),
  ('000', '$2y$12$NFAgmnMNED/yD/RI7PpWR.T7rlgAmffybnx7NeggNvsS7Yp9aD016', 'customer',
   '山田 花子', 'ヤマダ ハナコ', '090-0000-0000', 'hanako@example.com', 'テスト用のお客様アカウント');

-- スタイリスト
INSERT IGNORE INTO stylists (id, name, title) VALUES
  (1, '永瀬 貴之', '店長 / トップスタイリスト'),
  (2, '佐藤 美咲', 'スタイリスト'),
  (3, '田中 玲奈', 'カラーリスト');

-- 施術メニュー
INSERT IGNORE INTO menus (id, name, minutes, price) VALUES
  (1, 'カット',            60,  4400),
  (2, 'カット + カラー',   120, 9900),
  (3, 'カット + パーマ',   150, 11000),
  (4, 'カラーのみ',        90,  7150),
  (5, 'トリートメント',    30,  3300),
  (6, 'ヘッドスパ',        45,  4950);

-- レート制限（ログイン総当たり攻撃対策）の記録用テーブル
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       VARCHAR(64) NOT NULL COMMENT '制限対象（例: login:IPアドレス）',
  cnt          INT NOT NULL DEFAULT 1 COMMENT '時間窓内の試行回数',
  window_start INT NOT NULL COMMENT '時間窓の開始（UNIX秒）',
  PRIMARY KEY (bucket)
) ENGINE=InnoDB COMMENT='レート制限';
