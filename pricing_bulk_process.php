<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start();

/* =========================
   PERFORMANCE
========================= */

set_time_limit(0);

ini_set('memory_limit', '4096M');

ini_set('max_execution_time', 0);

ini_set('mysql.connect_timeout', 300);

ini_set('default_socket_timeout', 300);

gc_enable();

include "db.php";

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

/* =========================
   PROFIT FUNCTION
========================= */

function calcProfit($p, $ref, $us, $ex, $wt)
{
    if ($us <= 50) {

        $margin = 0.04;

    } elseif ($us <= 250) {

        $margin = 0.05;

    } elseif ($us <= 500) {

        $margin = 0.06;

    } else {

        $margin = 0.08;
    }

    return

        ($p / 1.18)

        - ($ref * $p / 100)

        - ($us * $ex * 1.2)

        - ($wt * 5 * $ex)

        - ($wt * 200)

        - ($p * $margin);
}

/* =========================
   VALIDATION
========================= */

if (
    !isset($_FILES['listing_file']) ||
    !isset($_FILES['ap_file']) ||
    !isset($_FILES['inventory_file'])
) {

    die("Upload all 3 files");
}

/* =========================
   EXCHANGE RATE
========================= */

$exchange_rate = 85;

/* =========================
   LOAD ASIN MASTER
========================= */

$master = [];

$res = $conn->query("
    SELECT asin, weight, referral_fee
    FROM asin_master
");

while ($r = $res->fetch_assoc()) {

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim($r['asin'])
        )
    );

    $master[$asin] = [

        'weight' => (float)$r['weight'],

        'ref' => (float)$r['referral_fee']

    ];
}

/* =========================
   LOAD AP FILE
========================= */

$ap_data = [];

$apFile =
$_FILES['ap_file']['tmp_name'];

$readerAp =
IOFactory::createReaderForFile($apFile);

$readerAp->setReadDataOnly(true);

$spreadsheetAp =
$readerAp->load($apFile);

$sheetAp =
$spreadsheetAp->getActiveSheet();

foreach ($sheetAp->getRowIterator() as $index => $row) {

    if ($index == 1) continue;

    $cellIterator = $row->getCellIterator();

    $cellIterator->setIterateOnlyExistingCells(false);

    $data = [];

    foreach ($cellIterator as $cell) {

        $data[] = $cell->getValue();
    }

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim($data[1] ?? '')
        )
    );

    if ($asin == '') continue;

    $raw_price = trim($data[6] ?? '');

    $raw_price = str_replace(
        ['$', ','],
        '',
        $raw_price
    );

    $us_price = (float)$raw_price;

    $is_prime = strtoupper(
        trim($data[5] ?? '')
    );

    $run_date = trim(
        $data[2] ?? ''
    );

    if ($run_date != '') {

        if (
            strtotime($run_date)
            <
            strtotime("-3 days")
        ) {
            continue;
        }
    }

    $ap_data[$asin] = [

        'us_price' => $us_price,

        'is_prime' => $is_prime

    ];
}

$spreadsheetAp->disconnectWorksheets();

unset($spreadsheetAp);

gc_collect_cycles();

/* =========================
   LOAD INVENTORY FILE
========================= */

$inventory = [];

$inventoryPath =
$_FILES['inventory_file']['tmp_name'];

$readerInv =
IOFactory::createReaderForFile($inventoryPath);

$readerInv->setReadDataOnly(true);

$spreadsheetInv =
$readerInv->load($inventoryPath);

$sheetInv =
$spreadsheetInv->getActiveSheet();

$headerInv = [];

$inv_asin_col = null;
$qty_col = null;

foreach ($sheetInv->getRowIterator() as $index => $row) {

    $cellIterator = $row->getCellIterator();

    $cellIterator->setIterateOnlyExistingCells(false);

    $data = [];

    foreach ($cellIterator as $cell) {

        $data[] = $cell->getValue();
    }

    if ($index == 1) {

        $headerInv = $data;

        foreach ($headerInv as $i => $h) {

            $h = strtolower(trim($h));

            if (strpos($h, 'asin') !== false) {

                $inv_asin_col = $i;
            }

            if (strpos($h, 'qty') !== false) {

                $qty_col = $i;
            }
        }

        continue;
    }

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim(
                $data[$inv_asin_col] ?? ''
            )
        )
    );

    $qty = (int)(
        $data[$qty_col] ?? 0
    );

    if ($asin == '') continue;

    $inventory[$asin] = $qty;
}

