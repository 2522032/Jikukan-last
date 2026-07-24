<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/connect_db.php';

/*
 * CSVファイル
 */
$csvPath = __DIR__ . '/130001_evacuation_center.csv';

/*
 * CSVが存在するか確認
 */
if (!file_exists($csvPath)) {
    exit(
        'CSVファイルが見つかりません：'
        . htmlspecialchars(
            $csvPath,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

/*
 * CSVを開く
 */
$file = fopen($csvPath, 'r');

if ($file === false) {
    exit('CSVファイルを開けませんでした。');
}

/*
 * CSVの設備情報をBOOLEAN用文字列へ変換する関数
 */
function csvToBooleanString(?string $value): string
{
    $value = mb_strtolower(
        trim((string)$value)
    );

    $trueValues = [
        '○',
        '〇',
        'o',
        '1',
        'true',
        't',
        'あり',
        '有'
    ];

    return in_array(
        $value,
        $trueValues,
        true
    )
        ? 'true'
        : 'false';
}

/*
 * CSVの見出し行を探す
 */
$headerFound = false;
$lineNumber = 0;

while (
    ($row = fgetcsv(
        $file,
        0,
        ',',
        '"',
        '\\'
    )) !== false
) {
    $lineNumber++;

    if (!isset($row[0])) {
        continue;
    }

    /*
     * UTF-8 BOMを削除
     */
    $firstColumn = preg_replace(
        '/^\xEF\xBB\xBF/',
        '',
        trim((string)$row[0])
    );

    /*
     * 「避難所_施設名称」から始まる行を見出しにする
     */
    if (
        $firstColumn === '避難所_施設名称'
        || str_contains(
            $firstColumn,
            '避難所_施設名称'
        )
    ) {
        $headerFound = true;
        break;
    }
}

if (!$headerFound) {
    fclose($file);

    exit(
        'CSVの見出し行が見つかりませんでした。'
    );
}

/*
 * INSERT文
 */
$sql = "
    INSERT INTO evacuation_app.evacuation_centers (
        facility_name,
        municipality,
        address,
        latitude,
        longitude,
        elevator,
        slope,
        braille_block,
        wheelchair_toilet,
        other_equipment,
        information_date
    )
    VALUES (
        :facility_name,
        :municipality,
        :address,
        :latitude,
        :longitude,
        CAST(:elevator AS BOOLEAN),
        CAST(:slope AS BOOLEAN),
        CAST(:braille_block AS BOOLEAN),
        CAST(:wheelchair_toilet AS BOOLEAN),
        :other_equipment,
        CURRENT_DATE
    )
";

/*
 * INSERT文を準備
 */
$stmt = $pdo->prepare($sql);

$insertCount = 0;
$skipCount = 0;
$errors = [];

/*
 * 開発中に毎回CSVを入れ直す場合だけ使用
 *
 * すでに登録済みのデータを消してから
 * CSVを登録し直す
 */
$pdo->exec("
    TRUNCATE TABLE
        evacuation_app.evacuation_centers
    RESTART IDENTITY
");

try {
    /*
     * トランザクション開始
     */
    $pdo->beginTransaction();

    /*
     * データ行を1行ずつ読む
     */
    while (
        ($row = fgetcsv(
            $file,
            0,
            ',',
            '"',
            '\\'
        )) !== false
    ) {
        $lineNumber++;

        /*
         * 完全な空行を無視
         */
        if (
            count($row) === 1
            && trim((string)$row[0]) === ''
        ) {
            continue;
        }

        /*
         * 必要な列は12列
         */
        if (count($row) < 12) {
            $skipCount++;

            $errors[] =
                $lineNumber
                . '行目：列数が不足しています。';

            continue;
        }

        /*
         * CSVの列番号
         *
         * 0  避難所_施設名称
         * 1  地方公共団体コード
         * 2  都道府県
         * 3  指定市区町村名
         * 4  所在地住所
         * 5  緯度
         * 6  経度
         * 7  エレベーター・1階避難スペース
         * 8  スロープ等
         * 9  点字ブロック
         * 10 車椅子使用者対応トイレ
         * 11 その他
         */
        $facilityName = trim(
            (string)$row[0]
        );

        $municipality = trim(
            (string)$row[3]
        );

        $address = trim(
            (string)$row[4]
        );

        $latitudeText = trim(
            (string)$row[5]
        );

        $longitudeText = trim(
            (string)$row[6]
        );

        $elevatorText = trim(
            (string)$row[7]
        );

        $slopeText = trim(
            (string)$row[8]
        );

        $brailleBlockText = trim(
            (string)$row[9]
        );

        $wheelchairToiletText = trim(
            (string)$row[10]
        );

        $otherEquipment = trim(
            (string)$row[11]
        );

        /*
         * 施設名チェック
         */
        if ($facilityName === '') {
            $skipCount++;

            $errors[] =
                $lineNumber
                . '行目：施設名がありません。';

            continue;
        }

        /*
         * 緯度・経度チェック
         */
        if (
            !is_numeric($latitudeText)
            || !is_numeric($longitudeText)
        ) {
            $skipCount++;

            $errors[] =
                $lineNumber
                . '行目：緯度または経度が不正です。';

            continue;
        }

        /*
         * PostgreSQLへ登録
         */
        $stmt->execute([
            ':facility_name' =>
                $facilityName,

            ':municipality' =>
                $municipality !== ''
                    ? $municipality
                    : null,

            ':address' =>
                $address !== ''
                    ? $address
                    : null,

            ':latitude' =>
                (float)$latitudeText,

            ':longitude' =>
                (float)$longitudeText,

            ':elevator' =>
                csvToBooleanString(
                    $elevatorText
                ),

            ':slope' =>
                csvToBooleanString(
                    $slopeText
                ),

            ':braille_block' =>
                csvToBooleanString(
                    $brailleBlockText
                ),

            ':wheelchair_toilet' =>
                csvToBooleanString(
                    $wheelchairToiletText
                ),

            ':other_equipment' =>
                $otherEquipment !== ''
                    ? $otherEquipment
                    : null
        ]);

        $insertCount++;
    }

    /*
     * 登録確定
     */
    $pdo->commit();

} catch (Throwable $e) {
    /*
     * エラー時はすべて取り消す
     */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fclose($file);

    echo "CSV登録失敗\n";
    echo "行番号：" . $lineNumber . "\n";
    echo $e->getMessage() . "\n";

    exit;
}

/*
 * CSVを閉じる
 */
fclose($file);

/*
 * 結果表示
 */
echo "CSV登録完了\n";
echo "登録件数：" . $insertCount . "件\n";
echo "スキップ件数：" . $skipCount . "件\n";

/*
 * スキップ内容を最大20件表示
 */
if (count($errors) > 0) {
    echo "\nスキップ内容\n";

    foreach (
        array_slice(
            $errors,
            0,
            20
        ) as $error
    ) {
        echo $error . "\n";
    }
}