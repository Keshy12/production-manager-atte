<?php
namespace Atte\DB;  

require_once(realpath(dirname(__FILE__) . '/class-basedb.php'));

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
}