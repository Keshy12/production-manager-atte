<?php
namespace Atte\DB;  

require_once(realpath(dirname(__FILE__) . '/class-basedb.php'));

class FlowpinDB extends BaseDB {
    private static $instance;

    private function __construct(){
        $this->dbUrl = $_ENV['FLOWPINURL'];
        $this->dbUsername = $_ENV['FLOWPINUSERNAME'];
        $this->dbPassword = $_ENV['FLOWPINPASSWORD'];
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
    
    public function getWarehouses() {
        $query = "SELECT Id, Name, Description FROM [dbo].[Warehouses] WHERE CompanyId = 1";
        $result = $this -> query($query);
        return $result;
    }

    public function getUsers() {
        $query = "SELECT anu.* FROM [dbo].[AspNetUsers] anu JOIN [dbo].[User2Company] u2c ON anu.Id = u2c.User_Id WHERE Company_Id = 1";
        $result = $this -> query($query);
        return $result;
    }

    
}