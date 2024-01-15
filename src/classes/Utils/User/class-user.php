<?php
namespace Atte\Utils;  

use Atte\DB\BaseDB;
use \PDO;

class User 
{
    private $database;
    public int $userId;
    public string $login;
    public string $name;
    public string $surname; 
    public string $email;
    public bool $isAdmin;
    public int $subMagazineId; 

    public function __construct(BaseDB $database){
        $this->database = $database;
    }

    public function isAdmin(){
        return $this -> isAdmin;
    }

    public function getUserInfo(){
        $database = $this -> database;
        $id = $this -> userId;
        $sql = "SELECT * 
                FROM user u 
                JOIN magazine__list s 
                ON u.sub_magazine_id = s.sub_magazine_id 
                WHERE user_id = $id";
        $result = $database -> query($sql, PDO::FETCH_ASSOC);
        return $result[0];
    }

    /**
    * Get array of active commissions where user 
    * is the receiver of given commission.
    * @return array Array of Commission classes
    */
    public function getActiveCommissions(){
        $database = $this -> database;
        $id = $this->userId;
        $result = [];
        $queryResult = $database -> query("SELECT commission_id 
                                           FROM `commission__receivers` cr 
                                           JOIN commission__list cl 
                                           ON cr.commission_id = cl.id 
                                           WHERE cl.isCancelled = 0 
                                           AND cr.user_id = $id 
                                           ORDER BY priority DESC", 
                                           PDO::FETCH_COLUMN);
        if(empty($queryResult)) return $result;
        $commissionRepository = new \Atte\Utils\CommissionRepository($database);
        foreach($queryResult as $commissionId)
        {
            $result[] = $commissionRepository -> getCommissionById($commissionId);
        }
        return $result;
    }

    public function getDevicesUsed($deviceType){
        $database = $this -> database;
        $userId = $this -> userId;
        return $database -> query("SELECT {$deviceType}_id 
                                   FROM `used__{$deviceType}` 
                                   WHERE user_id = '{$userId}'", 
                                   PDO::FETCH_COLUMN);
    }

}