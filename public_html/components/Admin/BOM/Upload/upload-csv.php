<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

header('Content-Type: application/json');
$MsaDB = MsaDB::getInstance();
$bomRepository = new BomRepository($MsaDB);

$list__tht = $MsaDB -> readIdName('list__tht');
$list__tht_desc = $MsaDB -> readIdName('list__tht', 'id', 'description');
$list__smd = $MsaDB -> readIdName('list__smd');
$list__smd_desc = $MsaDB -> readIdName('list__smd', 'id', 'description');
$list__laminate = $MsaDB -> readIdName('list__laminate');
$list__parts = $MsaDB -> readIdName('list__parts');
$list__parts_desc = $MsaDB -> readIdName('list__parts', 'id', 'description');
$list__parts_types = $MsaDB -> readIdName('list__parts', 'id', 'PartType');

$file = basename($_FILES["BomCsv"]["name"]);
$fileExtension = strtolower(pathinfo($file,PATHINFO_EXTENSION));
$fileSize = $_FILES["BomCsv"]["size"];
$fileTmpName = $_FILES["BomCsv"]["tmp_name"];

if($fileExtension != "csv") throw new \Exception("Incorrect extension (expecting: csv).");
if($fileSize > 5242880) throw new \Exception("File too big (over 5MB).");

$csvAsArray = array_map('str_getcsv', file($fileTmpName));

//Remove Header, then re-assign keys.
//Re-assign is done so array_search works with 2d array (using array_column).
unset($csvAsArray[0]);
$csvAsArray = array_values($csvAsArray);

$csvDeviceNameKey = array_search("NAZWA_URZADZENIA_OPIS",array_column($csvAsArray, 3));
$csvDeviceName = $csvAsArray[$csvDeviceNameKey][1];

// Included file generates fatal and non fatal errors,
// pushes them to variables defined.
$fatalErrors = [];
$nonFatalErrors = [];

$dbTHTBom = null;
$dbSMDBom = null;
require('validate-device-with-db.php');

// Includes function to filter unnecessary data from read CSV.
require('filter-csv.php');
$ref__packageToExclude = $MsaDB -> query("SELECT name FROM ref__package_exclude", PDO::FETCH_COLUMN);

$csvAsArray = filterCSVArray($csvAsArray, $ref__packageToExclude);

$dbTHTBomFlat = is_null($dbTHTBom) ? [] : $dbTHTBom -> getComponents(1);
$dbSMDBomFlat = is_null($dbSMDBom) ? [] : $dbSMDBom -> getComponents(1);
$dbTHTBomId = is_null($dbTHTBom) ? null : $dbTHTBom -> id;
$dbSMDBomId = is_null($dbSMDBom) ? null : $dbSMDBom -> id;

$ref__valuePackage = $MsaDB -> query("SELECT * FROM ref__valuepackage", PDO::FETCH_ASSOC);

// Includes functions to get bom flat from CSV file
include('get-csv-bom-flat.php');
const SMD_PART_TYPES = [1, 3];
$csvBomFlat = getCsvBomFlat($csvAsArray, $ref__valuePackage, $list__tht, $list__parts_types);

$csvTHTBomFlat = $csvBomFlat['THTBomFlat'];
$csvSMDBomFlat = $csvBomFlat['SMDBomFlat'];
$missingValuePackages = $csvBomFlat['MissingValuePackage'];
foreach($missingValuePackages as $missingVP)
{
    $fatalErrors[] = 'Brak <b class="user-select-all">'
        .$missingVP
        .'</b> w słowniku '
        .'<a href="http://'.BASEURL.'/admin/bom/dictionary?dictionary=ref__valuepackage&value='.$missingVP.'" target="_blank">ref__valuePackage</a> lub '
        .'<a href="http://'.BASEURL.'/admin/bom/dictionary?dictionary=ref__package_exclude&value='.$missingVP.'" target="_blank">ref__packageExclude</a>.
        Dodaj go, a następnie prześlij BOM ponownie.';
}
// Variables in if statement defined in validate-device-with-db.php
if($csvHasSMD && $dbSMDIdFound) $csvTHTBomFlat[] = [
    "type" => "smd",
    "componentId" => $dbSMDId,
    "quantity" => 1
];

