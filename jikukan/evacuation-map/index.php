<?php
header("Content-Type: text/html; charset=UTF-8");

error_reporting(E_ALL);
ini_set("display_errors", "1");

require_once __DIR__ . "/../db/connect_db.php";



function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}


function toBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(
        strtolower((string)$value),
        ["1", "t", "true", "yes", "on"],
        true
    );
}


/*
 * 緯度・経度から直線距離を計算
 * 戻り値：メートル
 */
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

    $c = 2 * atan2(
        sqrt($a),
        sqrt(1 - $a)
    );

    return $earthRadius * $c;
}

function makeFacilityData(
    array $facility,
    float $startLatitude,
    float $startLongitude,
    float $speed,
    string $resultType
): array {
    $latitude = (float)$facility["latitude"];
    $longitude = (float)$facility["longitude"];

    $distance = calculateDistance(
        $startLatitude,
        $startLongitude,
        $latitude,
        $longitude
    );

    $minutes = (int)ceil($distance / $speed);

    return [
        "id" => (int)$facility["id"],

        "facility_name" =>
            (string)$facility["facility_name"],

        "municipality" =>
            (string)($facility["municipality"] ?? ""),

        "address" =>
            (string)($facility["address"] ?? ""),

        "latitude" => $latitude,
        "longitude" => $longitude,

        "elevator" =>
            toBool($facility["elevator"] ?? false),

        "slope" =>
            toBool($facility["slope"] ?? false),

        "braille_block" =>
            toBool($facility["braille_block"] ?? false),

        "wheelchair_toilet" =>
            toBool($facility["wheelchair_toilet"] ?? false),

        "other_equipment" =>
            (string)($facility["other_equipment"] ?? ""),

        "distance" => (int)round($distance),
        "minutes" => $minutes,

        /*
         * nearby：現在地周辺
         * selected：選択した市区町村
         */
        "result_type" => $resultType
    ];
}


/*
 * 利用者ごとの移動速度
 * 単位：メートル／分
 */
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


/*
 * 選択できる所要時間
 */
$allowedMinutes = [5, 10, 15, 30, 60];


/*
 * GETパラメータ
 */
$userTypeKey = $_GET["user_type"] ?? "general";
$municipality = trim($_GET["municipality"] ?? "");

$latitudeText = trim($_GET["latitude"] ?? "");
$longitudeText = trim($_GET["longitude"] ?? "");

$maxMinutes = filter_input(
    INPUT_GET,
    "max_minutes",
    FILTER_VALIDATE_INT
);


/*
 * 利用者の種類を検証
 */
if (!isset($userTypes[$userTypeKey])) {
    $userTypeKey = "general";
}


/*
 * 所要時間を検証
 */
if (
    $maxMinutes === false ||
    $maxMinutes === null ||
    !in_array($maxMinutes, $allowedMinutes, true)
) {
    $maxMinutes = 10;
}


/*
 * 現在地の緯度・経度を検証
 */
$currentLatitude = filter_var(
    $latitudeText,
    FILTER_VALIDATE_FLOAT
);

$currentLongitude = filter_var(
    $longitudeText,
    FILTER_VALIDATE_FLOAT
);

$hasCurrentLocation =
    $currentLatitude !== false &&
    $currentLongitude !== false &&
    $currentLatitude >= -90 &&
    $currentLatitude <= 90 &&
    $currentLongitude >= -180 &&
    $currentLongitude <= 180;


/*
 * 利用者情報
 */
$userType = $userTypes[$userTypeKey];
$speed = (float)$userType["speed"];


/*
 * 検索状態
 */
$searchRequested = isset($_GET["search"]);

$searched =
    $searchRequested &&
    $hasCurrentLocation;


/*
 * 初期値
 */
$municipalities = [];

$nearbyFacilities = [];
$selectedFacilities = [];

$errorMessage = "";


/*
 * 現在地を取得せずに検索した場合
 */
