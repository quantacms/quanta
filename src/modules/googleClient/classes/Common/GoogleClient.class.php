<?php
namespace Quanta\Common;

use Google_Client; // Import the Google_Client class
use Google_Service_Oauth2; // Import the Google_Service_Oauth2 class
use Google_Service_Docs;

/**
 * Class GoogleClient
 */
class GoogleClient{

    const GENERATE_GOOGLE_DOC_PATH = "generate-google-doc";

    public $client = NULL;
    public $service = NULL;


    public function __construct(){
        // Set the Google API credentials
        $this->client = new Google_Client();
        $this->client->setClientId('552368569718-f8po1fm2hgcjpkdcoub2atjfe6d6bu7u.apps.googleusercontent.com');
        $this->client->setClientSecret('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');
        $this->client->addScope(Google_Service_Docs::DOCUMENTS);
        $this->client->setRedirectUri('http://localhost/google-auth-callback');
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');
        if(isset($_SESSION['google_access_token'])){
            $this->client->setAccessToken($_SESSION['google_access_token']);
            $this->refreshAccessToken();

        }
    }

   
    /**
     * Check if the google access token stored in the session
     */
    public function checkSession(){

        if (!isset($_SESSION['google_access_token']) || $_SESSION['google_access_token'] === null) {
            $authUrl = $this->client->createAuthUrl();
            \Quanta\Common\API::redirect(filter_var($authUrl, FILTER_SANITIZE_URL));
        }
    }


    /**
     * Set google_access_token in the session.
     * @param String $code
     */
    public function setAccessToken($code){
        // Exchange authorization code for an access token
        $this->client->authenticate($code);
        $_SESSION['google_access_token'] = $this->client->getAccessToken();
    }

    /**
     * Refresh the access token
     * @param String $code
     */
    public function refreshAccessToken(){
    // Refresh the token if it's expired
        if ($this->client->isAccessTokenExpired()) {
            $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            $_SESSION['access_token'] = $this->client->getAccessToken();
        }
    }

}
