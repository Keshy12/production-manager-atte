<?php 
namespace Atte\Api; 

require_once ROOT_DIRECTORY."/config/config-google-sheets.php";

class GoogleOAuth
{
    private $database;

    public function __construct(){
        $this -> database = \Atte\DB\MsaDB::getInstance();
    }

    public function regenerateToken(){
        $refresh_token = $this->get_refresh_token();
        $client = new \GuzzleHttp\Client(['base_uri' => 'https://accounts.google.com']);

        $response = $client->request('POST', '/o/oauth2/token', [
            'form_params' => [
                "grant_type" => "refresh_token",
                "refresh_token" => $refresh_token,
                "client_id" => GOOGLE_CLIENT_ID,
                "client_secret" => GOOGLE_CLIENT_SECRET,
            ],
        ]);
        $data = (array) json_decode($response->getBody());
        $data['refresh_token'] = $refresh_token;

        $this->update_access_token(json_encode($data));
    }

    private function is_table_empty() {
        $result = $this->database->query("SELECT id FROM google_oauth WHERE provider = 'google'");     
        if(count($result)) {
            return false;
        }
        return true;
    }

    public function get_access_token() {
        $result = $this->database->query("SELECT provider_value FROM google_oauth WHERE provider = 'google'");
        return json_decode($result[0]['provider_value']);
    }

    public function get_refresh_token() {
        $result = $this->get_access_token();
        return $result->refresh_token;
    }

    public function update_access_token($token) {
        if($this->is_table_empty()) {
            $this->database->insert("google_oauth", ["provider, provider_values"], ['google', $token]);
            return;
        }
        $this->database->update("google_oauth", ["provider_value" => $token], "provider", "google");
    }
}