$spreadsheetInv->disconnectWorksheets();

unset($spreadsheetInv);

gc_collect_cycles();

/* =========================
   OUTPUT ARRAYS
========================= */

$fba = [];
$mfn_inventory = [];
$remaining_asins = [];
$inactive = [];
$backend = [];
$final = [];

/* =========================
   LOAD LISTING REPORT
========================= */

$listingPath =
$_FILES['listing_file']['tmp_name'];

$readerList =
IOFactory::createReaderForFile($listingPath);

$readerList->setReadDataOnly(true);

$spreadsheetList =
$readerList->load($listingPath);

$sheetList =
$spreadsheetList->getActiveSheet();

$headers = [];

$asin_col = null;
$sku_col = null;
$fulfillment_col = null;
$shipping_col = null;

foreach ($sheetList->getRowIterator() as $index => $row) {

    $cellIterator = $row->getCellIterator();

    $cellIterator->setIterateOnlyExistingCells(false);

    $data = [];

    foreach ($cellIterator as $cell) {

        $data[] = $cell->getValue();
    }

    /* HEADER */

    if ($index == 1) {

        $headers = $data;

        foreach ($headers as $i => $h) {

            $h = strtolower(trim($h));

            $clean = str_replace(
                [" ", "-", "_"],
                '',
                $h
            );

            if (
                strpos($clean, 'asin1') !== false
                ||
                $clean == 'asin'
            ) {

                $asin_col = $i;
            }

            if (
                strpos($clean, 'sellersku') !== false
            ) {

                $sku_col = $i;
            }

            if (
                strpos($clean, 'fulfillmentchannel') !== false
            ) {

                $fulfillment_col = $i;
            }

            if (
                strpos($clean, 'merchantshippinggroup') !== false
            ) {

                $shipping_col = $i;
            }
        }

        continue;
    }

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim(
                $data[$asin_col] ?? ''
            )
        )
    );

    if ($asin == '') continue;

    $sku = trim(
        $data[$sku_col] ?? ''
    );

    $fulfillment = trim(
        $data[$fulfillment_col] ?? ''
    );

    $shipping = trim(
        $data[$shipping_col] ?? ''
    );

    /* FBA */

    $is_fba = false;

    if (
        strtoupper($fulfillment)
        ==
        'AMAZON_IN'
    ) {

        $is_fba = true;

        $fba[] = [

            $asin,
            $sku,
            $fulfillment

        ];
    }

    /* MFN */

    $is_inventory = false;

    $qty = $inventory[$asin] ?? 0;

    $allowed_templates = [

        'Local Shops',
        'Inventory Shipping Template',
        'Fast Moving Inventory Temp'

    ];

    if (
        $qty > 0
        &&
        in_array(
            $shipping,
            $allowed_templates
        )
    ) {

        $is_inventory = true;

        $mfn_inventory[] = [

            $asin,
            $sku,
            $qty,
            $shipping

        ];
    }

    /* REMAINING */

    if (!$is_fba && !$is_inventory) {

        $remaining_asins[] = [

            $asin,
            $sku,
            $shipping

        ];
    }

    /* US PRICE */

    $us_price =
    $ap_data[$asin]['us_price'] ?? 0;

    if ($us_price <= 0) {

        $inactive[] = [

            $asin,
            $sku,
            'US PRICE MISSING'

        ];

        continue;
    }

    /* MASTER */

    if (!isset($master[$asin])) {

        $backend[] = [

            $asin,
            $sku,
            'ASIN NOT FOUND'

        ];

        continue;
    }

    $weight =
    $master[$asin]['weight'];

    $ref =
    $master[$asin]['ref'];

    $weight_valid = $weight > 0;

    $ref_valid = $ref > 0;

    if (!$weight_valid && !$ref_valid) {

        $backend[] = [

            $asin,
            $sku,
            'WEIGHT & REFERRAL MISSING'

        ];

        continue;
    }

    $profit = 0;

    /* BOTH AVAILABLE */

    if ($weight_valid && $ref_valid) {

        $base_cost =

            ($us_price * $exchange_rate * 1.2)

            +

            ($weight * 5 * $exchange_rate)

            +

            ($weight * 200);

        if ($us_price <= 50) {

            $margin = 0.04;

        } elseif ($us_price <= 250) {

            $margin = 0.05;

        } elseif ($us_price <= 500) {

            $margin = 0.06;

        } else {

            $margin = 0.08;
        }

        $price =

            (
                ($base_cost + 200)
                /
                (
                    (1 / 1.18)
                    -
                    ($ref / 100)
                    -
                    $margin
                )
            );

        $price = ceil($price);

        $profit = round(

            calcProfit(
                $price,
                $ref,
                $us_price,
                $exchange_rate,
                $weight
            ),

            2
        );
    }

    /* ONLY WEIGHT */

    elseif ($weight_valid && !$ref_valid) {

        $price =

            ($us_price * $exchange_rate * 2)

            +

            ($weight * 5 * $exchange_rate)

            +

            ($weight * 200)

            +

            3000;
    }

    /* FINAL PRICES */

    $min_price = round($price, 2);

    $sale_price = round(
        $min_price * 1.09,
        2
    );

    $max_price = round(
        $sale_price * 1.20,
        2
    );

    $mrp = round(
        $sale_price * 1.30,
        2
    );

    $b2b = round(
        $sale_price * 0.985,
        2
    );

    $is_prime =
    $ap_data[$asin]['is_prime'] ?? '';

    /* FINAL OUTPUT */

    $final[] = [

        $asin,
        $sku,
        $is_prime,
        round($us_price, 2),
        round($weight, 2),
        round($ref, 2),
        $min_price,
        $sale_price,
        $max_price,
        $mrp,
        $b2b,
        $profit,
        $shipping

    ];
}

