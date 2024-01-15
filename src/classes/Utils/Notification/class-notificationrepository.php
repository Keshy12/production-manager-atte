<?php
namespace Atte\Utils;  

use Atte\DB\MsaDB;
use Atte\Utils\Notification;

class NotificationRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this -> MsaDB = $MsaDB;
    }

    public function getNotificationById($id) {
        $database = $this -> MsaDB;
        $query = "SELECT * FROM `notification__list` WHERE `id` = $id";
        $result = $database -> query($query, \PDO::FETCH_ASSOC);
        if(isset($result[0])) {
            return new Notification($database, $result[0]);
        } else {
            throw new \Exception("There is no notification with given id($id)");
        }
    }
    public function getUnresolvedNotifications(){
        $database = $this -> MsaDB;
        $dbresult = $database -> query("SELECT * FROM `notification__list` 
                                        WHERE isResolved = 0");
        $result = [];
        foreach($dbresult as $row) {
            $result[] = new Notification($database, $row);
        }
        return $result;
    }


    public function createNotification($actionNeeded, $row, $valueForAction, $exceptionValues, $flowpinQueryTypeId) {
        $database = $this -> MsaDB;
        $valueForActionClause = isset($valueForAction) ? "AND value_for_action = '$valueForAction'" : " ";
        $dbresult = $database -> query("SELECT id FROM `notification__list` 
                                        WHERE action_needed_id = $actionNeeded 
                                        $valueForActionClause
                                        AND isResolved = 0", 
                                        \PDO::FETCH_COLUMN);
        $notificationId = isset($dbresult[0]) ? $dbresult[0] : $database -> insert("notification__list", ["action_needed_id", "value_for_action"], [$actionNeeded, $valueForAction]);
        $values = $database -> query("SELECT * FROM `notification__list` WHERE id = $notificationId")[0];
        $notification = new Notification($database, $values);
        $notification -> addValuesToResolve($row, $exceptionValues, $flowpinQueryTypeId);
        return $notification;
    }

    /**
    * Create notification from given exception, or add queries to existing notification.
    * @param \Throwable $exception Exception that will be used for creating notification.
    * @param mixed $row Query that caused error.
    * @param mixed $valueForAction Value to replace in queries when resolving.
    * @param int|null $flowpinQueryTypeId Type of flowpin query that caused exception (for future resolvement).
    * @return Notification Notification class
    */
    public function createNotificationFromException(\Throwable $exception, mixed $row = null, int|null $flowpinQueryTypeId = null) {
        $exceptionClass = get_class($exception);
        switch($exceptionClass) {
            case "PDOException":
                return $this -> createNotificationFromPDOException($exception, $row, $flowpinQueryTypeId);
            case "Exception":
                return $this -> createNotificationFromCustomException($exception, $row, $flowpinQueryTypeId);

            default:
                return $this -> createNotification(0, $row, NULL, serialize($exception), $flowpinQueryTypeId);
        }
    }
    private function createNotificationFromPDOException(\PDOException $exception, $row, $flowpinQueryTypeId) {
        $exceptionCode = $exception -> getCode();
        $exceptionValues = serialize($exception);
        switch($exceptionCode) {
            case 23000:
                $actionNeeded = 1;
                $valueForAction = $row["ProductTypeId"];
                break;

            default:
                $actionNeeded = 0;
                $valueForAction = NULL;
                break;
        }
        return $this -> createNotification($actionNeeded, $row, $valueForAction, $exceptionValues, $flowpinQueryTypeId);

    }

    private function createNotificationFromCustomException(\Exception $exception, $row, $flowpinQueryTypeId) {
        $exceptionCode = $exception -> getCode();
        $exceptionValues = serialize($exception);
        switch($exceptionCode) {
            case 1:
                $actionNeeded = 1;
                $valueForAction = $row["ProductTypeId"];
                break;
            case 2:
                $actionNeeded = 2;
                $valueForAction = $row["ByUserEmail"];
                break;

            default:
                $actionNeeded = 0;
                $valueForAction = NULL;
                break;
        }
        return $this -> createNotification($actionNeeded, $row, $valueForAction, $exceptionValues, $flowpinQueryTypeId);

    }
}
