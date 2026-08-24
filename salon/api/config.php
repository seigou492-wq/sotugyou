<?php
/* =====================================================
   データベース接続設定
   環境に合わせてここだけ書き換えてください。

   ◆ ローカルのXAMPPで動かす場合（初期状態のまま）
       DB_HOST: 127.0.0.1 / DB_NAME: nagase_salon
       DB_USER: root      / DB_PASS: （空）

   ◆ レンタルサーバーで動かす場合
       サーバーの管理画面（MySQL設定・データベース設定の
       ページ）に書いてある値をそのまま入れてください。
       例:
       DB_HOST: localhost（サーバーにより mysqlXX.example.jp 等）
       DB_NAME: dora4205_nagase など（自分のデータベース名）
       DB_USER: dora4205_nagase など（DBユーザー名）
       DB_PASS: 管理画面で設定したDBパスワード
   ===================================================== */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'nagase_salon');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP初期状態はパスワードなし
