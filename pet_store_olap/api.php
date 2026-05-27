<?php
// ── api.php — Endpoint OLAP para Pet Store ──
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once "db.php";

$q = isset($_GET['q']) ? $_GET['q'] : '';

// ── Helper: ejecutar query y devolver JSON ──
function run($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) {
        echo json_encode(["error" => $conn->error]);
        exit;
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode($rows);
}

// ── Ignorar fila basura con product_category = 'product_category' ──
$base_filter = "WHERE product_category != 'product_category' AND product_category IS NOT NULL";

switch ($q) {

    // ── Q1: Roll-Up — Ventas por categoría ──────────────────
    case 'q1':
        run($conn, "
            SELECT
                product_category                          AS categoria,
                COUNT(*)                                  AS registros,
                SUM(sales)                                AS ventas_totales,
                ROUND(AVG(price), 2)                      AS precio_prom,
                ROUND(AVG(rating), 2)                     AS rating_prom,
                ROUND(SUM(sales) * 100.0 /
                    (SELECT SUM(sales) FROM pet_store_records
                     WHERE product_category != 'product_category'), 1) AS pct_ventas
            FROM pet_store_records
            $base_filter
            GROUP BY product_category
            ORDER BY ventas_totales DESC
        ");
        break;

    // ── Q2: Drill-Down — Por país ────────────────────────────
    case 'q2':
        run($conn, "
            SELECT
                country                                   AS pais,
                COUNT(*)                                  AS registros,
                SUM(sales)                                AS ventas_total,
                ROUND(AVG(price), 2)                      AS precio_prom,
                ROUND(AVG(rating), 2)                     AS rating_prom,
                ROUND(AVG(re_buy) * 100, 1)               AS tasa_recompra,
                ROUND(SUM(sales) * 100.0 /
                    (SELECT SUM(sales) FROM pet_store_records
                     WHERE product_category != 'product_category'), 1) AS pct_ventas
            FROM pet_store_records
            $base_filter
            GROUP BY country
            ORDER BY ventas_total DESC
        ");
        break;

    // ── Q3: Slice+Dice — pet_type × category ────────────────
    case 'q3':
        run($conn, "
            SELECT
                pet_type,
                product_category,
                SUM(sales) AS ventas
            FROM pet_store_records
            $base_filter
            GROUP BY pet_type, product_category
            ORDER BY pet_type, product_category
        ");
        break;

    // ── Q4: Pivot — Rating & Recompra por categoría ──────────
    case 'q4':
        run($conn, "
            SELECT
                product_category                          AS categoria,
                COUNT(*)                                  AS registros,
                ROUND(AVG(rating), 2)                     AS rating_prom,
                ROUND(AVG(re_buy) * 100, 1)               AS tasa_recompra
            FROM pet_store_records
            $base_filter
            GROUP BY product_category
            ORDER BY rating_prom DESC
        ");
        break;

    // ── Q5: Dice — pet_size × country ────────────────────────
    case 'q5':
        run($conn, "
            SELECT
                pet_size,
                country,
                SUM(sales) AS ventas
            FROM pet_store_records
            $base_filter
            GROUP BY pet_size, country
            ORDER BY pet_size, country
        ");
        break;

    // ── Q6: Consulta dinámica en tiempo real ─────────────────
    case 'q6':
        $dim     = isset($_GET['dim'])     ? $_GET['dim']     : 'product_category';
        $metric  = isset($_GET['metric'])  ? $_GET['metric']  : 'sales_sum';
        $f_cat   = isset($_GET['f_cat'])   ? $_GET['f_cat']   : '';
        $f_country = isset($_GET['f_country']) ? $_GET['f_country'] : '';
        $f_pet   = isset($_GET['f_pet'])   ? $_GET['f_pet']   : '';
        $f_size  = isset($_GET['f_size'])  ? $_GET['f_size']  : '';
        $f_vap   = isset($_GET['f_vap'])   ? $_GET['f_vap']   : '';

        // Whitelist de dimensiones y métricas
        $valid_dims = ['product_category','country','pet_type','pet_size'];
        $valid_metrics = [
            'sales_sum'  => 'SUM(sales)',
            'sales_avg'  => 'ROUND(AVG(sales),2)',
            'price_avg'  => 'ROUND(AVG(price),2)',
            'rating_avg' => 'ROUND(AVG(rating),2)',
            'rebuy_pct'  => 'ROUND(AVG(re_buy)*100,1)',
            'count'      => 'COUNT(*)'
        ];

        if (!in_array($dim, $valid_dims)) $dim = 'product_category';
        if (!array_key_exists($metric, $valid_metrics)) $metric = 'sales_sum';

        $metric_expr = $valid_metrics[$metric];

        // Filtros dinámicos
        $filters = ["product_category != 'product_category'", "product_category IS NOT NULL"];

        if ($f_cat)     $filters[] = "product_category = '" . $conn->real_escape_string($f_cat) . "'";
        if ($f_country) $filters[] = "country = '"          . $conn->real_escape_string($f_country) . "'";
        if ($f_pet)     $filters[] = "pet_type = '"         . $conn->real_escape_string($f_pet) . "'";
        if ($f_size)    $filters[] = "pet_size = '"         . $conn->real_escape_string($f_size) . "'";
        if ($f_vap !== '') $filters[] = "VAP = " . (int)$f_vap;

        $where = "WHERE " . implode(" AND ", $filters);

        run($conn, "
            SELECT
                `$dim`         AS dimension,
                $metric_expr   AS valor,
                COUNT(*)       AS registros
            FROM pet_store_records
            $where
            GROUP BY `$dim`
            ORDER BY valor DESC
        ");
        break;

    default:
        echo json_encode(["error" => "Consulta no reconocida. Usa: q1, q2, q3, q4, q5, q6"]);
        break;
}

$conn->close();
?>
