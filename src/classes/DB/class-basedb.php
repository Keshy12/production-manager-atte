<?php
namespace Atte\DB;  

use \PDO;
use \Dotenv;

// Use vlucas/dotenv to get credentials from .env 
$dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY);
$dotenv->load();

class BaseDB {
    protected $dbUrl;
    protected $dbUsername;
    protected $dbPassword;
    public $db;
    
    public function __construct(){
        if(!isset($this->db)){
            // Connect to the database
            $conn = new PDO($this->dbUrl, $this->dbUsername, $this->dbPassword);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db = $conn;
        }
    }

    public function isInTransaction(){
        return $this -> db -> inTransaction();
    }

    /**
    * Get result from a query.
    * @param string $sql Valid SQL query
    * @param int $fetch PDO::FETCH_* constant
    * @param mixed $fetchExtraParameters Extra patameter for fetching (f.e. class name for PDO::FETCH_CLASS)
    * @return array Result of the query, fetched.
    */
    public function query($sql, $fetch = PDO::FETCH_BOTH, ...$fetchExtraParameters) {
        $db = $this -> db;
        $query = $db -> prepare($sql);
        $query -> execute();

        if(is_null($fetchExtraParameters)) return $query -> fetchAll($fetch);
        return $query -> fetchAll($fetch, ...$fetchExtraParameters);
    }

    /**
    * Insert values into table using parameterized queries.
    * @param string $table
    * @param array $columns array of columns to insert into
    * @param array $values corresponding values
    * @return int|object Inserted ID on success, throw Exception otherwise.
    */
    public function insert(string $table, array $columns, array $values) {
        $db = $this -> db;
        $countColumns = count($columns);
        $countValues = count($values);
        if($countValues != $countColumns) {
            throw new \Exception('Different number of elements in $columns and $values');
        }
        // Make an array with "?" parameter, to use for prepared pdo statement.
        $questionMarkParam = array_fill(0, $countValues, "?");
        $sql = "INSERT INTO $table (".implode(", ", $columns).") VALUES (".implode(", ", $questionMarkParam).");";
        $query = $db -> prepare($sql);
        $query -> execute($values); 
        $id = $db -> lastInsertId();
        return $id;
    }

    /**
    * Update values in table using parameterized queries.
    * UPDATE `$table` SET `columName` = `newValue` WHERE `$checkColumn` = $checkValue
    * @param string $table
    * @param array $updateValues syntax: ['columnName' => 'newValue']
    * @param string $checkColumn Column to check in WHERE
    * @param mixed $checkValue Value to check in WHERE
    * @return bool|object True on success, throw Exception otherwise.
    */
    public function update(string $table, array $updateValues, string $checkColumn, $checkValue) {
        $db = $this -> db;
        $columns = array();
        $values = array();
        foreach($updateValues as $key => $item) {
            $columns[] = "`$key` = ?";
            $values[] = $item;
        }
        $sql = "UPDATE `$table` 
                SET ".implode(", ", $columns)." 
                WHERE `$table`.`$checkColumn` = '$checkValue'";
        $query = $db -> prepare($sql);
        $query -> execute($values); 
        return true;
    }
    /**
    * Delete row in table by provided id.
    * @param string $table Name of the table.
    * @param int $id Id to delete.
    * @return bool|object True on success, false on failure.
    */
    public function deleteById(string $table, int $id) {
        $db = $this -> db;
        $sql = "DELETE FROM `$table` WHERE `id` = $id";
        $query = $db -> prepare($sql);
        return $query -> execute(); 
    }

    // Prevent cloning of the instance
    public function __clone()
    {
    }

    // Prevent unserialization of the instance
    public function __wakeup()
    {
    }
}