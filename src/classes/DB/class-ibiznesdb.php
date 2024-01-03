<?php
namespace Atte\DB;  

class IbiznesDB extends BaseDB {
    private static $instance;

    // Private constructor to prevent direct instantiation
    private function __construct(){
        $this->dbUrl = $_ENV['IBIZNESURL'];
        $this->dbUsername = $_ENV['IBIZNESUSERNAME'];
        $this->dbPassword = $_ENV['IBIZNESPASSWORD'];
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
}