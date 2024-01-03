<?php
namespace Atte\Utils;  

use Atte\DB\BaseDB;
use Atte\Utils\User;
use \PDO;

class UserRepository {
    private $database;

    public function __construct(BaseDB $database){
        $this -> database = $database;
    }

    public function getUserById($id) {
        $database = $this -> database;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, sub_magazine_id as subMagazineId 
                    FROM `user` WHERE `user_id` = '$id'";
        $result = $database -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$database]);
        if(isset($result[0])) {
            return $result[0];
        } else {
            throw new \Exception("There is no user with given id($id)", 2);
        }
    }

    public function getUserByEmail($email) {
        $database = $this -> database;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, sub_magazine_id as subMagazineId 
                    FROM `user` WHERE `email` = '$email'";
        $result = $database -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$database]);
        if(isset($result[0])) {
            return $result[0];
        } else {
            throw new \Exception("There is no user with given email($email)", 2);
        }
    }


}
