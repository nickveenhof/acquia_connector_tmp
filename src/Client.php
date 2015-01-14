<?php
/**
 * Created by PhpStorm.
 * User: ben.jeavons
 * Date: 2/11/14
 * Time: 7:17 PM
 */

namespace Drupal\acquia_connector;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

class Client {

  /**
   * @todo create specific exceptions?
   *
   */

  /**
   * @var \Guzzle\Http\ClientInterface
   */
  protected $client;

  /**
   * @var array
   */
  protected $headers;

  /**
   * @var string
   */
  protected $server;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * @param ClientInterface $client
   * @param ConfigFactoryInterface $config
   */

  public function __construct(ClientInterface $client, ConfigFactoryInterface $config) {
    $this->client = $client;
    $this->headers = array(
      'Content-Type' => 'application/json',
      'Accept' => 'application/json'
    );
    $this->config = $config->get('acquia_connector.settings');
    $this->server = $this->config->get('spi.server');
    $this->client->setDefaultOption('verify', $this->config->get('spi.ssl_verify'));
  }

  /**
   * Get account settings to use for creating request authorizations.
   *
   * @param string $email Acquia Network account email
   * @param string $password
   *   Plain-text password for Acquia Network account. Will be hashed for
   *   communication.
   * @return array | FALSE
   */
  public function getSubscriptionCredentials($email, $password) {
    $body = array('email' => $email);
    $authenticator = $this->buildAuthenticator($email, array('rpc_version' => ACQUIA_SPI_DATA_VERSION));
    $data = array(
      'body' => $body,
      'authenticator' => $authenticator,
    );

    try {
      // Don't use nspiCall() - key is not defined yet.
      $communication_setting = $this->request('POST', '/agent-api/subscription/communication', $data);
    }
    catch (\Exception $e) {
      return FALSE;
    }
    if($communication_setting) {
      $crypt_pass = new CryptConnector($communication_setting['algorithm'], $password, $communication_setting['hash_setting'], $communication_setting['extra_md5']);
      $pass = $crypt_pass->cryptPass();

      $body = array('email' => $email, 'pass' => $pass, 'rpc_version' => ACQUIA_SPI_DATA_VERSION);
      $authenticator = $this->buildAuthenticator($pass, array('rpc_version' => ACQUIA_SPI_DATA_VERSION));
      $data = array(
        'body' => $body,
        'authenticator' => $authenticator,
      );

      // Don't use nspiCall() - key is not defined yet.
      try {
        $response = $this->request('POST', '/agent-api/subscription/credentials', $data);
        if ($response['body']) {
          return $response['body'];
        }
      }
      catch (\Exception $e) {
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * Get Acquia subscription from Acquia Network.
   *
   * @param string $id Network ID
   * @param string $key Network Key
   * @param array $body
   *   (optional)
   *
   * @return array|false or throw Exception
   * D7: acquia_agent_get_subscription
   */
  public function getSubscription($id, $key, array $body = array()) {
    $body['identifier'] = $id;
    // There is an identifier and key, so attempt communication.
    $subscription = array();
    $subscription['timestamp'] = REQUEST_TIME;

    // Include version number information.
    acquia_connector_load_versions();
    if (IS_ACQUIA_DRUPAL) {
      $params['version']  = ACQUIA_DRUPAL_VERSION;
      $params['series']   = ACQUIA_DRUPAL_SERIES;
      $params['branch']   = ACQUIA_DRUPAL_BRANCH;
      $params['revision'] = ACQUIA_DRUPAL_REVISION;
    }
    // @todo
    // Include Acquia Search module version number.
    if (\Drupal::moduleHandler()->moduleExists('acquia_search')) {
//      foreach (array('acquia_search', 'apachesolr') as $name) {
//        $info = system_get_info('module', $name);
//        // Send the version, or at least the core compatibility as a fallback.
//        $params['search_version'][$name] = isset($info['version']) ? (string)$info['version'] : (string)$info['core'];
//      }
    }
    // @todo
    // Include Acquia Search for Search API module version number.
    if (\Drupal::moduleHandler()->moduleExists('search_api_acquia')) {
//      foreach (array('search_api_acquia', 'search_api', 'search_api_solr') as $name) {
//        $info = system_get_info('module', $name);
//        // Send the version, or at least the core compatibility as a fallback.
//        $params['search_version'][$name] = isset($info['version']) ? (string)$info['version'] : (string)$info['core'];
//      }
    }

    try{
      $response = $this->nspiCall('/agent-api/subscription/' . $id, $body);
      if (!empty($response['result']['authenticator']) && $this->validateResponse($key, $response['result'], $response['authenticator'])) {
        return $subscription + $response['result']['body'];
      }
    }
    catch (\Exception $e) {
      // @todo: Add error message
      dpm($e->getMessage());
    }

    return FALSE;
  }

  /**
   * Get Acquia subscription from Acquia Network.
   *
   * @param string $id Network ID
   * @param string $key Network Key
   * @param array $body
   *   (optional)
   *
   * @return array|false
   */
  public function sendNspi($id, $key, array $body = array()) {
    $body['identifier'] = $id;
    dpm('sendNspi $body: ');  // @todo: remove debug
    dpm($body);               // @todo: remove debug

    try{
      $response = $this->nspiCall('/spi-api/site', $body);
      if (!empty($response['result']['authenticator']) && $this->validateResponse($key, $response['result'], $response['authenticator'])) {
        return $response['result'];
      }
    }
    catch (\Exception $e) {
      // @todo: Add error message
      dpm($e->getMessage());
    }
    return FALSE;
  }

  public function getDefinition($apiEndpoint) {
    try{
      return $this->request('GET', $apiEndpoint, array());
    }
    catch (\Exception $e){}
    return FALSE;
  }

  /**
   * Validate the response authenticator.
   *
   * @param string $key
   * @param array $response
   * @param array $requestAuthenticator
   * @return bool
   */
  protected function validateResponse($key, array $response, array $requestAuthenticator) {
    $responseAuthenticator = $response['authenticator'];
    if (!($requestAuthenticator['nonce'] === $responseAuthenticator['nonce'] && $requestAuthenticator['time'] < $responseAuthenticator['time'])) {
      return FALSE;
    }
    $hash = $this->hash($key, $responseAuthenticator['time'], $responseAuthenticator['nonce'], $response['body']);
    return ($hash === $responseAuthenticator['hash']);
  }

  /**
   * Create and send a request.
   *
   * @param string $method
   * @param string $path
   * @param array $data
   * @return array|false
   * @throws \Exception
   */
  protected function request($method, $path, $data) {
    $uri = $this->server . $path;
    $options = array(
      'headers' => $this->headers,
      'json' => json_encode($data),
    );

    try {
      switch ($method) {
        case 'GET':
          $response = $this->client->get($uri, $options);
          break;
        case 'POST':
          $response = $this->client->post($uri, $options);
          break;
      }
    }
    catch (ClientException $e) {
      $error = $e->getResponse()->json();
      dpm($error);
      if ((!empty($error['error']) || !empty($error['is_error'])) && !empty($error['message']) && !empty($error['code'])) {
        throw new \Exception($error['message'], $error['code']);
      }
      throw new \Exception($e->getMessage(), $e->getCode());
    }
    // @todo support response code
    if (!empty($response)) {
      $body = $response->json();
      if ((!empty($body['error']) || !empty($body['is_error'])) && !empty($body['message']) && !empty($body['code'])) {
        throw new \Exception($body['message'], $body['code']);
      }
      return $body;
    }
    return FALSE;
  }

  /*
   * Build authenticator to sign requests to the Acquia Network
   *
   * @params string $key Secret key to use for signing the request.
   * @params array $params Optional parameters to include.
   *   'identifier' - Network Identifier
   *
   * @return string
   */
  protected function buildAuthenticator($key, $params = array()) {
    $authenticator = array();
    if (isset($params['identifier'])) {
      // Put Network ID in authenticator but do not use in hash.
      $authenticator['identifier'] = $params['identifier'];
      unset($params['identifier']);
    }
    $nonce = $this->getNonce();
    $authenticator['time'] = REQUEST_TIME;
    $authenticator['hash'] = $this->hash($key, REQUEST_TIME, $nonce, $params);
    $authenticator['nonce'] = $nonce;

    return $authenticator;
  }

  /**
   * Calculates a HMAC-SHA1 according to RFC2104 (http://www.ietf.org/rfc/rfc2104.txt).
   *
   * @param string $key
   * @param int $time
   * @param string $nonce
   * @param array $params
   * @return string
   * D7: _acquia_agent_hmac
   */
  protected function hash($key, $time, $nonce, $params = array()) {
    // @todo: should we remove this method for D8?
    if (empty($params['rpc_version']) || $params['rpc_version'] < 2) {
      $string = $time . ':' . $nonce . ':' . $key . ':' . serialize($params);

      return base64_encode(
        pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
        pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) .
        $string)))));
    }
    // @todo: should we remove this method for D8?
    elseif ($params['rpc_version'] == 2) {
      $string = $time . ':' . $nonce . ':' . json_encode($params);
      return sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) . pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) . $string)));
    }

    $string = $time . ':' . $nonce;
    return sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) . pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) . $string)));
  }

  /**
   * Get a random base 64 encoded string.
   *
   * @return string
   */
  protected function getNonce() {
    return Crypt::hashBase64(uniqid(mt_rand(), TRUE) . Crypt::randomBytes(55));
  }

  /**
   * Prepare and send a REST request to Acquia Network with an authenticator.
   *
   * @param string $method
   * @param array $params
   * @param string $key or NULL
   * @return array or throw Exception
   * D7: acquia_agent_call().
   */
  public function nspiCall($method, $params, $key = NULL) {
    dpm('Method called: ' . $method);
    if (empty($key)) {
      $config = \Drupal::config('acquia_connector.settings');
      $key = $config->get('key');
    }
    $params['rpc_version'] = ACQUIA_SPI_DATA_VERSION; // Used in HMAC validation
    // @todo: Remove $_SERVER
    $ip = isset($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : '';
    $host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : '';
    $ssl = \Drupal::request()->isSecure();
    $data = array(
      'authenticator' => $this->buildAuthenticator($key, $params),
      'ip' => $ip,
      'host' => $host,
      'ssl' => $ssl,
      'body' => $params,
    );
    $data['result'] = $this->request('POST', $method, $data);
    return $data;
  }

}
