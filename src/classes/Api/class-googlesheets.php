<?php 
namespace Atte\Api; 

require_once ROOT_DIRECTORY."/config/config-google-sheets.php";

class GoogleSheets
{
    private $service;

    public function __construct(){
        $client = new \Google_Client();
        $OAuth = new GoogleOAuth();
        $arr_token = (array) $OAuth->get_access_token();
        $accessToken = array(
            'access_token' => $arr_token['access_token'],
            'expires_in' => $arr_token['expires_in'],
        );
        $client->setAccessToken($accessToken);
        $this -> service = new \Google_Service_Sheets($client);      
    }

    /**
    * Read values from sheet.
    * @param string $spreadsheetId
    * @param string $sheetName
    * @param string $range syntax X:Y
    * @return array|bool Values read, or false on failure.
    */
    function readSheet($spreadsheetId = '',$sheetName = '', $range = '') {
        try {
            $response = $this -> service->spreadsheets_values->get($spreadsheetId, $sheetName.'!'.$range);
            $values = $response->getValues();
            //To make array start with key 1.
            array_unshift($values, "placeholder");
            unset($values[0]);
            return $values;
        } catch(\Exception $e) {
            if( 401 == $e->getCode() ) {
                $OAuth = new GoogleOAuth();
                $OAuth -> regenerateToken();
                return $this -> readSheet($spreadsheetId,$sheetName,$range);
            } else {
                print_r($e->getMessage());
                return false;
            }
        }
    }

    /**
    * Append to sheet, write after last cell with values. No override.
    * @param string $spreadsheetId
    * @param string $sheetName
    * @param string $range syntax X:Y
    * @param array $values to insert
    * @return int|bool Number of updated cells, or false on failure.
    */
    function appendToSheet($spreadsheetId = '',$sheetName = '', $range = '', $values = []) {
        try {
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);
            $params = [
                'valueInputOption' => 'USER_ENTERED'
            ];
            $result = $this->service->spreadsheets_values->append($spreadsheetId, $sheetName."!".$range, $body, $params);
            return get_object_vars($result)["updates"]["updatedCells"];
        } catch(\Exception $e) {
            if( 401 == $e->getCode() ) {
                $OAuth = new GoogleOAuth();
                $OAuth -> regenerateToken();
                return $this -> appendToSheet($spreadsheetId, $sheetName, $range, $values);
            } else {
                print_r($e->getMessage());
                return false;
            }
        }
    }

    /**
    * Write to sheet, clearing cells before inserting. Override.
    * @param string $spreadsheetId
    * @param string $sheetName
    * @param string $range syntax X:Y
    * @param array $values to insert
    * @return int|bool Number of written cells, or false on failure.
    */
    function writeToSheet($spreadsheetId = '',$sheetName = '', $range = '', $values = []) {
        try {
            $bodyClear = new \Google_Service_Sheets_ClearValuesRequest();
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);
            $params = [
                'valueInputOption' => 'USER_ENTERED'
            ];
            $result = $this->service->spreadsheets_values->clear($spreadsheetId, $sheetName."!".$range, $bodyClear);
            $result = $this->service->spreadsheets_values->update($spreadsheetId, $sheetName."!".$range, $body, $params);
            return $result -> getUpdatedCells();
        } catch(\Exception $e) {
            if( 401 == $e->getCode() ) {
                $OAuth = new GoogleOAuth();
                $OAuth -> regenerateToken();
                return $this -> writeToSheet($spreadsheetId, $sheetName, $range, $values);
            } else {
                print_r($e->getMessage());
                return false;
            }
        }
    }
}