$spreadsheetList->disconnectWorksheets();

unset($spreadsheetList);

gc_collect_cycles();

/* =========================
   CREATE EXCEL
========================= */

$spreadsheet = new Spreadsheet();

$spreadsheet->removeSheetByIndex(0);

function addSheet(
    $spreadsheet,
    $title,
    $headers,
    $data
) {

    $title = substr(
        preg_replace(
            '/[\\\\\\/\\?\\*\\[\\]\\:]/',
            '',
            $title
        ),
        0,
        31
    );

    $sheet =
    $spreadsheet->createSheet();

    $sheet->setTitle($title);

    $sheet->fromArray(
        [$headers],
        null,
        'A1'
    );

    if (!empty($data)) {

        $rowNum = 2;

        foreach ($data as $row) {

            $sheet->fromArray(
                [$row],
                null,
                'A' . $rowNum
            );

            $rowNum++;
        }
    }
}

/* SHEETS */

addSheet(
    $spreadsheet,
    'FBA',
    ['ASIN', 'SKU', 'FULFILLMENT'],
    $fba
);

addSheet(
    $spreadsheet,
    'MFN_INVENTORY',
    ['ASIN', 'SKU', 'QTY', 'TEMPLATE'],
    $mfn_inventory
);

addSheet(
    $spreadsheet,
    'REMAINING_ASINS',
    ['ASIN', 'SKU', 'TEMPLATE'],
    $remaining_asins
);

addSheet(
    $spreadsheet,
    'INACTIVE',
    ['ASIN', 'SKU', 'REASON'],
    $inactive
);

addSheet(
    $spreadsheet,
    'BACKEND_DATA',
    ['ASIN', 'SKU', 'REASON'],
    $backend
);

addSheet(
    $spreadsheet,
    'FINAL_UPLOAD',
    [
        'ASIN',
        'SKU',
        'IS_PRIME',
        'US_PRICE',
        'WEIGHT',
        'REFERRAL',
        'MIN_PRICE',
        'SALE_PRICE',
        'MAX_PRICE',
        'MRP',
        'B2B_PRICE',
        'PROFIT',
        'SHIPPING_TEMPLATE'
    ],
    $final
);

/* DOWNLOAD */

$file =
'pricing_output_' .
date('YmdHis') .
'.xlsx';

while (ob_get_level()) {

    ob_end_clean();
}

$tempFile =
sys_get_temp_dir() .
DIRECTORY_SEPARATOR .
$file;

$writer = new Xlsx($spreadsheet);

$writer->save($tempFile);

header_remove();

header('Content-Description: File Transfer');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header(
'Content-Disposition: attachment; filename="' . $file . '"'
);

header('Content-Length: ' . filesize($tempFile));

header('Cache-Control: must-revalidate');

header('Pragma: public');

header('Expires: 0');

readfile($tempFile);

unlink($tempFile);

exit;

?>