<?php
namespace Atte\Utils\Purchase\Order;

/**
 * Przydział pozycji dostawy do pozycji zamówienia (`purchase__delivery_allocation`).
 * Pola dołączane (poId, poNumber, ...) są wypełniane przez DeliveryLineRepository.
 */
class DeliveryAllocation {
    public int $id;
    public int $deliveryLineId;
    public int $poItemId;
    public float $quantity;
    public bool $isManual;
    public ?int $poId;
    public ?string $poNumber;
    public ?float $poUnitPrice;
    public ?string $poCurrency;

    public function __construct(array $row) {
        $this->id = (int)$row['id'];
        $this->deliveryLineId = (int)$row['deliveryLineId'];
        $this->poItemId = (int)$row['poItemId'];
        $this->quantity = (float)$row['quantity'];
        $this->isManual = (int)($row['isManual'] ?? 0) === 1;
        $this->poId = isset($row['poId']) && $row['poId'] !== null ? (int)$row['poId'] : null;
        $this->poNumber = $row['poNumber'] ?? null;
        $this->poUnitPrice = isset($row['poUnitPrice']) && $row['poUnitPrice'] !== null ? (float)$row['poUnitPrice'] : null;
        $this->poCurrency = $row['poCurrency'] ?? null;
    }
}
