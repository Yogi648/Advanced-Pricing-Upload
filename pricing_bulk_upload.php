<?php
include "db.php";
?>

<!DOCTYPE html>
<html>
<head>

<title>Advanced Pricing Upload</title>

<style>

body{
    font-family:Arial;
    background:#f4f6f9;
}

.box{
    width:700px;
    margin:40px auto;
    background:#fff;
    padding:30px;
    border-radius:10px;
}

input,button{
    width:100%;
    padding:12px;
    margin-top:10px;
    box-sizing:border-box;
}

button{
    background:#007bff;
    color:#fff;
    border:none;
    cursor:pointer;
    font-size:16px;
}

</style>

</head>
<body>

<div class="box">

<h2>Advanced Pricing Upload</h2>

<form method="post"
      enctype="multipart/form-data"
      action="pricing_bulk_process.php">

    <label>1. Listing Report</label>

    <input type="file"
           name="listing_file"
           accept=".xlsx,.xls,.csv,.txt"
           required>

    <br><br>

    <label>2. Uploads Output AP File</label>

    <input type="file"
           name="ap_file"
           accept=".xlsx,.xls,.csv"
           required>

    <br><br>

    <label>3. Warehouse Inventory File</label>

    <input type="file"
           name="inventory_file"
           accept=".xlsx,.xls,.csv"
           required>

    <br><br>

    <button type="submit">
        PROCESS PRICING
    </button>

</form>

</div>

</body>
</html>