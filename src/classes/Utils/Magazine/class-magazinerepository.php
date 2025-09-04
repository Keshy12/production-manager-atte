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
     * @param int $id Id of magazine.
     * @return Magazine Magazine class
     */
    public function getMagazineById($id) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT sub_magazine_id as id, sub_magazine_name as name, type_id as typeId FROM `magazine__list` WHERE `sub_magazine_id` = $id";
        $queryResult = $MsaDB -> query($query, \PDO::FETCH_CLASS, "Atte\\Utils\\Magazine", [$MsaDB]);
        if(isset($queryResult[0])) {
            return $queryResult[0];
        } else {
            throw new \Exception("There is no magazine with given id($id)");
        }
    }

    /**
     * Get all magazines with their type information
     * @return array Array of magazine data with type names
     */
    public function getAllMagazines($onlyIsActive = true) {
        $MsaDB = $this -> MsaDB;
        $add = $onlyIsActive ? "WHERE `isActive` = 1" : "";
        $query = "SELECT ml.sub_magazine_id, ml.sub_magazine_name, ml.type_id, ml.isActive, mt.name as type_name
              FROM magazine__list ml
              LEFT JOIN magazine__type mt ON ml.type_id = mt.id
                {$add}
              ORDER BY ml.type_id ASC, ml.sub_magazine_id ASC";
        return $MsaDB -> query($query);
    }

    /**
     * Get all magazine types as id => name array
     * @return array Magazine types
     */
    public function getMagazineTypes() {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> readIdName('magazine__type');
    }

    /**
     * Create a new magazine
     * @param string $name Magazine name
     * @param int $typeId Magazine type ID
     * @return int Created magazine ID
     */
    public function createMagazine(string $name, int $typeId) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> insert(
            'magazine__list',
            ['sub_magazine_name', 'type_id'],
            [$name, $typeId]
        );
    }

    /**
     * Update existing magazine
     * @param int $id Magazine ID
     * @param string $name New magazine name
     * @param int $typeId New magazine type ID
     * @return bool Success status
     */
    public function updateMagazine(int $id, string $name, int $typeId) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> update(
            'magazine__list',
            [
                'sub_magazine_name' => $name,
                'type_id' => $typeId
            ],
            'sub_magazine_id',
            $id
        );
    }

    /**
     * Create a new magazine type
     * @param string $name Type name
     * @return int Created type ID
     */
    public function createMagazineType(string $name) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> insert(
            'magazine__type',
            ['name'],
            [$name]
        );
    }

    /**
     * Check if magazine is used by any users
     * @param int $id Magazine ID
     * @return bool True if magazine is assigned to users
     */
    public function isAssignedToUsers(int $id) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT COUNT(*) as count FROM `user` WHERE sub_magazine_id = $id";
        $result = $MsaDB -> query($query);
        return $result[0]['count'] > 0;
    }

    /**
     * Get all users assigned to a specific magazine
     * @param int $magazineId Magazine ID
     * @return array Array of User objects
     */
    public function getUsersAssignedToMagazine(int $magazineId) {
        $MsaDB = $this -> MsaDB;
        $query = "SELECT user_id as userId, login, name, surname, email, isAdmin, sub_magazine_id as subMagazineId 
                  FROM `user` 
                  WHERE sub_magazine_id = $magazineId AND isActive = 1 
                  ORDER BY surname, name";
        return $MsaDB -> query($query, \PDO::FETCH_CLASS, "Atte\\Utils\\User", [$MsaDB]);
    }

    /**
     * Assign user to magazine
     * @param int $userId User ID
     * @param int|null $magazineId Magazine ID (null to unassign)
     * @return bool Success status
     */
    public function assignUserToMagazine(int $userId, $magazineId) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> update(
            'user',
            ['sub_magazine_id' => $magazineId],
            'user_id',
            $userId
        );
    }

    /**
     * Toggle magazine active status
     * @param int $id Magazine ID
     * @param bool $isActive Active status
     * @return bool Success status
     */
    public function toggleMagazineStatus(int $id, bool $isActive) {
        $MsaDB = $this -> MsaDB;
        return $MsaDB -> update(
            'magazine__list',
            ['isActive' => $isActive ? 1 : 0],
            'sub_magazine_id',
            $id
        );
    }
}