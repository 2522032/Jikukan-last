<?php

$host = 'localhost';
$dbname = 'yf002023';//自分のdbname
$user = 'yf002023';
$pass = 'eGnH4e4X';

$pdo = new PDO(
    "pgsql:host=$host;dbname=$dbname",
    $user,
    $pass
);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 使用するスキーマを指定
//$pdo->exec("SET search_path TO evacuation_app");