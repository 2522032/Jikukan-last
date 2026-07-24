<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/connect_db.php';

try {
    /*
     * 避難所アプリ用のスキーマを作成
     */
    $pdo->exec("
        CREATE SCHEMA IF NOT EXISTS evacuation_app
    ");

    /*
     * 避難所テーブルを作成
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS evacuation_app.evacuation_centers (
            id SERIAL PRIMARY KEY,

            facility_name VARCHAR(255) NOT NULL,
            municipality VARCHAR(100),
            address TEXT,

            latitude DOUBLE PRECISION NOT NULL,
            longitude DOUBLE PRECISION NOT NULL,

            elevator BOOLEAN NOT NULL DEFAULT FALSE,
            slope BOOLEAN NOT NULL DEFAULT FALSE,
            braille_block BOOLEAN NOT NULL DEFAULT FALSE,
            wheelchair_toilet BOOLEAN NOT NULL DEFAULT FALSE,

            other_equipment TEXT,

            information_date DATE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /*
     * 市区町村検索を速くするインデックス
     */
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_evacuation_centers_municipality
        ON evacuation_app.evacuation_centers (
            municipality
        )
    ");

    /*
     * 緯度・経度検索用インデックス
     */
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS
            idx_evacuation_centers_location
        ON evacuation_app.evacuation_centers (
            latitude,
            longitude
        )
    ");

    echo '<h2>データベースの準備が完了しました</h2>';
    echo '<p>スキーマ：evacuation_app</p>';
    echo '<p>テーブル：evacuation_centers</p>';

} catch (PDOException $e) {
    error_log($e->getMessage());

    echo '<h2>データベース作成に失敗しました</h2>';
    echo '<p>' . htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
}