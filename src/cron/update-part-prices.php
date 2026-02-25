<?php
use Atte\DB\MsaDB;
use Atte\Api\GoogleSheets;
use Atte\Utils\Bom\PriceCalculator;

set_time_limit(0);

$MsaDB = MsaDB::getInstance();
$sheets = new GoogleSheets();
$calculator = new PriceCalculator($MsaDB);

$spreadsheetId = '1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew';
$range = 'ceny_tmp!A:G';

echo "Pobieranie danych z arkuszy Google...
";
$values = $sheets->readSheet($spreadsheetId, 'ceny_tmp', 'A:G');

if (!$values || count($values) < 2) {
    echo "Nie znaleziono danych lub wystąpił błąd podczas odczytu arkusza.
";
    exit(1);
}

// Map column headers
$header = array_shift($values);
$partNoIdx = array_search('PartNo', $header);
$priceIdx = array_search('BuyPrice_PLN', $header);

if ($partNoIdx === false || $priceIdx === false) {
    echo "Nie znaleziono wymaganych kolumn (PartNo, BuyPrice_PLN).
";
    exit(1);
}

// Get current prices from DB to compare
$currentParts = $MsaDB->query("SELECT id, name, price FROM list__parts", PDO::FETCH_ASSOC);
$dbParts = [];
foreach ($currentParts as $p) {
    $dbParts[$p['name']] = $p;
}

$updatedCount = 0;
$recalculatedCount = 0;

echo "Przetwarzanie cen...
";

foreach ($values as $row) {
    $name = $row[$partNoIdx] ?? '';
    $newPrice = str_replace(',', '.', $row[$priceIdx] ?? '0');
    $newPrice = (float)$newPrice;

    if (isset($dbParts[$name])) {
        $part = $dbParts[$name];
        $oldPrice = (float)$part['price'];

        if (abs($newPrice - $oldPrice) > 0.000001) {
            // Update price in DB
            $MsaDB->update('list__parts', ['price' => $newPrice], 'id', $part['id']);
            $updatedCount++;

            // Trigger propagation
            $calculator->propagatePriceChange($part['id'], 'parts');
            $recalculatedCount++;
            
            echo "Zaktualizowano część: $name ($oldPrice -> $newPrice)
";
        }
    }
}

echo "Zakończono. Zaktualizowano $updatedCount części. Przeliczono powiązane BOMy.
";
