-- =====================================================
-- 永瀬美容室 予約・顧客管理システム テーブル定義
-- データベース: nagase_salon
--
-- ※ 初期データ（テストアカウント等）はパスワードを
--    ハッシュ化して登録するため api/install.php が投入します。
--    このファイルは phpMyAdmin での手動構築・提出資料用です。
-- =====================================================

CREATE DATABASE IF NOT EXISTS nagase_salon
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nagase_salon;

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
