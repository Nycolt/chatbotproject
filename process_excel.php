<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

require __DIR__ . "/db.php";
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_FILES['excel']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
  die("❌ No se recibió archivo Excel.");
}

$archivo = $_FILES['excel']['tmp_name'];

// Version: nombre del archivo (limpio)
$sourceVersion = $_FILES['excel']['name'] ?? 'unknown';
$sourceVersion = preg_replace('/\s+/', '_', $sourceVersion);
$sourceVersion = substr($sourceVersion, 0, 50);

$sheetName = "FortiGate";

$reader = IOFactory::createReader('Xlsx');
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($archivo);

if (!in_array($sheetName, $spreadsheet->getSheetNames(), true)) {
  die("❌ La hoja '{$sheetName}' no existe en el Excel.");
}

$sheet = $spreadsheet->getSheetByName($sheetName);
$rows  = $sheet->toArray(null, true, true, true);

echo "<h2>📊 Importación FortiGate</h2>";
echo "<p><b>Hoja:</b> {$sheetName} | <b>Versión:</b> {$sourceVersion}</p><hr>";

/**
 * Detectar fila de encabezados: buscamos UNIT + SKU
 */
$headerRowIndex = null;
$headerMap = []; // TEXTO_ENCABEZADO => LETRA_COLUMNA

foreach ($rows as $i => $r) {
  $a = strtoupper(trim((string)($r['A'] ?? '')));
  $b = strtoupper(trim((string)($r['B'] ?? '')));

  if ($a === 'UNIT' && $b === 'SKU') {
    $headerRowIndex = $i;

    foreach ($r as $colLetter => $cellValue) {
      $h = strtoupper(trim((string)$cellValue));
      if ($h !== '') {
        $headerMap[$h] = $colLetter;
      }
    }
    break;
  }
}

if ($headerRowIndex === null) {
  die("❌ No encontré encabezados (UNIT / SKU).");
}

/**
 * Helper: obtener valor por posible lista de encabezados
 */
$getAny = function(array $row, array $possibleHeaders) use ($headerMap) {
  foreach ($possibleHeaders as $h) {
    $key = strtoupper($h);
    if (isset($headerMap[$key])) {
      $col = $headerMap[$key];
      return trim((string)($row[$col] ?? ''));
    }
  }
  return '';
};

/**
 * Convertir texto a número (precio)
 */
$toDecimal = function($v) {
  $v = trim((string)$v);
  if ($v === '') return null;
  $v = str_replace(['$', ',', ' '], '', $v);
  if (!is_numeric($v)) return null;
  return (float)$v;
};

/**
 * Preparamos statements
 * products:
 *   modelo = UNIT (FortiGate-200G)
 *   sku    = SKU  (FG-200G)
 */
$stmtProduct = $conn->prepare("
  INSERT INTO products (familia, modelo, sku, descripcion_es, segmento)
  VALUES ('FortiGate', ?, ?, ?, NULL)
  ON DUPLICATE KEY UPDATE
    modelo = VALUES(modelo),
    descripcion_es = VALUES(descripcion_es)
");

$stmtFindProductId = $conn->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");

$stmtPrice = $conn->prepare("
  INSERT INTO product_prices (product_id, source_version, term, price_usd, currency)
  VALUES (?, ?, ?, ?, 'USD')
  ON DUPLICATE KEY UPDATE
    price_usd = VALUES(price_usd),
    updated_at = CURRENT_TIMESTAMP
");

$insertedProducts = 0;
$updatedProducts  = 0;
$insertedPrices   = 0;
$updatedPrices    = 0;

foreach ($rows as $i => $r) {
  if ($i <= $headerRowIndex) continue; // saltar hasta después del header

  // Lectura por encabezados reales
  $unit = $getAny($r, ['UNIT']);
  $sku  = $getAny($r, ['SKU']);
  $desc = $getAny($r, ['DESCRIPTION', 'DESCRIPCION', 'DESCRIPCIÓN']);
  $basePrice = $getAny($r, ['PRICE', 'PRECIO']);

  // Contratos (acepta encabezados largos)
  $p1 = $getAny($r, ['1YR CONTRACT (REPLACE DD BY 12)', '1YR CONTRACT', '1Y CONTRACT', '1YR']);
  $p2 = $getAny($r, ['2YR CONTRACT (REPLACE DD BY 24)', '2YR CONTRACT', '2Y CONTRACT', '2YR']);
  $p3 = $getAny($r, ['3YR CONTRACT (REPLACE DD BY 36)', '3YR CONTRACT', '3Y CONTRACT', '3YR']);
  $p4 = $getAny($r, ['4YR CONTRACT (REPLACE DD BY 48)', '4YR CONTRACT', '4Y CONTRACT', '4YR']);
  $p5 = $getAny($r, ['5YR CONTRACT (REPLACE DD BY 60)', '5YR CONTRACT', '5Y CONTRACT', '5YR']);

  // Filtros anti-basura
  if ($unit === '' || $sku === '') continue;
  if (strtoupper($unit) === 'UNIT' || strtoupper($sku) === 'SKU') continue;

  // Evitar filas que NO son producto (ej: Secure RMA, texto raro sin FG-...)
  // Reglas simples: SKU debe parecer FG-xxxx o FGT-...
  if (!preg_match('/^(FG|FGT)\-/i', $sku)) continue;

  // Guardar/actualizar producto
  $stmtProduct->bind_param("sss", $unit, $sku, $desc);
  $stmtProduct->execute();

  if ($stmtProduct->affected_rows === 1) $insertedProducts++;
  else if ($stmtProduct->affected_rows === 2) $updatedProducts++;

  // Buscar id por sku corto (FG-200G)
  $stmtFindProductId->bind_param("s", $sku);
  $stmtFindProductId->execute();
  $result = $stmtFindProductId->get_result();
  $rowId = $result->fetch_assoc();
  $productId = $rowId ? (int)$rowId['id'] : 0;

  if ($productId <= 0) continue;

  $prices = [
    'BASE' => $toDecimal($basePrice),
    '1Y'   => $toDecimal($p1),
    '2Y'   => $toDecimal($p2),
    '3Y'   => $toDecimal($p3),
    '4Y'   => $toDecimal($p4),
    '5Y'   => $toDecimal($p5),
  ];

  foreach ($prices as $term => $priceVal) {
    if ($priceVal === null) continue;

    $stmtPrice->bind_param("issd", $productId, $sourceVersion, $term, $priceVal);
    $stmtPrice->execute();

    if ($stmtPrice->affected_rows === 1) $insertedPrices++;
    else if ($stmtPrice->affected_rows === 2) $updatedPrices++;
  }
}

$stmtProduct->close();
$stmtFindProductId->close();
$stmtPrice->close();

echo "<hr>";
echo "<h3>✅ Importación finalizada</h3>";
echo "<p>📌 Productos insertados: <b>{$insertedProducts}</b> | actualizados: <b>{$updatedProducts}</b></p>";
echo "<p>💲 Precios insertados: <b>{$insertedPrices}</b> | actualizados: <b>{$updatedPrices}</b></p>";
echo "<p>🎯 Listo: FortiGate quedó actualizado con esta versión.</p>";

