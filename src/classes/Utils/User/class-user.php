<?php
namespace Atte\Utils;

use Atte\DB\BaseDB;
use \PDO;

class User
{
    private $MsaDB;
    public int $userId;
    public string $login;
    public string $name;
    public string $surname;
    public string $email;
    public bool $isAdmin;
    public bool $isActive;
    public ?int $subMagazineId;

    public function __construct(BaseDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function isAdmin(){
        return $this -> isAdmin;
    }

    public function getUserInfo(){
        $MsaDB = $this -> MsaDB;
        $id = $this -> userId;
        $sql = "SELECT u.*,
                   s.*,
                   u.isActive as user_isActive,
                   s.isActive as magazine_isActive
            FROM user u
            LEFT JOIN magazine__list s
            ON u.sub_magazine_id = s.sub_magazine_id
            WHERE user_id = $id";
        $result = $MsaDB -> query($sql, PDO::FETCH_ASSOC);

        // Return null if no results found
        if (empty($result)) {
            return null;
        }

        // To avoid confusion, we remove isActive since it can mean both user and magazine
        unset($result[0]['isActive']);
        return $result[0];
    }

    /**
     * Get array of active commissions where user
     * is the receiver of given commission.
     * @return array Array of Commission classes
     */
    public function getActiveCommissions(){
        $MsaDB = $this -> MsaDB;
        $id = $this->userId;
        $result = [];
        $queryResult = $MsaDB -> query("SELECT commission_id 
                                           FROM `commission__receivers` cr 
                                           JOIN commission__list cl 
                                           ON cr.commission_id = cl.id 
                                           WHERE cl.is_cancelled = 0 
                                           AND cr.user_id = $id 
                                           AND cl.state != 'returned'
                                           ORDER BY priority DESC",
            PDO::FETCH_COLUMN);
        if(empty($queryResult)) return $result;
        $commissionRepository = new \Atte\Utils\CommissionRepository($MsaDB);
        foreach($queryResult as $commissionId)
        {
            $result[] = $commissionRepository -> getCommissionById($commissionId);
        }
        return $result;
    }

    public function getDevicesUsed($deviceType){
        $MsaDB = $this -> MsaDB;
        $userId = $this -> userId;
        return $MsaDB -> query("SELECT {$deviceType}_id 
                                   FROM `used__{$deviceType}` 
                                   WHERE user_id = '{$userId}'",
            PDO::FETCH_COLUMN);
    }

}