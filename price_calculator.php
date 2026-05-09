// pricing_bulk_process.php

<?php

error_reporting(0);
ini_set('display_errors', 0);

ob_start();

set_time_limit(0);
ini_set('memory_limit', '4096M');

include "db.php";

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

/* =========================
   PROFIT FUNCTION
========================= */

function calcProfit($p, $ref, $us, $ex, $wt) {

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

while($r = $res->fetch_assoc()){

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

$spreadsheetAp =
IOFactory::load($apFile);

$sheetAp =
$spreadsheetAp->getActiveSheet();

$rowsAp =
$sheetAp->toArray();

/* REMOVE HEADER */

array_shift($rowsAp);

/* =========================
   AP FILE DATA
========================= */

foreach($rowsAp as $row){

    /* ASIN */

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim($row[1] ?? '')
        )
    );

    if($asin == '') continue;

    /* =========================
       US PRICE
       new_price column
    ========================= */

    $raw_price = trim(
        $row[6] ?? ''
    );

    $raw_price = str_replace(
        ['$', ','],
        '',
        $raw_price
    );

    $us_price = (float)$raw_price;

    /* PRIME */

    $is_prime = strtoupper(
        trim($row[5] ?? '')
    );

    /* DATE */

    $run_date = trim(
        $row[2] ?? ''
    );

    /* LAST 3 DAYS */

    if($run_date != ''){

        if(
            strtotime($run_date)
            <
            strtotime("-3 days")
        ){
            continue;
        }
    }

    /* STORE */

    $ap_data[$asin] = [

        'us_price' => $us_price,

        'is_prime' => $is_prime

    ];
}

/* =========================
   LOAD INVENTORY FILE
========================= */

$inventory = [];

$inventoryPath =
$_FILES['inventory_file']['tmp_name'];

$spreadsheetInv =
IOFactory::load($inventoryPath);

$sheetInv =
$spreadsheetInv->getActiveSheet();

$rowsInv =
$sheetInv->toArray();

/* HEADERS */

$headerInv =
array_shift($rowsInv);

/* DETECT COLUMNS */

$inv_asin_col = null;
$qty_col = null;

foreach($headerInv as $i => $h){

    $h = strtolower(trim($h));

    if(strpos($h,'asin') !== false){

        $inv_asin_col = $i;
    }

    if(strpos($h,'qty') !== false){

        $qty_col = $i;
    }
}

if($inv_asin_col === null){

    die("Inventory ASIN column not found");
}

if($qty_col === null){

    die("Inventory Qty column not found");
}

/* STORE INVENTORY */

foreach($rowsInv as $row){

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim(
                $row[$inv_asin_col] ?? ''
            )
        )
    );

    $qty = (int)(
        $row[$qty_col] ?? 0
    );

    if($asin == '') continue;

    $inventory[$asin] = $qty;
}

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

$spreadsheetList =
IOFactory::load($listingPath);

$sheetList =
$spreadsheetList->getActiveSheet();

$rowsList =
$sheetList->toArray();

/* HEADERS */

$headers =
array_shift($rowsList);

/* DETECT */

$asin_col = null;
$sku_col = null;
$fulfillment_col = null;
$shipping_col = null;

foreach($headers as $i => $h){

    $h = strtolower(trim($h));

    $clean = str_replace(
        [" ","-","_"],
        '',
        $h
    );

    if(
        strpos($clean,'asin1') !== false
        ||
        $clean == 'asin'
    ){

        $asin_col = $i;
    }

    if(
        strpos($clean,'sellersku') !== false
    ){

        $sku_col = $i;
    }

    if(
        strpos($clean,'fulfillmentchannel') !== false
    ){

        $fulfillment_col = $i;
    }

    if(
        strpos($clean,'merchantshippinggroup') !== false
    ){

        $shipping_col = $i;
    }
}

if($asin_col === null){

    die("Listing ASIN column not found");
}

/* =========================
   PROCESS LISTINGS
========================= */

