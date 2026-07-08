<?php
use Atte\DB\MsaDB;

header('Content-Type: application/json');
$MsaDB = MsaDB::getInstance();

$newSubMagId = null;
$subMagazineId = null;

// Check if we need to create a new sub magazine
if (isset($_POST["new_magazine_name"]) && !empty(trim($_POST["new_magazine_name"]))) {
    try {
        $magazineName = trim($_POST["new_magazine_name"]);
        $nextNumber = sprintf('%02d', $_POST["next_submag_number"]);
        $fullMagazineName = "SUB MAG $nextNumber: $magazineName";

        $newSubMagId = $MsaDB->insert("magazine__list",
            ["sub_magazine_name", "type_id"],
            [$fullMagazineName, 2]
        );

        $subMagazineId = $newSubMagId;
    } catch (\Throwable $e) {
        $resultMessage = "Wystąpił błąd przy tworzeniu magazynu: ".$e->getMessage();
        $wasSuccessful = false;
exit;
        echo json_encode([$resultMessage, $wasSuccessful], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
exit;
        return;
    }
} else {
    $subMagazineId = $_POST["sub_magazine_id"];
}

// Only proceed with user creation if we have a valid sub_magazine_id
if ($subMagazineId !== null) {
    $newIsAdmin = isset($_POST["isAdmin"]) ? 1 : 0;

    if ($newIsAdmin === 1) {
        $verifyAdminPassword = $_POST["verifyAdminPassword"] ?? '';
        if (empty($verifyAdminPassword) || !isset($_SESSION['user_id'])) {
            $resultMessage = "Tworzenie użytkownika z uprawnieniami administratora wymaga potwierdzenia hasłem.";
            $wasSuccessful = false;
            $insertedId = "";
            echo json_encode([$resultMessage, $wasSuccessful, $insertedId, $newSubMagId], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            return;
        }
        $actingAdminId = (int)$_SESSION['user_id'];
        $actingAdminRow = $MsaDB->query(
            "SELECT password FROM user WHERE user_id = $actingAdminId",
            \PDO::FETCH_ASSOC
        );
        $expectedHash = (!empty($actingAdminRow)) ? $actingAdminRow[0]['password'] : '';
        $providedHash = hash('sha256', $verifyAdminPassword);
        if (!hash_equals($expectedHash, $providedHash)) {
            $resultMessage = "Nieprawidłowe hasło administratora.";
            $wasSuccessful = false;
            $insertedId = "";
            echo json_encode([$resultMessage, $wasSuccessful, $insertedId, $newSubMagId], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    $columns = ["login", "password", "name", "surname", "email", "isAdmin", "isActive", "sub_magazine_id"];
    $values = [
        $_POST["login"],
        hash('sha256', $_POST["password"]),
        $_POST["name"],
        $_POST["surname"],
        $_POST["email"],
        $newIsAdmin,
        isset($_POST["isActive"]),
        $subMagazineId
    ];

    $resultMessage = "Pomyślnie dodano użytkownika";
    $wasSuccessful = true;
    $insertedId = "";

    try {
        $insertedId = $MsaDB -> insert("user", $columns, $values);
    } catch (\Throwable $e) {
        $resultMessage = "Wystąpił błąd przy dodawaniu. Kod błędu: ".$e->getMessage();
        $wasSuccessful = false;
    }
} else {
    $resultMessage = "Brak wybranego magazynu";
    $wasSuccessful = false;
    $insertedId = "";
}

echo json_encode([$resultMessage, $wasSuccessful, $insertedId, $newSubMagId], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);