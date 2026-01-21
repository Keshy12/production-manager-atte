<?php
namespace Atte\Utils;  

use Atte\DB\BaseDB;
use Atte\Utils\User;
use \PDO;

class UserRepository {
    private $MsaDB;

    public function __construct(BaseDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getAllUsers()
    {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT user_id as userId, 
                        login, name, surname, 
                        email, isAdmin, 
                        sub_magazine_id as subMagazineId 
                    FROM `user` 
                    WHERE isActive = 1 
                    ORDER BY user_id ASC";
        $result = $MsaDB -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$MsaDB]);
        return $result;
    }

    public function getUserById($id) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, isActive, sub_magazine_id as subMagazineId 
                    FROM `user` WHERE `user_id` = '$id'";
        $result = $MsaDB -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$MsaDB]);
        if(isset($result[0])) {
            return $result[0];
        } else {
            throw new \Exception("There is no user with given id($id)", 2);
        }
    }

    public function getUserByEmail($email) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, isActive, sub_magazine_id as subMagazineId 
                    FROM `user` WHERE `email` = '$email'";
        $result = $MsaDB -> query($query, PDO::FETCH_CLASS, "Atte\\Utils\\User", [$MsaDB]);
        if(isset($result[0])) {
            return $result[0];
        } else {
            throw new \Exception("There is no user with given email($email)", 2);
        }
    }

    /**
     * Disable a user
     * @param int $userId User ID
     * @return bool Success status
     */
    public function disableUser(int $userId) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> update(
            'user',
            ['isActive' => 0],
            'user_id',
            $userId
        );
    }

    /**
     * Enable a user
     * @param int $userId User ID
     * @return bool Success status
     */
    public function enableUser(int $userId) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> update(
            'user',
            ['isActive' => 1],
            'user_id',
            $userId
        );
    }
}
