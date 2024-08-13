<?php
// This file is a part of upload-bom.php, it is included and
// takes variables previously defined in upload-bom.php file.

$csvTHTItem = getTHTItemFromDeviceName($csvDeviceName);

$dbTHTId = array_search($csvTHTItem["name"], $list__tht);
$dbTHTIdFound = $dbTHTId !== false;

if(!$dbTHTIdFound) { 
    $fatalErrors[] = generateMissingDeviceMessage('THT', $csvTHTItem["name"]);
}
else {
    $THTBomValues = [
        "tht_id" => $dbTHTId,
        "version" => $csvTHTItem["version"]
    ];
    
    $dbTHTBoms = $bomRepository -> getBomByValues("tht", $THTBomValues);
    if($dbTHTBoms === null || count($dbTHTBoms) != 1)
    {
        $nonFatalErrors[] = 'Brak <b class="user-select-all">'
                            .$csvTHTItem['name']
                            .'</b> w wersji <b class="user-select-all">'
                            .$csvTHTItem['version']
                            .'</b> w bazie. 
                            Jeśli nazwa i wersja się zgadzają, możesz przesłać BOM.';
    }
    else {
        // this variable is defined in parent file (upload-csv.php) 
        $dbTHTBom = $dbTHTBoms[0];
    }
}

$csvSMDItem = getSMDItemFromDeviceName($csvDeviceName);
$csvHasSMD = $csvSMDItem !== null;

if($csvHasSMD)
{
    $dbSMDId = array_search($csvSMDItem["name"], $list__smd);
    $dbLaminateId = array_search($csvSMDItem["laminateName"], $list__laminate);
    
    $dbSMDIdFound = $dbTHTId !== false;
    $dbLaminateIdFound = $dbTHTId !== false;
    
    if(!$dbSMDIdFound) $fatalErrors[] = generateMissingDeviceMessage('SMD', $csvSMDItem["name"]);

    if(!$dbLaminateIdFound) {
        $nonFatalErrors[] = 'Brak <b class="user-select-all">'
                        .$csvSMDItem['laminateName']
                        .'</b> w liście laminatów.
                        Możesz przesłac BOM, laminat zostanie dodany do listy automatycznie.';
                    
        $nonFatalErrors[] = 'Brak <b class="user-select-all">'
                        .$csvSMDItem['name']
                        .'</b> z laminatem <b class="user-select-all">'
                        .$csvSMDItem['laminateName']
                        .'</b> w wersji <b class="user-select-all">'
                        .$csvSMDItem['version']
                        .'</b> w bazie. 
                        Jeśli nazwa i wersja się zgadzają, możesz przesłać BOM.';

    }

    if($dbSMDIdFound && $dbLaminateIdFound) {
        $SMDBomValues = [
            "smd_id" => $dbSMDId,
            "laminate_id" => $dbLaminateId,
            "version" => $csvSMDItem["version"]
        ];
        $dbSMDBoms = $bomRepository -> getBomByValues("smd", $SMDBomValues);
        if($dbSMDBoms === null || count($dbSMDBoms) != 1) {
            $nonFatalErrors[] = 'Brak <b class="user-select-all">'
                                .$csvSMDItem['name']
                                .'</b> z laminatem <b class="user-select-all">'
                                .$csvSMDItem['laminateName']
                                .'</b> w wersji <b class="user-select-all">'
                                .$csvSMDItem['version']
                                .'</b> w bazie. 
                                Jeśli nazwa i wersja się zgadzają, możesz przesłać BOM.';
        }
        else {
            // this variable is defined in parent file (upload-csv.php) 
            $dbSMDBom = $dbSMDBoms[0];
        }
    }
}

function generateMissingDeviceMessage($deviceType, $deviceName)
{
    return 'Brak <b class="user-select-all">'
            .$deviceName
            .'</b> w liście urządzeń '
            .$deviceType
            .'. Dodaj je, a następnie prześlij plik CSV ponownie.';
}

function getSMDItemFromDeviceName($deviceName)
{
    $deviceNameExploded = explode("_", $deviceName);

    $deviceNameSMDPart = array_reduce($deviceNameExploded, function($carry, $item) {
        if (strpos($item, "SMD") !== false) {
            return $item;
        }
        return $carry;
    });
    
    if($deviceNameSMDPart === null) return null;

    $SMDPart = explode(".", $deviceNameSMDPart);
    $laminatePart = array_pop($SMDPart);
    $laminateName = substr($laminatePart, 0, -1);
    $SMDName = implode(".", $SMDPart)."_BOM";
    $SMDVersion = substr($laminatePart, -1);
    return [
        "name" => $SMDName,
        "laminateName" => $laminateName,
        "version" => $SMDVersion
    ];
}

function getTHTItemFromDeviceName($deviceName)
{
    $deviceNameExploded = explode("_", $deviceName);

    $THTPart = array_filter($deviceNameExploded, function($item) {
        return strpos($item, "SMD") === false;
    });

    //Move pointer to "_BOM"
    end($THTPart);
    //Move pointer to previous position, get version and remove it from string.
    //substr(..., 0, -2), because we want to remove last character, as well as leading "."
    $THTver = substr(prev($THTPart), -1);
    $THTPart[key($THTPart)] = substr(current($THTPart), 0, -2);

    $THTName = implode("_", $THTPart);
    return [
        "name" => $THTName,
        "version" => $THTver
    ];
}