foreach($rowsList as $row){

    $asin = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim(
                $row[$asin_col] ?? ''
            )
        )
    );

    if($asin == '') continue;

    $sku = trim(
        $row[$sku_col] ?? ''
    );

    $fulfillment = trim(
        $row[$fulfillment_col] ?? ''
    );

    $shipping = trim(
        $row[$shipping_col] ?? ''
    );

    /* =========================
       FBA
    ========================= */

    $is_fba = false;

    if(
        strtoupper($fulfillment)
        ==
        'AMAZON_IN'
    ){

        $is_fba = true;

        $fba[] = [

            $asin,
            $sku,
            $fulfillment

        ];
    }

    /* =========================
       MFN INVENTORY
    ========================= */

    $is_inventory = false;

    $qty = $inventory[$asin] ?? 0;

    $allowed_templates = [

        'Local Shops',
        'Inventory Shipping Template',
        'Fast Moving Inventory Temp'

    ];

    if(
        $qty > 0
        &&
        in_array(
            $shipping,
            $allowed_templates
        )
    ){

        $is_inventory = true;

        $mfn_inventory[] = [

            $asin,
            $sku,
            $qty,
            $shipping

        ];
    }

    /* =========================
       REMAINING
    ========================= */

    if(!$is_fba && !$is_inventory){

        $remaining_asins[] = [

            $asin,
            $sku,
            $shipping

        ];
    }

    /* =========================
       US PRICE FROM AP FILE
    ========================= */

    $us_price =
    $ap_data[$asin]['us_price'] ?? 0;

    if($us_price <= 0){

        $inactive[] = [

            $asin,
            $sku,
            'US PRICE MISSING'

        ];

        continue;
    }

    /* =========================
       WEIGHT + REFERRAL
       FROM asin_master
    ========================= */

    if(!isset($master[$asin])){

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

    if($weight <= 0){

        $backend[] = [

            $asin,
            $sku,
            'WEIGHT MISSING'

        ];

        continue;
    }

    /* =========================
       BASE PRICE
    ========================= */

    $price =

    ($us_price * $exchange_rate * 2)

    +

    ($weight * 5 * $exchange_rate)

    +

    ($weight * 200);

    /* DEFAULTS */

    $profit = '';
    $min_price = '';
    $sale_price = '';
    $max_price = '';
    $mrp = '';
    $b2b = '';

    /* =========================
       FULL FORMULA
    ========================= */

    if($ref > 0){

        while (
            calcProfit(
                $price,
                $ref,
                $us_price,
                $exchange_rate,
                $weight
            ) < 200
        ) {

            $price += 10;
        }

        while (
            calcProfit(
                $price,
                $ref,
                $us_price,
                $exchange_rate,
                $weight
            ) > 200
        ) {

            $price -= 1;
        }

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

        $min_price = round($price,2);

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
    }

    else{

        $sale_price = round($price,2);
    }

    /* PRIME */

    $is_prime =
    $ap_data[$asin]['is_prime'] ?? '';

    /* =========================
       FINAL OUTPUT
    ========================= */

    $final[] = [

        $asin,
        $sku,
        $is_prime,
        round($us_price,2),
        round($weight,2),
        round($ref,2),
        $min_price,
        $sale_price,
        $max_price,
        $mrp,
        $b2b,
        $profit,
        $shipping

    ];
}

/* =========================
   CREATE EXCEL
========================= */

$spreadsheet = new Spreadsheet();

$spreadsheet->removeSheetByIndex(0);

/* =========================
   SHEET FUNCTION
========================= */

function addSheet(
    $spreadsheet,
    $title,
    $headers,
    $data
){

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

    if(!empty($data)){

        $rowNum = 2;

        foreach($data as $row){

            $cleanRow = [];

            foreach($row as $v){

                $cleanRow[] = (string)$v;
            }

            $sheet->fromArray(
                [$cleanRow],
                null,
                'A'.$rowNum
            );

            $rowNum++;
        }
    }
}

/* =========================
   SHEETS
========================= */

addSheet(
    $spreadsheet,
    'FBA',
    ['ASIN','SKU','FULFILLMENT'],
    $fba
);

addSheet(
    $spreadsheet,
    'MFN_INVENTORY',
    ['ASIN','SKU','QTY','TEMPLATE'],
    $mfn_inventory
);

addSheet(
    $spreadsheet,
    'REMAINING_ASINS',
    ['ASIN','SKU','TEMPLATE'],
    $remaining_asins
);

addSheet(
    $spreadsheet,
    'INACTIVE',
    ['ASIN','SKU','REASON'],
    $inactive
);

addSheet(
    $spreadsheet,
    'BACKEND_DATA',
    ['ASIN','SKU','REASON'],
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

/* =========================
   DOWNLOAD
========================= */

$file =
'pricing_output_' .
date('YmdHis') .
'.xlsx';

while(ob_get_level()){

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
'Content-Disposition: attachment; filename="'.$file.'"'
);

header('Content-Length: ' . filesize($tempFile));

header('Cache-Control: must-revalidate');

header('Pragma: public');

header('Expires: 0');

readfile($tempFile);

unlink($tempFile);

exit;

?>