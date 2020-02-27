<?php
use CRM_Civixero_ExtensionUtil as E;

class CRM_Civixero_Page_XeroAuthorize extends CRM_Core_Page {
  private $authorizeURL = 'https://login.xero.com/identity/connect/authorize';

  private $tokenURL = 'https://identity.xero.com/connect/token';
  
  private $resourceOwnerURL = 'https://api.xero.com/api.xro/2.0/Organisation';
  
  private $connectionsURL = 'https://api.xero.com/connections';

  private $redirectURL;
  
  private $scopes;
  
  private $clientID;

  private $clientSecret;
  
  private $hasValidTokens = FALSE;
  
  private $tenantID;
  
  /**
   * 
   * @var []
   */
  private $accessTokenData;
  
  public $provider;


  private function init() {
    $this->clientID = trim(Civi::settings()->get('xero_client_id'));
    $this->clientSecret = trim(Civi::settings()->get('xero_client_secret'));
    $this->accessTokenData = Civi::settings()->get('xero_access_token');
    $this->tenantID = Civi::settings()->get('xero_tenant_id');
    $this->redirectURL = CRM_Utils_System::url('civicrm/xero/authorize',
      NULL, 
      TRUE,
      NULL,
      FALSE,
      FALSE,
      TRUE
    );
    $this->scopes = [
      // This is what the resource owner is granting the application.
      // It may need revising, depending on the operations being performed.
      'offline_access', // This allows us to obtain a refresh token.
      'accounting.settings', 
      'accounting.transactions',
      'accounting.contacts',
      'accounting.journals.read',
      'accounting.reports.read',
    ];
    $this->provider = new \League\OAuth2\Client\Provider\GenericProvider([
      'clientId' => trim(Civi::settings()->get('xero_client_id')),
      'clientSecret' => trim(Civi::settings()->get('xero_client_secret')),
      'redirectUri' => $this->redirectURL,
      'urlAuthorize' => $this->authorizeURL,
      'urlAccessToken' => $this->tokenURL,
      'urlResourceOwnerDetails' => $this->resourceOwnerURL,
    ]);
    $refresh_token = NULL;
    if (!empty($this->accessTokenData['refresh_token'])) {
      $access_token = new \League\OAuth2\Client\Token\AccessToken($this->accessTokenData);
      $refresh_token = $access_token->getRefreshToken();
    }
    // If we have a refresh token, test it by getting a new access token
    // and use them to get the tenant ID.
    // We may or may not have valid tokens at this point.
    if ($refresh_token) {
      try {
        $newAccessToken = $this->provider->getAccessToken('refresh_token', [
          'refresh_token' => $refresh_token,
        ]);
        // Save the new tokens.
        $refresh_token = $newAccessToken->getRefreshToken();
        if ($refresh_token) {
          $this->accessTokenData = $newAccessToken->jsonSerialize();
          $this->tenantID = $this->getConnectedTenantID($newAccessToken->getToken());
          if ($this->tenantID) {
            Civi::settings()->set('xero_access_token', $this->accessTokenData);
            Civi::settings()->set('xero_tenant_id', $this->tenantID);
            $this->hasValidTokens = TRUE;
          }
        }
      }
      catch (Exception $e) {
        // Expected invalid_grant. Continue to let user authorize.
      }
    }
  }
  

  private function processAuthCode() { 
    // Have we been redirected back from an authorization?
    $code = CRM_Utils_Array::value('code', $_GET, '');
    $state = CRM_Utils_Array::value('state', $_GET, '');
    if ($code) {
      // Check state to mitigate against CSRF attacks.
      if ($state != $this->getState()) {
        throw new Exception('Invalid state.');
      }
      
      // Try to get an access token using the authorization code grant.
      $token = $this->provider->getAccessToken('authorization_code', [
        'code' => $code
      ]);
      // The refresh token and tenant_id
      // are required to get new access tokens without
      // needing the user to authorize again.
      $refresh_token = $token->getRefreshToken();
      $access_token = $token->getToken();
      $success = FALSE;
      if ($access_token && $refresh_token) {
        // The tenant_id is also required.
        $tenant_id = $this->getConnectedTenantID($access_token);
        if ($tenant_id) {
          // Save to Settings.
          Civi::settings()->add([
            'xero_access_token' => $token->jsonSerialize(),
            'xero_tenant_id' => $tenant_id,
            ]
           );
         // Signal success.
          $success = TRUE;
          CRM_Core_Session::setStatus(E::ts('Xero Authorization Successful'));
          // Redirect to clear stale $_GET params.
          CRM_Utils_System::redirect('/' . CRM_Utils_System::currentPath(), 'reset=1');
        }
      }
      if (!$success) {
        CRM_Core_Session::setStatus(E::ts('Xero Authorization Not Successful, try again.'));
        CRM_Core_Error::debug_var('XeroAuthorization Error', [
          'token' => $token->jsonSerialize(), 
          'tenant_id' => $tenant_id,
        ]);
      }
    }
  }
 
