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
        $result = "Wystąpił błąd przy tworzeniu magazynu: ".$e->getMessage();
        $wasSuccessful = false;
exit;
        echo json_encode([$result, $wasSuccessful], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
exit;
        return;
    }
} else {
    $subMagazineId = $_POST["sub_magazine_id"];
}

if ($subMagazineId !== null) {
    $userId = $_POST["user_id"];
    $newIsAdmin = isset($_POST["isAdmin"]) ? 1 : 0;

    $currentUserRow = $MsaDB->query(
        "SELECT isAdmin FROM user WHERE user_id = ".(int)$userId,
        \PDO::FETCH_ASSOC
    );
    $currentIsAdmin = (!empty($currentUserRow)) ? (int)$currentUserRow[0]['isAdmin'] : 0;
    $isAdminChanging = ($newIsAdmin !== $currentIsAdmin);

    if ($isAdminChanging) {
        $verifyAdminPassword = $_POST["verifyAdminPassword"] ?? '';
        if (empty($verifyAdminPassword) || !isset($_SESSION['user_id'])) {
            $result = "Zmiana uprawnień administratora wymaga potwierdzenia hasłem.";
            $wasSuccessful = false;
            echo json_encode([$result, $wasSuccessful, null], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
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
            $result = "Nieprawidłowe hasło administratora.";
            $wasSuccessful = false;
            echo json_encode([$result, $wasSuccessful, null], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    $userInfo = [
        "login" => $_POST["login"],
        "name" => $_POST["name"],
        "surname" => $_POST["surname"],
        "email" => $_POST["email"],
        "isActive" => isset($_POST["isActive"]),
        "sub_magazine_id" => $subMagazineId,
        "isAdmin" => $newIsAdmin
    ];

    $result = "Zedytowano dane pomyślnie";
    $wasSuccessful = true;

    try {
        $MsaDB -> update("user", $userInfo, "user_id", $userId);
    } catch(\Throwable $e) {
        $result = "Wystąpił błąd. Treść błędu: ".$e->getMessage();
        $wasSuccessful = false;
    }
} else {
    $result = "Brak wybranego magazynu";
    $wasSuccessful = false;
}

echo json_encode([$result, $wasSuccessful, $newSubMagId], JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);