<?php
namespace Atte\Utils;

use Atte\DB\BaseDB;
use \PDO;

class CommissionGroup
{
    private $MsaDB;
    public array $groupValues;
    public array $commissions = [];

    public function __construct(BaseDB $MsaDB) {
        $this->MsaDB = $MsaDB;
    }

    public function loadCommissions() {
        $groupId = $this->groupValues['id'];
        $commissionsData = $this->MsaDB->query(
            "SELECT * FROM commission__list WHERE commission_group_id = $groupId AND isCancelled = 0"
        );

        $commissionRepo = new CommissionRepository($this->MsaDB);
        foreach($commissionsData as $commissionData) {
            $this->commissions[] = $commissionRepo->getCommissionById($commissionData['id']);
        }
    }

    public function getTransferDetails() {
        // Zwraca szczegółowe informacje o transferach w grupie
        $groupId = $this->groupValues['id'];
        $transferDetails = [];

        foreach(['sku', 'tht', 'smd', 'parts'] as $type) {
            // Pobieramy wszystkie transfery związane z grupą
            $transfers = $this->MsaDB->query("
                SELECT i.*, c.id as commission_id,
                       m.sub_magazine_name as magazine_name,
                       l.name as component_name,
                       l.description as component_description
                FROM inventory__$type i
                LEFT JOIN commission__list c ON i.commission_id = c.id
                LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
                LEFT JOIN list__$type l ON i.{$type}_id = l.id
                WHERE c.commission_group_id = $groupId
                ORDER BY i.timestamp ASC, i.quantity DESC
            ");

            $transferDetails[$type] = $transfers;
        }

        return $transferDetails;
    }

    public function getTransferSummaryByComponent() {
        // Grupuje transfery według komponentów pokazując źródła i cele
        $transferDetails = $this->getTransferDetails();
        $summary = [];

        foreach($transferDetails as $type => $transfers) {
            foreach($transfers as $transfer) {
                $componentKey = $type . '_' . $transfer["{$type}_id"];

                if(!isset($summary[$componentKey])) {
                    $summary[$componentKey] = [
                        'type' => $type,
                        'componentId' => $transfer["{$type}_id"],
                        'componentName' => $transfer['component_name'],
                        'componentDescription' => $transfer['component_description'],
                        'sources' => [], // magazyny źródłowe (quantity < 0)
                        'destinations' => [], // magazyny docelowe (quantity > 0)
                        'totalTransferred' => 0
                    ];
                }

                $magazineName = $transfer['magazine_name'];
                $quantity = abs($transfer['quantity']);

                if($transfer['quantity'] < 0) {
                    // Magazyn źródłowy
                    if(!isset($summary[$componentKey]['sources'][$magazineName])) {
                        $summary[$componentKey]['sources'][$magazineName] = 0;
                    }
                    $summary[$componentKey]['sources'][$magazineName] += $quantity;
                } else {
                    // Magazyn docelowy
                    if(!isset($summary[$componentKey]['destinations'][$magazineName])) {
                        $summary[$componentKey]['destinations'][$magazineName] = 0;
                    }
                    $summary[$componentKey]['destinations'][$magazineName] += $quantity;
                    $summary[$componentKey]['totalTransferred'] += $quantity;
                }
            }
        }

        return $summary;
    }

    public function cancelGroup($rollbackOption = 'full') {
        foreach($this->commissions as $commission) {
            $commission->cancel();
            if($rollbackOption === 'full') {
                $commission->rollbackItems('all', $this->MsaDB);
            }
        }
    }
}