  /**
   * Gets the connected Tenant ID.
   * 
   * @param string $access_token
   * @return string|NULL
   */
  private function getConnectedTenantID($access_token) {
    $ch = curl_init();
    $header = [
      "Authorization: Bearer {$access_token}",
      "Content-Type: application/json",
    ];
    $opts =  [
      CURLOPT_HTTPHEADER => $header,
      CURLOPT_URL => $this->connectionsURL,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
    ];
    curl_setopt_array($ch, $opts);
    
    $response = curl_exec($ch);
    if (false === $response) {
      curl_error($ch);
    }
    curl_close($ch);
    $data = json_decode($response);
    $connection = reset($data);
    return !empty($connection->tenantId) ? $connection->tenantId : NULL;
  }

  public function getToken($code) {
    // No longer required use $this->provider->getAccessToken().
   $ch = curl_init();
   $authorization =  base64_encode($this->clientID . ':' . $this->clientSecret);
   $header = array("Authorization: Basic {$authorization}","Content-Type: application/x-www-form-urlencoded");
   $data = array(
     'grant_type' => 'authorization_code',
     'code' => $code,
     'redirect_uri' => $this->redirectURL, 
   );
   $content = http_build_query($data);
   curl_setopt_array($ch, array(
    CURLOPT_HTTPHEADER => $header,
    CURLOPT_URL => $this->tokenURL,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $content,
   ));
   $response = curl_exec($ch);
   curl_close($ch);
    if ($response === false) {
      echo "Failed";
      echo curl_error($curl);
      echo "Failed";
    } elseif (json_decode($response)->error) {
      echo "Error:<br />";
      echo $authorization_code;
      echo $response;
    }
    
    return json_decode($response)->access_token;

  }
  
  /**
   * Gets the URL to authorize with Xero.
   * 
   * @return string
   */
  public function getAuthURL() {
    $options = [
      'scope' => implode(' ', $this->scopes), 
      'state' => $this->getState(), // If empty, the provider will generate one.
    ];    
    $url = $this->provider->getAuthorizationUrl($options);
    // The state is used to verify the response.
    // Store the state generated by the OAuth provider object.
    $this->setState($this->provider->getState());
    return $url;
  }
 
  /**
   * Gets the state used during  authorization.
   * @return string
   */
  protected function getState() {
    return CRM_Core_Session::singleton()->get('oauth2state', 'xero'); 
  }
  
  /**
   * Stores the state used during authorization.
   * 
   * @param string $state
   */
  protected function setState($state = NULL) {
    CRM_Core_Session::singleton()->set('oauth2state', $state, 'xero'); 
  }
  

  public function run() {
    $this->init();
     
    $page_content = '';
    //Check if we have returned from authorization and process data.
    // Do we have a client id
    if (empty($this->clientID) || empty($this->clientSecret)) {
      // Set status
      $page_content = "A Client ID needs to be added in the Xero Settings.";
    }
    else {
      $this->processAuthCode();
      if ($this->hasValidTokens) {
        $status_msg = E::ts("CiviCRM can connect to Xero. You do not need to authorize again at this point.");
        $status_msg .= '<br />' .E::ts('TenantID: ' . $this->tenantID);
        $status_msg .= '<br />' . E::ts('Scopes: %1', ['1' => implode(', ', $this->scopes)]);
      }
      else {
        $status_msg = E::ts('You will need to Authorize with Xero by clicking the link below.');
        
      }
      $page_content = '<p>' . $status_msg . '</p>';
      $url = $this->getAuthURL();
      $page_content .= '<a href="' . $url . '"> Authorize With Xero </a>';
    }
    $this->assign('authorizeLink', $page_content);

    parent::run();
  }

}
