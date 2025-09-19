<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\Commission;

class CommissionRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    /**
    * Get Commission class by id from DB.
    * @param int $id Id of commission.
    * @return Commission Commission class
    */
    public function getCommissionById($id) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT * FROM `commission__list` WHERE `id` = $id";
        $queryResult = $MsaDB -> query($query, \PDO::FETCH_ASSOC);
        if(isset($queryResult[0])) {
            $row = $queryResult[0];
            $row = array_filter($row, fn ($value) => !is_null($value));
            $type = null;
            $type = isset($row["bom_sku_id"]) ? "sku" : $type;
            $type = isset($row["bom_tht_id"]) ? "tht" : $type;
            $type = isset($row["bom_smd_id"]) ? "smd" : $type;
            $row["deviceBomId"] = $row["bom_{$type}_id"];
            $commission = new Commission($MsaDB);
            $commission -> deviceType = $type;
            $commission -> commissionValues = $row;
            return $commission;
        } else {
            throw new \Exception("There is no commission with given id($id)");
        }
    }

    public function createCommissionGroup($createdBy, $transferFromDefault, $transferTo, $comment = null) {
        $now = date("Y-m-d H:i:s", time());
        $groupId = $this->MsaDB->insert('commission__groups', [
            'created_by',
            'timestamp_created',
            'transfer_from',
            'transfer_to',
            'comment'
        ], [
            $createdBy,
            $now,
            $transferFromDefault,
            $transferTo,
            $comment
        ]);

        return $groupId;
    }

    public function getCommissionGroupById($groupId) {
        $groupData = $this->MsaDB->query(
            "SELECT * FROM commission__groups WHERE id = $groupId"
        );

        if(empty($groupData)) {
            throw new \Exception("Commission group not found");
        }

        $group = new CommissionGroup($this->MsaDB);
        $group->groupValues = $groupData[0];
        $group->loadCommissions();

        return $group;
    }

}
