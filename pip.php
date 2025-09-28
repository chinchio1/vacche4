<?php
require 'vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

function fnum($v, $dec=2) {
    return number_format($v, $dec, ',', '.');
}

$results = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['excel'])) {
    $tmp = $_FILES['excel']['tmp_name'];
    $spreadsheet = IOFactory::load($tmp);
    $sheet = $spreadsheet->getActiveSheet();

    // Qui leggi le celle del tuo file Excel
    // Adatta i riferimenti in base alla struttura del tuo modulo
    $days         = (int)$sheet->getCell('B2')->getValue();
    $milk_sold    = (float)$sheet->getCell('B3')->getValue();
    $milk_not     = (float)$sheet->getCell('B4')->getValue();
    $invoice_eur  = (float)$sheet->getCell('B5')->getValue();
    $cows_in_milk = (int)$sheet->getCell('B6')->getValue();
    $cows_end     = (int)$sheet->getCell('B7')->getValue();

    // Esempio: tabella alimenti da riga 12 in poi
    $row = 12;
    $feedItems = [];
    while ($sheet->getCell("A$row")->getValue() !== null && $row < 200) {
        $type   = strtolower(trim($sheet->getCell("A$row")->getValue()));
        $kgphd  = (float)$sheet->getCell("B$row")->getValue();
        $priceT = (float)$sheet->getCell("C$row")->getValue();
        $dm     = (float)$sheet->getCell("D$row")->getValue();
        if ($dm > 1) $dm = $dm/100;
        $heads  = (int)$sheet->getCell("E$row")->getValue();

        $daily_asfed = $kgphd * $heads;
        $daily_dm    = $daily_asfed * $dm;
        $monthly_asfed = $daily_asfed * $days;
        $monthly_dm    = $daily_dm * $days;
        $monthly_cost  = ($monthly_asfed/1000)*$priceT;

        $feedItems[] = compact('type','kgphd','priceT','dm','heads','daily_asfed','daily_dm','monthly_asfed','monthly_dm','monthly_cost');
        $row++;
    }

    // Calcoli latte
    $milk_total = $milk_sold + $milk_not;
    $price_per_L_sold = $milk_sold>0 ? $invoice_eur/$milk_sold : 0;
    $price_per_L_prod = $milk_total>0 ? $invoice_eur/$milk_total : 0;

    // Totali alimenti
    $monthly_cost_total = array_sum(array_column($feedItems,'monthly_cost'));
    $monthly_dm_total   = array_sum(array_column($feedItems,'monthly_dm'));

    // IOFC
    $iofc_month = $invoice_eur - $monthly_cost_total;
    $iofc_per_cow_month = $cows_in_milk>0 ? $iofc_month/$cows_in_milk : 0;
    $iofc_per_L = $milk_sold>0 ? $iofc_month/$milk_sold : 0;

    $results = compact('days','milk_sold','milk_not','invoice_eur','cows_in_milk','cows_end',
                       'milk_total','price_per_L_sold','price_per_L_prod',
                       'monthly_cost_total','monthly_dm_total',
                       'iofc_month','iofc_per_cow_month','iofc_per_L','feedItems');
}
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="utf-8"><title>Milkminder Excel</title></head>
<body>
<h1>Carica Excel Milkminder</h1>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="excel" accept=".xlsx,.xls">
    <button type="submit">Calcola</button>
</form>

<?php if ($results): ?>
<h2>Risultati</h2>
<ul>
    <li>Totale latte prodotto: <?=fnum($results['milk_total'])?> L</li>
    <li>Prezzo €/L venduto: <?=fnum($results['price_per_L_sold'],4)?></li>
    <li>Totale € alimenti: <?=fnum($results['monthly_cost_total'])?></li>
    <li>Totale kg SS: <?=fnum($results['monthly_dm_total'])?></li>
    <li>IOFC mese: <?=fnum($results['iofc_month'])?></li>
    <li>IOFC/capo/mese: <?=fnum($results['iofc_per_cow_month'])?></li>
    <li>IOFC/L: <?=fnum($results['iofc_per_L'],4)?></li>
</ul>
<?php endif; ?>
</body>
</html>
