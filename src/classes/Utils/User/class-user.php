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

    public function getUserInfo(){//ToDo
        $database = $this -> database;
        $id = $this -> userId;
        $sql = "SELECT * FROM user u JOIN magazine__list s on u.sub_magazine_id = s.sub_magazine_id WHERE user_id = $id";
        $result = $database -> query($sql, PDO::FETCH_ASSOC);
        return $result[0];
    }

    public function getActiveCommissions(){
        $database = $this -> database;
        $id = $this->userId;
        $result = array();
        $rows = $database -> query("SELECT commission_id FROM `commission__receivers` cr 
                                join commission__list cl on cr.commission_id = cl.id 
                                WHERE cl.isCancelled = 0 AND cr.user_id = $id ORDER BY priority DESC;", PDO::FETCH_ASSOC);
        if(empty($rows)) return $result;
        foreach($rows as $row)
        {
            $commission = new \Commission($row["commission_id"]);
            $result[] = $commission -> getValues();
        }
        return $result;
    }

}