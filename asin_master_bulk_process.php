<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');

include "db.php";

if (!isset($_POST['upload'])) {
    exit;
}

$file = $_FILES['file']['tmp_name'];
$handle = fopen($file, "r");

if ($handle === false) {
    die("Unable to open file");
}

// START TRANSACTION (SPEED)
$conn->begin_transaction();

$inserted = 0;
$skipped  = 0;
$row = 0;

$stmt = $conn->prepare(
    "INSERT INTO asin_master (asin, sku, weight, referral_fee)
     VALUES (?, ?, ?, ?)"
);

while (($data = fgetcsv($handle, 2000, ",")) !== false) {

    // Skip header
    if ($row++ === 0) continue;

    $asin = trim($data[0] ?? '');
    $sku  = trim($data[1] ?? '');
    $weight = floatval($data[2] ?? 0);
    $ref   = floatval($data[3] ?? 0);

    if ($asin === '' || $sku === '') {
        $skipped++;
        continue;
    }

    try {
        $stmt->bind_param("ssdd", $asin, $sku, $weight, $ref);
        $stmt->execute();
        $inserted++;
    } catch (mysqli_sql_exception $e) {
        // Duplicate entry → skip
        $skipped++;
    }
}

$conn->commit();   // END TRANSACTION
fclose($handle);

echo "<h3>ASIN Master Bulk Upload Completed</h3>";
echo "Inserted: $inserted<br>";
echo "Skipped (duplicates/invalid): $skipped<br>";
echo "<a href='asin_master.php'>⬅ Back to ASIN Master</a>";
