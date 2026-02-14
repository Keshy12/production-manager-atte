<?php
namespace Atte\DB;  

class MsaDB extends BaseDB {
    // The single instance
    private static $instance;

    // Private constructor to prevent direct instantiation
    private function __construct(){
        $this->dbUrl = $_ENV['MSAURL'];
        $this->dbUsername = $_ENV['MSAUSERNAME'];
        $this->dbPassword = $_ENV['MSAPASSWORD'];
        parent::__construct();
    }

    // Get the single instance of Database
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function readIdName($table, $id = "id", $name = "name", $add = null) {
        if($add === null) $add = "ORDER BY $id ASC";
        $rows = $this->query("SELECT $id, $name FROM $table $add", \PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($rows as $row) {
            $result[$row[$id]] = $row[$name];
        }
        return $result;
    }
}