$addComponentDetails = function($component) use ($list__tht, $list__tht_desc, $list__smd, $list__smd_desc, $list__laminate, $list__parts, $list__parts_desc) {
    $type = $component['type'];
    $componentId = $component['componentId'];
    
    $component['componentName'] = ${'list__'.$type}[$componentId];
    $component['componentDescription'] = ${'list__'.$type.'_desc'}[$componentId];

    return $component;
};

$dbTHTBomFlat = array_map($addComponentDetails, $dbTHTBomFlat);
$dbSMDBomFlat = array_map($addComponentDetails, $dbSMDBomFlat);
$csvTHTBomFlat = array_map($addComponentDetails, $csvTHTBomFlat);
$csvSMDBomFlat = array_map($addComponentDetails, $csvSMDBomFlat);

// Fetch current default_bom_id for THT
$defaultBomIdTHT = null;
if ($dbTHTId) {
    $result = $MsaDB->query("SELECT default_bom_id FROM list__tht WHERE id = {$dbTHTId}", PDO::FETCH_COLUMN);
    $defaultBomIdTHT = !empty($result) ? $result[0] : null;
}

// Fetch current default_bom_id for SMD
$defaultBomIdSMD = null;
if ($dbSMDId) {
    $result = $MsaDB->query("SELECT default_bom_id FROM list__smd WHERE id = {$dbSMDId}", PDO::FETCH_COLUMN);
    $defaultBomIdSMD = !empty($result) ? $result[0] : null;
}

// Fetch default BOM version for THT
$defaultBomVersionTHT = null;
if ($defaultBomIdTHT) {
    $result = $MsaDB->query("SELECT version FROM bom__tht WHERE id = {$defaultBomIdTHT}", PDO::FETCH_COLUMN);
    $defaultBomVersionTHT = !empty($result) ? $result[0] : null;
}

// Fetch default BOM version and laminate for SMD
$defaultBomVersionSMD = null;
$defaultBomLaminateSMD = null;
if ($defaultBomIdSMD) {
    $result = $MsaDB->query("SELECT bs.version, l.name as laminate_name FROM bom__smd bs LEFT JOIN list__laminate l ON bs.laminate_id = l.id WHERE bs.id = {$defaultBomIdSMD}", PDO::FETCH_ASSOC);
    if (!empty($result)) {
        $defaultBomVersionSMD = $result[0]['version'];
        $defaultBomLaminateSMD = $result[0]['laminate_name'];
    }
}

$THTFlatBoms = [
    "bomId" => $dbTHTBomId,
    "deviceId" => $dbTHTId,
    "deviceName" => $csvTHTItem['name'],
    "deviceVersion" => $csvTHTItem['version'],
    "defaultBomId" => $defaultBomIdTHT,
    "defaultBomVersion" => $defaultBomVersionTHT,
    "db" => $dbTHTBomFlat,
    "csv" => $csvTHTBomFlat
];
$SMDFlatBoms = [];
if($csvHasSMD)
{
    $SMDFlatBoms = [
        "bomId" => $dbSMDBomId,
        "deviceId" => $dbSMDId,
        "laminateId" => $dbLaminateId,
        "deviceName" => $csvSMDItem['name'],
        "laminateName" => $csvSMDItem['laminateName'],
        "deviceVersion" => $csvSMDItem['version'],
        "defaultBomId" => $defaultBomIdSMD,
        "defaultBomVersion" => $defaultBomVersionSMD,
        "defaultBomLaminate" => $defaultBomLaminateSMD,
        "db" => $dbSMDBomFlat,
        "csv" => $csvSMDBomFlat
    ];

}

echo json_encode([$fatalErrors, $nonFatalErrors, $THTFlatBoms, $SMDFlatBoms]
                        , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
exit;