if (
    $searchRequested &&
    !$hasCurrentLocation
) {
    $errorMessage =
        "現在地を取得してから検索してください。";
}


try {
    /*
     * 市区町村の選択肢をDBから取得
     */
    $municipalityStmt = $pdo->query("
        SELECT DISTINCT municipality
        FROM evacuation_app.evacuation_centers
        WHERE municipality IS NOT NULL
          AND municipality <> ''
        ORDER BY municipality
    ");

    $municipalities = $municipalityStmt->fetchAll(
        PDO::FETCH_COLUMN
    );


    /*
     * 検索実行
     */
    if ($searched) {
        /*
         * 設備条件を含む共通SQL
         */
        $baseSql = "
            SELECT
                id,
                facility_name,
                municipality,
                address,
                latitude,
                longitude,
                elevator,
                slope,
                braille_block,
                wheelchair_toilet,
                other_equipment
            FROM evacuation_app.evacuation_centers
            WHERE facility_name IS NOT NULL
              AND facility_name <> ''
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
        ";


        /*
         * 設備条件
         */
        if (isset($_GET["elevator"])) {
            $baseSql .= "
                AND elevator = TRUE
            ";
        }

        if (isset($_GET["slope"])) {
            $baseSql .= "
                AND slope = TRUE
            ";
        }

        if (isset($_GET["braille_block"])) {
            $baseSql .= "
                AND braille_block = TRUE
            ";
        }

        if (isset($_GET["wheelchair_toilet"])) {
            $baseSql .= "
                AND wheelchair_toilet = TRUE
            ";
        }


        /*
         * ========================================
         * 1．現在地から指定時間以内の避難所
         * ========================================
         */
        $nearbySql = $baseSql . "
            ORDER BY municipality, facility_name
        ";

        $nearbyStmt = $pdo->prepare($nearbySql);
        $nearbyStmt->execute();

        $nearbyRows = $nearbyStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


        foreach ($nearbyRows as $facility) {
            $facilityData = makeFacilityData(
                $facility,
                (float)$currentLatitude,
                (float)$currentLongitude,
                $speed,
                "nearby"
            );

            /*
             * 指定時間以内のみ追加
             */
            if (
                $facilityData["minutes"] >
                $maxMinutes
            ) {
                continue;
            }

            $nearbyFacilities[] = $facilityData;
        }


        /*
         * 現在地から近い順に並べる
         */
        usort(
            $nearbyFacilities,
            function (array $a, array $b): int {
                return
                    $a["distance"] <=>
                    $b["distance"];
            }
        );


        /*
         * 現在地周辺の施設ID
         * 市区町村側との重複除外に使用
         */
        $nearbyIds = array_column(
            $nearbyFacilities,
            "id"
        );


        /*
         * ========================================
         * 2．選択した市区町村の避難所
         * ========================================
         */
        if ($municipality !== "") {
            $selectedSql = $baseSql . "
                AND municipality = :municipality
                ORDER BY facility_name
            ";

            $selectedStmt = $pdo->prepare(
                $selectedSql
            );

            $selectedStmt->execute([
                "municipality" => $municipality
            ]);

            $selectedRows = $selectedStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


            foreach ($selectedRows as $facility) {
                $facilityId = (int)$facility["id"];

                /*
                 * 現在地周辺ですでに表示されている施設は
                 * 青いピン側には追加しない
                 */
                if (
                    in_array(
                        $facilityId,
                        $nearbyIds,
                        true
                    )
                ) {
                    continue;
                }

                $selectedFacilities[] =
                    makeFacilityData(
                        $facility,
                        (float)$currentLatitude,
                        (float)$currentLongitude,
                        $speed,
                        "selected"
                    );
            }


            /*
             * 現在地から近い順に並べる
             */
            usort(
                $selectedFacilities,
                function (
                    array $a,
                    array $b
                ): int {
                    return
                        $a["distance"] <=>
                        $b["distance"];
                }
            );
        }
    }
} catch (PDOException $e) {
    $errorMessage =
        "データベースから避難所情報を取得できませんでした。";

    error_log($e->getMessage());
}


/*
 * 地図を表示するか
 */
$hasMapResults =
    !empty($nearbyFacilities) ||
    !empty($selectedFacilities);


/*
 * 2種類を合わせた件数
 */
$totalFacilityCount =
    count($nearbyFacilities) +
    count($selectedFacilities);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        東京都バリアフリー避難所検索マップ
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    ></script>
</head>

<body>

<header class="site-header">
    <div class="header-inner">
        <h1>
            東京都バリアフリー避難所検索マップ
        </h1>

        <p>
            現在地周辺の避難所と、
            選択した市区町村の避難所を
            同じ地図に表示します。
        </p>
    </div>
</header>


<main class="container">

<section class="search-card">

    <form
        method="GET"
        action="index.php"
        id="search-form"
    >

        <!-- 現在地 -->
        <div class="form-section">
            <h2>1．現在地</h2>

            <button
                type="button"
                id="location-button"
                class="location-button"
            >
                現在地を取得
            </button>

            <p id="location-status">
                <?php if ($hasCurrentLocation): ?>
                    現在地を取得済みです。
                <?php else: ?>
                    現在地はまだ取得されていません。
                <?php endif; ?>
            </p>

            <input
                type="hidden"
                name="latitude"
                id="latitude"
                value="<?= $hasCurrentLocation
                    ? h($currentLatitude)
                    : "" ?>"
            >

            <input
                type="hidden"
                name="longitude"
                id="longitude"
                value="<?= $hasCurrentLocation
                    ? h($currentLongitude)
                    : "" ?>"
            >
        </div>


        <!-- 利用者の種類 -->
        <div class="form-section">
            <h2>2．利用者の種類</h2>

            <?php foreach ($userTypes as $key => $type): ?>

                <label class="radio-label">
                    <input
                        type="radio"
                        name="user_type"
                        value="<?= h($key) ?>"
                        <?= $userTypeKey === $key
                            ? "checked"
                            : "" ?>
                    >

                    <?= h($type["name"]) ?>
                </label>

            <?php endforeach; ?>
        </div>


        <!-- 市区町村 -->
        <div class="form-section">
            <h2>3．表示する市区町村</h2>

            <select name="municipality">
                <option value="">
                    選択しない
                </option>

                <?php foreach ($municipalities as $name): ?>

                    <option
                        value="<?= h($name) ?>"
                        <?= $municipality === $name
                            ? "selected"
                            : "" ?>
                    >
                        <?= h($name) ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <p class="form-help">
                現在地周辺とは別に、選択した市区町村の
                避難所を青いピンで表示します。
            </p>
        </div>


        <!-- 設備条件 -->
        <div class="form-section">
            <h2>4．必要な設備</h2>

            <div class="checkbox-list">

                <label>
                    <input
                        type="checkbox"
                        name="elevator"
                        value="1"
                        <?= isset($_GET["elevator"])
                            ? "checked"
                            : "" ?>
                    >

                    エレベーター・1階避難スペース
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="slope"
                        value="1"
                        <?= isset($_GET["slope"])
                            ? "checked"
                            : "" ?>
                    >

                    スロープ
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="braille_block"
                        value="1"
                        <?= isset($_GET["braille_block"])
                            ? "checked"
                            : "" ?>
                    >

                    点字ブロック
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="wheelchair_toilet"
                        value="1"
                        <?= isset($_GET["wheelchair_toilet"])
                            ? "checked"
                            : "" ?>
                    >

                    車いす使用者対応トイレ
                </label>

            </div>
        </div>


        <!-- 現在地周辺の所要時間 -->
        <div class="form-section">
            <h2>5．現在地周辺の希望所要時間</h2>

            <select name="max_minutes">

                <?php foreach ($allowedMinutes as $minute): ?>

                    <option
                        value="<?= $minute ?>"
                        <?= $maxMinutes === $minute
                            ? "selected"
                            : "" ?>
                    >
                        <?= $minute ?>分以内
                    </option>

                <?php endforeach; ?>

            </select>

            <p class="form-help">
                この時間条件は赤いピンの
                「現在地周辺」に適用されます。
            </p>
        </div>


        <!-- 検索ボタン -->
        <button
            class="search-button"
            id="search-button"
            type="submit"
            name="search"
            value="1"
            <?= !$hasCurrentLocation
                ? "disabled"
                : "" ?>
        >
            避難所を検索して地図に表示
        </button>

    </form>

</section>


<?php if ($errorMessage !== ""): ?>

    <p class="error-message">
        <?= h($errorMessage) ?>
    </p>

<?php endif; ?>


<?php if ($searched && $errorMessage === ""): ?>

<section class="result-section">

    <div class="result-header">

        <div>
            <h2>検索結果</h2>

            <p class="result-count">
                合計<?= $totalFacilityCount ?>件を表示します。
            </p>
        </div>

        <div class="search-summary">
            <span>
                利用者：<?= h($userType["name"]) ?>
            </span>

            <span>
                現在地から<?= h($maxMinutes) ?>分以内
            </span>

            <?php if ($municipality !== ""): ?>
                <span>
                    選択地域：<?= h($municipality) ?>
                </span>
            <?php endif; ?>
        </div>

    </div>


    <div class="result-type-summary">

        <div class="result-type-card nearby-result">
            <span class="result-color-dot red-dot"></span>

            <div>
                <strong>
                    現在地周辺
                </strong>

                <p>
                    <?= count($nearbyFacilities) ?>件
                </p>
            </div>
        </div>


        <?php if ($municipality !== ""): ?>

            <div class="result-type-card selected-result">
                <span class="result-color-dot blue-dot"></span>

                <div>
                    <strong>
                        <?= h($municipality) ?>
                    </strong>

                    <p>
                        <?= count($selectedFacilities) ?>件
                    </p>
                </div>
            </div>

        <?php endif; ?>

    </div>


    <?php if (!$hasMapResults): ?>

        <div class="no-result">
            <p>
                条件に合う避難所は見つかりませんでした。
            </p>

            <p>
                設備条件や希望所要時間を変更してください。
            </p>
        </div>

    <?php else: ?>

        <div id="map"></div>


        <!-- 現在地周辺一覧 -->
        <?php if (!empty($nearbyFacilities)): ?>

            <section class="facility-group">

                <h3 class="facility-group-title">
                    <span class="result-color-dot red-dot"></span>
                    現在地から<?= h($maxMinutes) ?>分以内
                </h3>

                <div class="facility-list">

                    <?php foreach (
                        $nearbyFacilities as $facility
                    ): ?>

                        <article class="facility-card">

                            <span class="facility-category nearby-label">
                                現在地周辺
                            </span>

                            <h3>
                                <?= h(
                                    $facility["facility_name"]
                                ) ?>
                            </h3>

                            <p>
                                <?= h(
                                    $facility["municipality"]
                                ) ?>
                                <?= h(
                                    $facility["address"]
                                ) ?>
                            </p>

                            <div class="facility-data">
                                <span>
                                    約<?= number_format(
                                        $facility["distance"]
                                    ) ?>m
                                </span>

                                <span>
                                    約<?= h(
                                        $facility["minutes"]
                                    ) ?>分
                                </span>
                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endif; ?>


        <!-- 選択した市区町村一覧 -->
        <?php if (!empty($selectedFacilities)): ?>

            <section class="facility-group">

                <h3 class="facility-group-title">
                    <span class="result-color-dot blue-dot"></span>
                    <?= h($municipality) ?>の避難所
                </h3>

                <div class="facility-list">

                    <?php foreach (
                        $selectedFacilities as $facility
                    ): ?>

                        <article class="facility-card">

                            <span class="facility-category selected-label">
                                選択した市区町村
                            </span>

                            <h3>
                                <?= h(
                                    $facility["facility_name"]
                                ) ?>
                            </h3>

                            <p>
                                <?= h(
                                    $facility["municipality"]
                                ) ?>
                                <?= h(
                                    $facility["address"]
                                ) ?>
                            </p>

                            <div class="facility-data">
                                <span>
                                    現在地から約<?= number_format(
                                        $facility["distance"]
                                    ) ?>m
                                </span>

                                <span>
                                    約<?= h(
                                        $facility["minutes"]
                                    ) ?>分
                                </span>
                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endif; ?>

    <?php endif; ?>


    <p class="notice">
        ※距離は現在地と避難所の緯度・経度から求めた
        直線距離です。推定所要時間は一般80m/分、
        高齢者60m/分、車いす利用者50m/分として
        算出した目安です。
    </p>

</section>

<?php endif; ?>

</main>


<!-- 現在地取得 -->
<script>
const locationButton =
    document.getElementById("location-button");

const locationStatus =
    document.getElementById("location-status");

const latitudeInput =
    document.getElementById("latitude");

const longitudeInput =
    document.getElementById("longitude");

const searchButton =
    document.getElementById("search-button");


locationButton.addEventListener(
    "click",
    function () {
        if (!navigator.geolocation) {
            locationStatus.textContent =
                "このブラウザでは位置情報を取得できません。";

            return;
        }

        locationButton.disabled = true;
        searchButton.disabled = true;

        locationStatus.textContent =
            "現在地を取得しています...";


        navigator.geolocation.getCurrentPosition(

            /*
             * 取得成功
             */
            function (position) {
                const latitude =
                    position.coords.latitude;

                const longitude =
                    position.coords.longitude;

                const accuracy =
                    position.coords.accuracy;


                latitudeInput.value = latitude;
                longitudeInput.value = longitude;


                locationStatus.textContent =
                    "現在地を取得しました。" +
                    " 誤差：約" +
                    Math.round(accuracy) +
                    "m";


                locationButton.disabled = false;
                searchButton.disabled = false;
            },


            /*
             * 取得失敗
             */
            function (error) {
                let message =
                    "現在地を取得できませんでした。";

                if (error.code === 1) {
                    message =
                        "位置情報の使用が許可されていません。";
                } else if (error.code === 2) {
                    message =
                        "現在地を特定できませんでした。";
                } else if (error.code === 3) {
                    message =
                        "位置情報の取得がタイムアウトしました。";
                }

                locationStatus.textContent = message;

                locationButton.disabled = false;
                searchButton.disabled = true;
            },


            /*
             * 高精度設定
             */
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    }
);
</script>


<?php if ($searched && $hasMapResults): ?>

<script>
const nearbyFacilities = <?= json_encode(
    $nearbyFacilities,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const selectedFacilities = <?= json_encode(
    $selectedFacilities,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const startLatitude =
    <?= json_encode((float)$currentLatitude) ?>;

const startLongitude =
    <?= json_encode((float)$currentLongitude) ?>;

const selectedMunicipality =
    <?= json_encode(
        $municipality,
        JSON_UNESCAPED_UNICODE |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;


/*
 * 地図を作成
 */
const map = L.map("map").setView(
    [startLatitude, startLongitude],
    14
);


/*
 * 地図タイル
 */
L.tileLayer(
    "https://osm.gdl.jp/styles/osm-bright-ja/{z}/{x}/{y}.png",
    {
        attribution:
            "&copy; OpenStreetMap contributors"
    }
).addTo(map);


/*
 * 赤いマーカー
 * 現在地周辺
 */
const redIcon = new L.Icon({
    iconUrl:
        "https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png",

    shadowUrl:
        "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",

    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
});


/*
 * 青いマーカー
 * 選択した市区町村
 */
const blueIcon = new L.Icon({
    iconUrl:
        "https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png",

    shadowUrl:
        "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",

    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
});


/*
 * 地図の表示範囲を決めるための座標
 */
const markerPositions = [
    [startLatitude, startLongitude]
];


/*
 * 現在地
 */
L.circleMarker(
    [startLatitude, startLongitude],
    {
        radius: 10,
        color: "#166534",
        fillColor: "#22c55e",
        fillOpacity: 0.9,
        weight: 3
    }
)
.addTo(map)
.bindPopup(
    "<strong>現在地</strong>"
);


/*
 * 現在地周辺の避難所
 */
nearbyFacilities.forEach(
    function (facility) {
        const popupContent =
            createPopupContent(
                facility,
                "現在地周辺"
            );

        L.marker(
            [
                facility.latitude,
                facility.longitude
            ],
            {
                icon: redIcon
            }
        )
        .addTo(map)
        .bindPopup(popupContent);

        markerPositions.push([
            facility.latitude,
            facility.longitude
        ]);
    }
);


/*
 * 選択した市区町村の避難所
 */
selectedFacilities.forEach(
    function (facility) {
        const popupContent =
            createPopupContent(
                facility,
                selectedMunicipality
            );

        L.marker(
            [
                facility.latitude,
                facility.longitude
            ],
            {
                icon: blueIcon
            }
        )
        .addTo(map)
        .bindPopup(popupContent);

        markerPositions.push([
            facility.latitude,
            facility.longitude
        ]);
    }
);


/*
 * マーカー全体が見えるように調整
 */
if (markerPositions.length > 1) {
    map.fitBounds(
        markerPositions,
        {
            padding: [35, 35],
            maxZoom: 16
        }
    );
}


/*
 * 凡例
 */
const legend = L.control({
    position: "bottomright"
});

legend.onAdd = function () {
    const div = L.DomUtil.create(
        "div",
        "map-legend"
    );

    div.innerHTML = `
        <div class="legend-row">
            <span class="legend-circle current-circle"></span>
            現在地
        </div>

        <div class="legend-row">
            <span class="legend-circle nearby-circle"></span>
            現在地周辺
        </div>

        ${
            selectedMunicipality !== ""
                ? `
                    <div class="legend-row">
                        <span class="legend-circle selected-circle"></span>
                        ${escapeHtml(selectedMunicipality)}
                    </div>
                `
                : ""
        }
    `;

    return div;
};

legend.addTo(map);


/*
 * ポップアップ作成
 */
function createPopupContent(
    facility,
    category
) {
    const equipment = [];

    if (facility.elevator) {
        equipment.push(
            "エレベーター・1階避難スペース"
        );
    }

    if (facility.slope) {
        equipment.push("スロープ");
    }

    if (facility.braille_block) {
        equipment.push("点字ブロック");
    }

    if (facility.wheelchair_toilet) {
        equipment.push(
            "車いす使用者対応トイレ"
        );
    }

    if (facility.other_equipment) {
        equipment.push(
            "その他：" +
            facility.other_equipment
        );
    }


    const equipmentText =
        equipment.length > 0
            ? equipment.map(
                function (item) {
                    return (
                        "・" +
                        escapeHtml(item)
                    );
                }
            ).join("<br>")
            : "登録なし";


    return `
        <div class="map-popup">

            <span class="popup-category">
                ${escapeHtml(category)}
            </span>

            <h3>
                ${escapeHtml(
                    facility.facility_name
                )}
            </h3>

            <p>
                <strong>住所：</strong><br>
                ${escapeHtml(
                    facility.municipality
                )}
                ${escapeHtml(
                    facility.address
                )}
            </p>

            <p>
                <strong>現在地からの距離：</strong>
                約${facility.distance.toLocaleString()}m
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
}


/*
 * HTMLエスケープ
 */
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