<?php
function getCsvBomFlat($filteredCsvArray, $ref__valuePackage, $list__tht, $list__parts_types)
{
    $csvTHTBomFlat = [];
    $csvSMDBomFlat = [];
    $csvMissingValuePackage = [];
    foreach($filteredCsvArray as $csvArrayRow)
    {
        list($qty, $value, $partNum, $package, $parts) = $csvArrayRow;
        $multiplier = getMultiplier($value);
        $qty *= $multiplier;
        $valuePackageColumn = array_column($ref__valuePackage, "ValuePackage");
        $valuePackage = $value.$package;
        $foundKey = array_search($valuePackage, $valuePackageColumn);
        if($foundKey === false) {
            $csvMissingValuePackage[] = $valuePackage;
            continue;
        }
        $foundValuePackage = $ref__valuePackage[$foundKey];
        $foundDeviceType = "tht";
        $foundTHTId = $foundValuePackage['tht_id'];
        if(!is_null($foundTHTId)) {
            $csvTHTBomFlat[] = getBomFlatItem($foundDeviceType, $foundValuePackage['tht_id'], $qty);
            continue;
        }
        $foundDeviceType = "parts";
        $foundPartsId = $foundValuePackage['parts_id'];
        if(in_array($list__parts_types[$foundPartsId], SMD_PART_TYPES)) {
            $csvSMDBomFlat[] = getBomFlatItem($foundDeviceType, $foundValuePackage['parts_id'], $qty);
            continue;
        }
        $csvTHTBomFlat[] = getBomFlatItem($foundDeviceType, $foundValuePackage['parts_id'], $qty);
    }

    return [
        "THTBomFlat" => $csvTHTBomFlat,
        "SMDBomFlat" => $csvSMDBomFlat,
        "MissingValuePackage" => $csvMissingValuePackage
    ];
}

function getBomFlatItem($deviceType, $componentId, $qty)
{
    return [
        "type" => $deviceType,
        "componentId" => $componentId,
        "quantity" => $qty
    ];
}

function getMultiplier($value)
{
    $multiplier = explode(' x ', $value);
    $multiplier = count($multiplier) > 1 ? array_pop($multiplier) : 1;
    return $multiplier;
}
