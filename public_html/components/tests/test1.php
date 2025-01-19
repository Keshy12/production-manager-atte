<?php
use Atte\DB\MsaDB;
use Atte\Api\GoogleSheets;
use Atte\Utils\MagazineRepository;
use Atte\Utils\UserRepository;

$googleSheets = new GoogleSheets();

$ref_mag_parts_sheet = getRefMagParts($googleSheets);

function getRefMagParts($googleSheets){
    $result = $googleSheets -> readSheet('1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew', 'ref_mag_parts', 'H:M');
    // Remove header row
    unset($result[0]);
    $MsaDB = MsaDB::getInstance();
    $list__parts = $MsaDB -> readIdName('list__parts', 'id', 'description');
    array_walk($result, function(&$row) use ($MsaDB, $list__parts){
        $row[0] = (int)$row[0];
        if(isset($list__parts[$row[0]])){
            if($row[2] !== $list__parts[$row[0]]){
                var_dump([$row[0], $row[2]]);
            }
        }
//        if($MsaDB->update('list__parts', ['description' => $row[2]], 'id', $row[0])){
//            var_dump([$row[0], $row[2]]);
//            echo "\n";
//        } else {
//            echo "<h4>ŹLE!</h4>";
//            var_dump($row);
//            echo "\n";
//        }
    });
    return 0;
}