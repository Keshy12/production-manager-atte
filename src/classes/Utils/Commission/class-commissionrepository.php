<?php
namespace Atte\Utils;

use Atte\DB\MsaDB;
use Atte\Utils\Commission;

class CommissionRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    /**
     * Get Commission class by id from DB.
     * @param int $id Id of commission.
     * @return Commission Commission class
     */
    public function getCommissionById($id) {
        $MsaDB = $this->MsaDB;
        $query = "SELECT * FROM `commission__list` WHERE `id` = $id";
        $queryResult = $MsaDB->query($query, \PDO::FETCH_ASSOC);
        if(isset($queryResult[0])) {
            $row = $queryResult[0];
            $row = array_filter($row, fn ($value) => !is_null($value));

            $type = $row["device_type"];
            $row["deviceBomId"] = $row["bom_id"];

            $commission = new Commission($MsaDB);
            $commission->deviceType = $type;
            $commission->commissionValues = $row;
            return $commission;
        } else {
            throw new \Exception("There is no commission with given id($id)");
        }
    }

    /**
     * Get Commission classes by ids from DB.
     * @param int[] $ids Ids of commissions.
     * @return Commission[] Array of Commission classes
     */
    public function getCommissionsByIds(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        $MsaDB = $this->MsaDB;
        $idsStr = implode(',', array_map('intval', $ids));
        $query = "SELECT * FROM `commission__list` WHERE `id` IN ($idsStr)";
        $queryResult = $MsaDB->query($query, \PDO::FETCH_ASSOC);

        $commissions = [];
        foreach ($queryResult as $row) {
            $row = array_filter($row, fn ($value) => !is_null($value));
            $type = $row["device_type"];
            $row["deviceBomId"] = $row["bom_id"];

            $commission = new Commission($MsaDB);
            $commission->deviceType = $type;
            $commission->commissionValues = $row;
            $commissions[$row['id']] = $commission;
        }

        return $commissions;
    }

    /**
     * Get receivers for multiple commissions.
     * @param int[] $ids Ids of commissions.
     * @return array Associative array [commissionId => [userId, ...]]
     */
    public function getReceiversForCommissions(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        $MsaDB = $this->MsaDB;
        $idsStr = implode(',', array_map('intval', $ids));
        $query = "SELECT commission_id, user_id FROM commission__receivers WHERE commission_id IN ($idsStr)";
        $queryResult = $MsaDB->query($query, \PDO::FETCH_ASSOC);

        $receivers = [];
        foreach ($queryResult as $row) {
            $receivers[$row['commission_id']][] = $row['user_id'];
        }

        return $receivers;
    }

}