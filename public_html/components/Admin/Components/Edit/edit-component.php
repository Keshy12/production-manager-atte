<?php
use Atte\DB\MsaDB;
use Atte\Utils\Bom\PriceCalculator;

$MsaDB = MsaDB::getInstance();


$deviceType = $_POST["deviceType"];
$deviceId = $_POST["deviceId"];

$isActive = (isset($_POST["isActive"]) && $_POST["isActive"] == "on");

$componentValues = [
    "name" => $_POST["name"],
    "description" => $_POST["description"],
    "isActive" => $isActive
];

if (isset($_POST["defaultBomId"])) {
    $componentValues["default_bom_id"] = $_POST["defaultBomId"] == "" ? null : $_POST["defaultBomId"];
}

switch($deviceType)
{
    case "tht":

        // Only checked checkboxes are posted, so make false the default state
        // and change accordingly.
        $marking = $_POST["marking"] ?? [];
        $defaultKeys = ['circle_checked', 'triangle_checked', 'square_checked'];
        $defaultValues = array_fill_keys($defaultKeys, false);
        $newValues = array_fill_keys($marking, true);
        $markingValues = array_merge($defaultValues, $newValues);
        $componentValues = array_merge($componentValues, $markingValues);
    case "sku":
        $componentValues['isAutoProduced'] = isset($_POST['autoProduce']);
        $componentValues['autoProduceVersion'] = ($componentValues['isAutoProduced'] && $_POST['autoProduceVersion'] !== 'n/d')
            ? $_POST['autoProduceVersion']
            : null;
        break;
    case "parts":
        $componentValues["PartGroup"] = $_POST["partGroup"];
        $componentValues["PartType"] = $_POST["partType"] == 0 ? null : $_POST["partType"];
        $componentValues["JM"] = $_POST["jm"];
        break;
}

$editResult = "Zedytowano dane pomyślnie";
$editSuccessful = true;
try
{
    $MsaDB -> update("list__".$deviceType, $componentValues, "id", $deviceId);
    
    $PriceCalculator = new PriceCalculator($MsaDB);
    if ($deviceType === 'parts') {
        $PriceCalculator->propagatePriceChange($deviceId, 'parts');
    } elseif (array_key_exists('default_bom_id', $componentValues)) {
        if ($componentValues['default_bom_id'] !== null) {
            $PriceCalculator->updateBomPriceAndPropagate((int)$componentValues['default_bom_id'], $deviceType);
        } else {
            // If default BOM was removed, propagate 0 price to parents
            $PriceCalculator->propagatePriceChange($deviceId, $deviceType);
        }
    }
}
catch(\Throwable $e)
{
    $editResult = "Wystąpił błąd podczas edycji. Treść błędu: ".$e->getMessage();
    $editSuccessful = false;
}


function convertToJpegAndSave($fileExtension, $fileTmpPath, $newFilePath)
{
    switch ($fileExtension) {
        case 'jpg':
        case 'jpeg':
            // No conversion needed, just move the file
            move_uploaded_file($fileTmpPath, $newFilePath);
            break;
        case 'png':
            $image = imagecreatefrompng($fileTmpPath);
            imagejpeg($image, $newFilePath, 100);
            imagedestroy($image);
            break;
        case 'gif':
            $image = imagecreatefromgif($fileTmpPath);
            imagejpeg($image, $newFilePath, 100);
            imagedestroy($image);
            break;
        case 'webp':
            $image = imagecreatefromwebp($fileTmpPath);
            imagejpeg($image, $newFilePath, 100);
            imagedestroy($image);
            break;
        default:
            throw new \Exception("There was an error with converting file to JPG. Try again with different extension");
    }

}

$file = $_FILES['image'];

$imageUploadResult = null;
$imageUploadSuccessful = null;

$fileUploaded = $file['error'] !== UPLOAD_ERR_NO_FILE;

if($fileUploaded)
{
    $imageUploadResult = "Przesłano plik z powodzeniem";
    $imageUploadSuccessful = true;
    try
    {   
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception("There was an error with uploading the image. Error code:".$file['error']);
        }
    
        $fileTmpPath = $file['tmp_name'];
        $fileName = $file['name'];
        $fileSize = $file['size'];
        $fileExtension = $file['type'];
    
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
        $newFileName = $deviceId.'.jpg';
        $newFilePath = ROOT_DIRECTORY."/public_html/assets/img/items/".$deviceType."/".$newFileName;
    
        convertToJpegAndSave($fileExtension, $fileTmpPath, $newFilePath);
    }
    catch(\Throwable $e)
    {
        $imageUploadResult = "Wystąpił błąd podczas przesyłania pliku. Treść błędu: ".$e->getMessage();
        $imageUploadSuccessful = false;
    }
}


echo json_encode([$editResult, $editSuccessful, $imageUploadResult, $imageUploadSuccessful]
                        , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);