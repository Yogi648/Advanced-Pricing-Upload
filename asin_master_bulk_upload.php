<!DOCTYPE html>
<html>
<head>
    <title>ASIN Master - Bulk Upload</title>
</head>
<body>

<h2>ASIN Master → Bulk Upload</h2>

<form method="post" action="asin_master_bulk_process.php" enctype="multipart/form-data">
    <input type="file" name="file" accept=".csv" required>
    <br><br>
    <button type="submit" name="upload">Upload CSV</button>
</form>

<p><b>CSV Format:</b></p>
<pre>
ASIN,SKU,WEIGHT,REFERRAL_FEE
B08TEST01,SKU001,1.5,15
</pre>

<br>
<a href="asin_master.php">⬅ Back to ASIN Master</a>

</body>
</html>
