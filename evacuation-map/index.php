<?php
header("Content-Type: text/html; charset=UTF-8");

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function toUtf8($value): string
{
    $value = (string)$value;
    $encoding = mb_detect_encoding(
        $value,
        ["UTF-8", "SJIS-win", "CP932", "EUC-JP"],
        true
    );

    if ($encoding === false || $encoding === "UTF-8") {
        return trim($value);
    }

    return trim(mb_convert_encoding($value, "UTF-8", $encoding));
}

function hasEquipment($value): bool
{
    $value = toUtf8($value);

    return in_array(
        $value,
        ["○", "〇", "有", "あり", "有り", "1", "true", "TRUE"],
        true
    );
}

function calculateDistance(
    float $lat1,
    float $lng1,
    float $lat2,
    float $lng2
): float {
    $earthRadius = 6371000;

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);

    $a =
        sin($deltaLat / 2) ** 2
        + cos($lat1Rad)
        * cos($lat2Rad)
        * sin($deltaLng / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/*
CSVはindex.phpと同じフォルダに置く。
実際のファイル名に合わせること。
*/
$csvFile = __DIR__ . "/130001_evacuation_center.csv";

$startPoints = [
    "musashino" => [
        "name" => "武蔵野大学 有明キャンパス",
        "lat" => 35.63119745,
        "lng" => 139.7863502
    ],
    "ariake_station" => [
        "name" => "有明駅",
        "lat" => 35.6346,
        "lng" => 139.7930
    ],
    "kokusai_tenjijo" => [
        "name" => "国際展示場駅",
        "lat" => 35.6342,
        "lng" => 139.7910
    ],
    "tokyo_station" => [
        "name" => "東京駅",
        "lat" => 35.6812,
        "lng" => 139.7671
    ]
];

$userTypes = [
    "general" => [
        "name" => "一般",
        "speed" => 80
    ],
    "elderly" => [
        "name" => "高齢者",
        "speed" => 60
    ],
    "wheelchair" => [
        "name" => "車いす利用者",
        "speed" => 50
    ]
];

$allowedMinutes = [5, 10, 15, 30, 60];

$startPointKey = $_GET["start_point"] ?? "musashino";
$userTypeKey = $_GET["user_type"] ?? "general";
$municipality = trim($_GET["municipality"] ?? "");
$maxMinutes = filter_input(INPUT_GET, "max_minutes", FILTER_VALIDATE_INT);

if (!isset($startPoints[$startPointKey])) {
    $startPointKey = "musashino";
}

if (!isset($userTypes[$userTypeKey])) {
    $userTypeKey = "general";
}

if (
    $maxMinutes === false ||
    $maxMinutes === null ||
    !in_array($maxMinutes, $allowedMinutes, true)
) {
    $maxMinutes = 10;
}

$startPoint = $startPoints[$startPointKey];
$userType = $userTypes[$userTypeKey];
$speed = $userType["speed"];

$searched = isset($_GET["search"]);
$facilities = [];
$municipalities = [];
$errorMessage = "";

if (!file_exists($csvFile)) {
    $errorMessage =
        "CSVファイルが見つかりません。ファイル名を"
        . "「130001_evacuation_center.csv」にしてください。";
} else {
    $handle = fopen($csvFile, "r");

    if ($handle === false) {
        $errorMessage = "CSVファイルを開けませんでした。";
    } else {
        /*
        このCSVは1行目が空の見出し、2行目が本来の見出し、
        3行目が空行、4行目からデータになっている。
        */
        fgetcsv($handle);
        fgetcsv($handle);
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            /*
            CSV列：
            0 施設名
            1 地方公共団体コード
            2 都道府県
            3 指定市区町村名
            4 所在地住所
            5 緯度
            6 経度
            7 エレベーター／1階避難スペース
            8 スロープ等
            9 点字ブロック
            10 車椅子使用者対応トイレ
            11 その他
            */

            $facilityName = toUtf8($row[0] ?? "");
            $municipalityName = toUtf8($row[3] ?? "");
            $address = toUtf8($row[4] ?? "");
            $latitudeText = trim((string)($row[5] ?? ""));
            $longitudeText = trim((string)($row[6] ?? ""));

            /*
            CSV末尾に大量の空行があるため、完全な空行は飛ばす。
            */
            if (
                $facilityName === "" &&
                $municipalityName === "" &&
                $latitudeText === "" &&
                $longitudeText === ""
            ) {
                continue;
            }

            if ($municipalityName !== "") {
                $municipalities[$municipalityName] = true;
            }

            if (!$searched) {
                continue;
            }

            if (
                $facilityName === "" ||
                !is_numeric($latitudeText) ||
                !is_numeric($longitudeText)
            ) {
                continue;
            }

            if (
                $municipality !== "" &&
                $municipalityName !== $municipality
            ) {
                continue;
            }

            $elevator = hasEquipment($row[7] ?? "");
            $slope = hasEquipment($row[8] ?? "");
            $brailleBlock = hasEquipment($row[9] ?? "");
            $wheelchairToilet = hasEquipment($row[10] ?? "");
            $otherEquipment = toUtf8($row[11] ?? "");

            if (isset($_GET["elevator"]) && !$elevator) {
                continue;
            }

            if (isset($_GET["slope"]) && !$slope) {
                continue;
            }

            if (isset($_GET["braille_block"]) && !$brailleBlock) {
                continue;
            }

            if (
                isset($_GET["wheelchair_toilet"]) &&
                !$wheelchairToilet
            ) {
                continue;
            }

            $latitude = (float)$latitudeText;
            $longitude = (float)$longitudeText;

            $distance = calculateDistance(
                $startPoint["lat"],
                $startPoint["lng"],
                $latitude,
                $longitude
            );

            $minutes = (int)ceil($distance / $speed);

            if ($minutes > $maxMinutes) {
                continue;
            }

            $facilities[] = [
                "facility_name" => $facilityName,
                "municipality" => $municipalityName,
                "address" => $address,
                "latitude" => $latitude,
                "longitude" => $longitude,
                "elevator" => $elevator,
                "slope" => $slope,
                "braille_block" => $brailleBlock,
                "wheelchair_toilet" => $wheelchairToilet,
                "other_equipment" => $otherEquipment,
                "distance" => (int)round($distance),
                "minutes" => $minutes
            ];
        }

        fclose($handle);
    }
}

