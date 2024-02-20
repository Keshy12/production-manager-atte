<?php
namespace Atte\Utils;  

use Atte\DB\BaseDB;
use Atte\Utils\User;
use \PDO;

class UserRepository {
    private $MsaDB;

    public function __construct(BaseDB $MsaDB){
        $this -> database = $MsaDB;
    }

    public function getUserById($id) {
        $MsaDB = $this -> database;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, sub_magazine_id as subMagazineId 
                    FROM `user` WHERE `user_id` = '$id'";
        $result = $MsaDB -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$MsaDB]);
        if(isset($result[0])) {
            return $result[0];
        } else {
            throw new \Exception("There is no user with given id($id)", 2);
        }
    }

    public function getUserByEmail($email) {
        $MsaDB = $this -> database;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, sub_magazine_id as subMagazineId 
                    FROM `user` WHERE `email` = '$email'";
        $result = $MsaDB -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$MsaDB]);
        if(isset($result[0])) {
            return $result[0];
        } else {
            throw new \Exception("There is no user with given email($email)", 2);
        }
    }


}
