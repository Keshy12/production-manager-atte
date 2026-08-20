<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\{VendorRepository, VendorSupplierRepository, VendorPartRepository};

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID dostawcy']);
    exit;
}

try {
    $MsaDB    = MsaDB::getInstance();
    $vRepo    = new VendorRepository($MsaDB);
    $vsRepo   = new VendorSupplierRepository($MsaDB);
    $vpRepo   = new VendorPartRepository($MsaDB);

    $vendor = $vRepo->getById($id);
    if($vendor === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono dostawcy']);
        exit;
    }

    $suppliers   = $vsRepo->getByVendor($id, false);
    $vendorParts = $vpRepo->getByVendor($id, false);

    $supArr = [];
    foreach ($suppliers as $s) {
        $supArr[] = [
            'id'       => $s->id,
            'vendorId' => $s->vendorId,
            'name'     => $s->name,
            'jobTitle' => $s->jobTitle,
            'phone'    => $s->phone,
            'email'    => $s->email,
            'isActive' => $s->isActive,
            'comment'  => $s->comment,
        ];
    }

    $vpArr = [];
    foreach ($vendorParts as $vp) {
        $vpArr[] = [
            'id'               => $vp->id,
            'vendorId'         => $vp->vendorId,
            'producerId'       => $vp->producerId,
            'partsId'          => $vp->partsId,
            'vendorPartNo'     => $vp->vendorPartNo,
            'vendorJmId'       => $vp->vendorJmId,
            'fullPackQuantity' => $vp->fullPackQuantity,
            'isActive'         => $vp->isActive,
            'vendorName'       => $vp->vendorName,
            'producerName'     => $vp->producerName,
            'partName'         => $vp->partName,
            'unitName'         => $vp->unitName,
        ];
    }

    echo json_encode([
        'success' => true,
        'vendor'  => [
            'id'             => $vendor->id,
            'name'           => $vendor->name,
            'address'        => $vendor->address,
            'additionalData' => $vendor->additionalData,
            'leadTimeDays'   => $vendor->leadTimeDays,
            'comment'        => $vendor->comment,
            'isActive'       => $vendor->isActive,
        ],
        'suppliers'   => $supArr,
        'vendorParts' => $vpArr,
    ]);
} catch (\Throwable $e) {
    error_log('vendor-detail error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania szczegółów']);
}
exit;
