<?php
namespace Atte\Api;

class GoogleOAuth
{
    private $MsaDB;

    public function __construct(){
        $this -> MsaDB = \Atte\DB\MsaDB::getInstance();
    }

    public function regenerateToken(){
        // The original config-google-sheets.php eagerly instantiates
        // Hybridauth\Provider\Google which calls session_start() — that
        // breaks CLI after any stdout output. The Google OAuth refresh
        // endpoint only needs the client_id/secret, so we read them from
        // $_ENV (Dotenv has already loaded them by the time we get here).
        if (!defined('GOOGLE_CLIENT_ID')) {
            define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
        }
        if (!defined('GOOGLE_CLIENT_SECRET')) {
            define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
        }

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
        $result = $this->MsaDB->query("SELECT id FROM google_oauth WHERE provider = 'google'");     
        if(count($result)) {
            return false;
        }
        return true;
    }

    public function get_access_token() {
        $result = $this->MsaDB->query("SELECT provider_value FROM google_oauth WHERE provider = 'google'");
        return json_decode($result[0]['provider_value']);
    }

    public function get_refresh_token() {
        $result = $this->get_access_token();
        return $result->refresh_token;
    }

    public function update_access_token($token) {
        if($this->is_table_empty()) {
            $this->MsaDB->insert("google_oauth", ["provider", "provider_values"], ['google', $token]);
            return;
        }
        $this->MsaDB->update("google_oauth", ["provider_value" => $token], "provider", "google");
    }
}
