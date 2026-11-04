<?php
namespace Atte\Utils\Purchase\Order;

/**
 * Pozycja faktury / WZ (`purchase__delivery_line`). Przydziały do PO
 * (`allocations`) ładuje DeliveryLineRepository::getByDelivery().
 */
class DeliveryLine {
    public int $id;
    public int $deliveryId;
    public int $lineNo;
    public int $vendorPartId;
    public float $quantity;
    public ?float $unitPrice;
    public ?int $subMagazineId;
    public ?int $inventoryPartsId;
    public ?string $comment;
    public ?int $partsId;
    public ?string $vendorPartNo;
    public ?string $producerPartNo;
    public ?string $partName;
    public ?string $subMagazineName;
    /** @var DeliveryAllocation[] */
    public array $allocations = [];

    public function __construct(array $row) {
        $this->id = (int)$row['id'];
        $this->deliveryId = (int)$row['deliveryId'];
        $this->lineNo = (int)($row['lineNo'] ?? 0);
        $this->vendorPartId = (int)$row['vendorPartId'];
        $this->quantity = (float)$row['quantity'];
        $this->unitPrice = isset($row['unitPrice']) && $row['unitPrice'] !== null ? (float)$row['unitPrice'] : null;
        $this->subMagazineId = isset($row['subMagazineId']) && $row['subMagazineId'] !== null
            ? (int)$row['subMagazineId'] : null;
        $this->inventoryPartsId = isset($row['inventoryPartsId']) && $row['inventoryPartsId'] !== null
            ? (int)$row['inventoryPartsId'] : null;
        $this->comment = $row['comment'] ?? null;
        $this->partsId = isset($row['partsId']) && $row['partsId'] !== null ? (int)$row['partsId'] : null;
        $this->vendorPartNo = $row['vendorPartNo'] ?? null;
        $this->producerPartNo = $row['producerPartNo'] ?? null;
        $this->partName = $row['partName'] ?? null;
        $this->subMagazineName = $row['subMagazineName'] ?? null;
    }
}