$municipalities = array_keys($municipalities);
sort($municipalities, SORT_STRING);

usort(
    $facilities,
    function (array $a, array $b): int {
        if ($a["minutes"] === $b["minutes"]) {
            return $a["distance"] <=> $b["distance"];
        }

        return $a["minutes"] <=> $b["minutes"];
    }
);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>東京都バリアフリー避難所検索マップ</title>

    <link rel="stylesheet" href="style.css">

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js">
    </script>
</head>
<body>

<header class="site-header">
    <div class="header-inner">
        <h1>東京都バリアフリー避難所検索マップ</h1>
        <p>
            CSVデータから、出発地点・利用者・設備・
            希望所要時間に合う避難所を検索します。
        </p>
    </div>
</header>

<main class="container">

<section class="search-card">
    <form method="GET" action="index.php">

        <div class="form-section">
            <h2>1．出発地点</h2>
            <select name="start_point">
                <?php foreach ($startPoints as $key => $point): ?>
                    <option
                        value="<?= h($key) ?>"
                        <?= $startPointKey === $key ? "selected" : "" ?>
                    >
                        <?= h($point["name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-section">
            <h2>2．利用者の種類</h2>

            <?php foreach ($userTypes as $key => $type): ?>
                <label class="radio-label">
                    <input
                        type="radio"
                        name="user_type"
                        value="<?= h($key) ?>"
                        <?= $userTypeKey === $key ? "checked" : "" ?>
                    >
                    <?= h($type["name"]) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-section">
            <h2>3．市区町村</h2>

            <select name="municipality">
                <option value="">すべて</option>

                <?php foreach ($municipalities as $name): ?>
                    <option
                        value="<?= h($name) ?>"
                        <?= $municipality === $name ? "selected" : "" ?>
                    >
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-section">
            <h2>4．必要な設備</h2>

            <div class="checkbox-list">
                <label>
                    <input
                        type="checkbox"
                        name="elevator"
                        value="1"
                        <?= isset($_GET["elevator"]) ? "checked" : "" ?>
                    >
                    エレベーター・1階避難スペース
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="slope"
                        value="1"
                        <?= isset($_GET["slope"]) ? "checked" : "" ?>
                    >
                    スロープ
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="braille_block"
                        value="1"
                        <?= isset($_GET["braille_block"]) ? "checked" : "" ?>
                    >
                    点字ブロック
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="wheelchair_toilet"
                        value="1"
                        <?= isset($_GET["wheelchair_toilet"]) ? "checked" : "" ?>
                    >
                    車いす使用者対応トイレ
                </label>
            </div>
        </div>

        <div class="form-section">
            <h2>5．希望所要時間</h2>

            <select name="max_minutes">
                <?php foreach ($allowedMinutes as $minute): ?>
                    <option
                        value="<?= $minute ?>"
                        <?= $maxMinutes === $minute ? "selected" : "" ?>
                    >
                        <?= $minute ?>分以内
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button
            class="search-button"
            type="submit"
            name="search"
            value="1"
        >
            条件に合う避難所を検索
        </button>
    </form>
</section>

<?php if ($errorMessage !== ""): ?>
    <p class="error-message"><?= h($errorMessage) ?></p>
<?php endif; ?>

<?php if ($searched && $errorMessage === ""): ?>
<section class="result-section">

    <div class="result-header">
        <div>
            <h2>検索結果</h2>
            <p class="result-count">
                <?= count($facilities) ?>件の避難所が見つかりました。
            </p>
        </div>

        <div class="search-summary">
            <span>出発地点：<?= h($startPoint["name"]) ?></span>
            <span>利用者：<?= h($userType["name"]) ?></span>
            <span><?= $maxMinutes ?>分以内</span>
        </div>
    </div>

    <?php if (empty($facilities)): ?>
        <div class="no-result">
            <p>条件に合う避難所は見つかりませんでした。</p>
            <p>設備条件や所要時間を変更してください。</p>
        </div>
    <?php else: ?>
        <div id="map"></div>

        <div class="facility-list">
            <?php foreach ($facilities as $facility): ?>
                <article class="facility-card">
                    <h3><?= h($facility["facility_name"]) ?></h3>

                    <p>
                        <?= h($facility["municipality"]) ?>
                        <?= h($facility["address"]) ?>
                    </p>

                    <div class="facility-data">
                        <span>
                            約<?= number_format($facility["distance"]) ?>m
                        </span>
                        <span>
                            約<?= h($facility["minutes"]) ?>分
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="notice">
        ※距離は緯度・経度から求めた直線距離です。
        推定所要時間は一般80m/分、高齢者60m/分、
        車いす利用者50m/分として算出した目安です。
    </p>
</section>
<?php endif; ?>

</main>

<?php if ($searched && !empty($facilities)): ?>
<script>
const facilities = <?= json_encode(
    $facilities,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const startLatitude = <?= json_encode($startPoint["lat"]) ?>;
const startLongitude = <?= json_encode($startPoint["lng"]) ?>;
const startName = <?= json_encode(
    $startPoint["name"],
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const map = L.map("map").setView(
    [startLatitude, startLongitude],
    14
);

L.tileLayer(
    "https://osm.gdl.jp/styles/osm-bright-ja/{z}/{x}/{y}.png",
    {
        attribution: "&copy; OpenStreetMap contributors"
    }
).addTo(map);

const markerPositions = [
    [startLatitude, startLongitude]
];

L.circleMarker(
    [startLatitude, startLongitude],
    {radius: 9}
)
.addTo(map)
.bindPopup(
    "<strong>出発地点</strong><br>" +
    escapeHtml(startName)
);

facilities.forEach(function (facility) {
    const equipment = [];

    if (facility.elevator) {
        equipment.push("エレベーター・1階避難スペース");
    }

    if (facility.slope) {
        equipment.push("スロープ");
    }

    if (facility.braille_block) {
        equipment.push("点字ブロック");
    }

    if (facility.wheelchair_toilet) {
        equipment.push("車いす使用者対応トイレ");
    }

    if (facility.other_equipment) {
        equipment.push("その他：" + facility.other_equipment);
    }

    const equipmentText =
        equipment.length > 0
            ? equipment.map(
                item => "・" + escapeHtml(item)
            ).join("<br>")
            : "登録なし";

    const popupContent = `
        <div class="map-popup">
            <h3>${escapeHtml(facility.facility_name)}</h3>
            <p>
                <strong>住所：</strong><br>
                ${escapeHtml(facility.municipality)}
                ${escapeHtml(facility.address)}
            </p>
            <p>
                <strong>距離：</strong>
                約${facility.distance}m
            </p>
            <p>
                <strong>推定所要時間：</strong>
                約${facility.minutes}分
            </p>
            <p>
                <strong>設備：</strong><br>
                ${equipmentText}
            </p>
        </div>
    `;

    L.marker([
        facility.latitude,
        facility.longitude
    ])
    .addTo(map)
    .bindPopup(popupContent);

    markerPositions.push([
        facility.latitude,
        facility.longitude
    ]);
});

map.fitBounds(markerPositions, {
    padding: [30, 30]
});

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}
</script>
<?php endif; ?>

</body>
</html>