<?php
include "db.php";

/* ==========================
   DOWNLOAD ALL DATA
   ========================== */
if (isset($_POST['download_all'])) {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=asin_master_data.csv');

    $output = fopen('php://output', 'w');

    fputcsv($output, ['ID', 'ASIN', 'SKU', 'Weight (Pound)', 'Referral %', 'Created At']);

    $result = $conn->query(
        "SELECT id, asin, sku, weight, referral_fee, created_at
         FROM asin_master
         ORDER BY id DESC"
    );

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/* ==========================
   MESSAGE
========================== */
$msg = "";

/* ==========================
   SAVE ASIN MANUALLY
========================== */
if (isset($_POST['save_asin'])) {

    $asin = trim($_POST['asin']);
    $sku  = trim($_POST['sku']);
    $weight = floatval($_POST['weight']);
    $referral_fee = floatval($_POST['referral_fee']);

    if ($asin === "" || $sku === "") {
        $msg = "❌ ASIN and SKU are required";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO asin_master (asin, sku, weight, referral_fee)
             VALUES (?, ?, ?, ?)"
        );

        try {
            $stmt->bind_param("ssdd", $asin, $sku, $weight, $referral_fee);
            $stmt->execute();
            $msg = "✅ ASIN saved successfully";
        } catch (mysqli_sql_exception $e) {
            $msg = "❌ ASIN + SKU already exists";
        }
    }
}

/* ==========================
   DELETE SINGLE ASIN
========================== */
if (isset($_POST['delete_asin'])) {
    $id = intval($_POST['id']);

    $stmt = $conn->prepare("DELETE FROM asin_master WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $msg = "✅ ASIN deleted successfully";
}

/* ==========================
   CLEAR ALL ASIN MASTER
========================== */
if (isset($_POST['clear_all'])) {

    $ADMIN_PASSWORD = "admin@123";

    if ($_POST['admin_pass'] !== $ADMIN_PASSWORD) {
        $msg = "❌ Invalid admin password";
    } else {
        $conn->query("TRUNCATE TABLE asin_master");
        $msg = "✅ ASIN Master cleared successfully";
    }
}

/* ==========================
   SEARCH LOGIC
========================== */
$search = trim($_GET['search'] ?? "");

if ($search !== "") {
    $stmt = $conn->prepare(
        "SELECT id, asin, sku, weight, referral_fee, created_at
         FROM asin_master
         WHERE asin LIKE ? OR sku LIKE ?
         ORDER BY id DESC"
    );
    $like = "%$search%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $list = $stmt->get_result();
} else {
    $list = $conn->query(
        "SELECT id, asin, sku, weight, referral_fee, created_at
         FROM asin_master
         ORDER BY id DESC
         LIMIT 500"
    );
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>ASIN Master</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
        }
        .box {
            width: 1100px;
            margin: 30px auto;
            background: #fff;
            padding: 20px;
            border-radius: 6px;
        }
        input, button {
            padding: 10px;
            margin-top: 8px;
            width: 100%;
            box-sizing: border-box;
        }
        button {
            background: #007bff;
            color: #fff;
            border: none;
            cursor: pointer;
        }
        .bulk-btn {
            background: #28a745;
            padding: 10px 15px;
            display: inline-block;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
        }
        .download-btn {
            background: #17a2b8;
            padding: 10px 15px;
            border: none;
            color: #fff;
            border-radius: 4px;
            margin-left: 10px;
            cursor: pointer;
        }
        .msg {
            margin: 10px 0;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background: #007bff;
            color: #fff;
        }
        .search-row {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        .danger {
            background: #dc3545;
        }
    </style>
</head>
<body>

<div class="box">
    <h2>ASIN Master</h2>

    <!-- BULK + DOWNLOAD -->
    <a class="bulk-btn" href="asin_master_bulk_upload.php">
        📤 Bulk Upload (CSV)
    </a>

    <form method="post" style="display:inline;">
        <button type="submit" name="download_all" class="download-btn">
            ⬇ Download All Data
        </button>
    </form>

    <hr>

    <!-- MESSAGE -->
    <?php if ($msg) { ?>
        <div class="msg"><?= htmlspecialchars($msg) ?></div>
    <?php } ?>

    <!-- ADD ASIN -->
    <h3>Add ASIN Manually</h3>
    <form method="post">
        <input type="text" name="asin" placeholder="ASIN" required>
        <input type="text" name="sku" placeholder="SKU" required>
        <input type="number" step="0.01" name="weight" placeholder="Weight (Pound)" required>
        <input type="number" step="0.01" name="referral_fee" placeholder="Referral Fee (%)" required>
        <button type="submit" name="save_asin">Save ASIN</button>
    </form>

    <!-- SEARCH -->
    <h3>Search ASIN / SKU</h3>
    <form method="get">
        <div class="search-row">
            <input type="text"
                   name="search"
                   value="<?= htmlspecialchars($search) ?>"
                   placeholder="Enter ASIN or SKU">
            <button type="submit">Search</button>
        </div>
    </form>

    <!-- DANGER ZONE -->
    <hr>
    <h3 style="color:red;">Danger Zone</h3>

    <form method="post"
          onsubmit="return confirm('⚠️ This will DELETE ALL ASIN MASTER DATA. Continue?');">
        <input type="password"
               name="admin_pass"
               placeholder="Admin Password"
               required>
        <button type="submit"
                name="clear_all"
                class="danger">
            🗑 Clear ALL ASIN Master
        </button>
    </form>

    <!-- ASIN LIST -->
    <h3>ASIN List</h3>

    <table>
        <tr>
            <th>ID</th>
            <th>ASIN</th>
            <th>SKU</th>
            <th>Weight (Pound)</th>
            <th>Referral %</th>
            <th>Created At</th>
            <th>Action</th>
        </tr>

        <?php if ($list->num_rows === 0) { ?>
            <tr>
                <td colspan="7">No records found</td>
            </tr>
        <?php } else { ?>
            <?php while ($row = $list->fetch_assoc()) { ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td><?= htmlspecialchars($row['asin']) ?></td>
                    <td><?= htmlspecialchars($row['sku']) ?></td>
                    <td><?= $row['weight'] ?></td>
                    <td><?= $row['referral_fee'] ?></td>
                    <td><?= $row['created_at'] ?></td>
                    <td>
                        <form method="post"
                              onsubmit="return confirm('Delete this ASIN?');">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit"
                                    name="delete_asin"
                                    class="danger">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
        <?php } ?>
    </table>
</div>

</body>
</html>
