<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\Magazine;

class MagazineRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    /**
    * Get Magazine class by id from DB.
    * @param int $id Id of commission.
    * @return Magazine Magazine class
    */
    public function getMagazineById($id) {
        $database = $this -> MsaDB;
        $query = "SELECT sub_magazine_id as id, sub_magazine_name as name, type_id as typeId FROM `magazine__list` WHERE `sub_magazine_id` = $id";
        $queryResult = $database -> query($query, \PDO::FETCH_CLASS, "Atte\\Utils\\Magazine", [$database]);
        if(isset($queryResult[0])) {
            return $queryResult[0];
        } else {
            throw new \Exception("There is no magazine with given id($id)");
        }
    }

}
