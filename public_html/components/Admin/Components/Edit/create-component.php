<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$deviceType = $_POST["deviceType"];

$componentValues = [
    "name" => $_POST["name"],
    "description" => $_POST["description"],
    "isActive" => true
];

switch($deviceType)
{
    case "tht":
        // Only checked checkboxes are posted, so make false the default state
        // and change accordingly.
        $defaultKeys = ['circle_checked', 'triangle_checked', 'square_checked'];
        $defaultValues = array_fill_keys($defaultKeys, false);
        $newValues = array_fill_keys($_POST["marking"], true);
        $markingValues = array_merge($defaultValues, $newValues);

        $componentValues = array_merge($componentValues, $markingValues);
        break;
    case "parts":
        $componentValues["PartGroup"] = $_POST["partGroup"];
        $componentValues["PartType"] = $_POST["partType"] == 0 ? null : $_POST["partType"];
        $componentValues["JM"] = $_POST["jm"];
        break;
}

$insertColumns = array_keys($componentValues);
$insertValues = array_values($componentValues);

$addResult = "Dodano komponent pomyślnie";
$addSuccessful = true;
$insertedId = '';
try
{
    $insertedId = $MsaDB -> insert("list__".$deviceType, $insertColumns, $insertValues);
}
catch(\Throwable $e)
{
    $addResult = "Wystąpił błąd podczas dodawania. Treść błędu: ".$e->getMessage();
    $addSuccessful = false;
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

if($fileUploaded && $addSuccessful)
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
    
        $newFileName = $insertedId.'.jpg';
        $newFilePath = ROOT_DIRECTORY."/public_html/assets/img/items/".$deviceType."/".$newFileName;
    
        convertToJpegAndSave($fileExtension, $fileTmpPath, $newFilePath);
    }
    catch(\Throwable $e)
    {
        $imageUploadResult = "Wystąpił błąd podczas przesyłania pliku. Treść błędu: ".$e->getMessage();
        $imageUploadSuccessful = false;
    }
}

echo json_encode([$addResult, $addSuccessful, $imageUploadResult, $imageUploadSuccessful, $insertedId]
                        , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
