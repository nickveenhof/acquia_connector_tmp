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

    $communication_setting = $this->request('POST', '/agent-api/subscription/communication', $data);

    if($communication_setting) {
      $crypt_pass = new CryptConnector($communication_setting['algorithm'], $password, $communication_setting['hash_setting'], $communication_setting['extra_md5']);
      $pass = $crypt_pass->cryptPass();

      $body = array('email' => $email, 'pass' => $pass, 'rpc_version' => ACQUIA_SPI_DATA_VERSION);
      $authenticator = $this->buildAuthenticator($pass, array('rpc_version' => ACQUIA_SPI_DATA_VERSION));
      $data = array(
        'body' => $body,
        'authenticator' => $authenticator,
      );

      $response = $this->request('POST', '/agent-api/subscription/credentials', $data);
      if($response['body']){
        dpm('getSubscriptionCredentials $response: ');
        dpm($response);
        return $response['body'];
      }
    }
    return FALSE;
  }

  /**
   * Validate Network ID/Key pair to Acquia Network.
   *
   * @param string $id Network ID
   * @param string $key Network Key
   * @return bool
   */
  public function validateCredentials($id, $key) {
    try {
      $this->getSubscription($id, $key);
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
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
   * D7: acquia_agent_get_subscription
   */
  public function getSubscription($id, $key, array $body = array()) {
    $body += array('identifier' => $id, 'rpc_version' => ACQUIA_SPI_DATA_VERSION);
    $authenticator =  $this->buildAuthenticator($key, $body);
    $data = array(
      'body' => $body,
      'authenticator' => $authenticator,
    );

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
      $response = $this->request('POST', '/agent-api/subscription/' . $id, $data);
      if ($this->validateResponse($key, $response, $authenticator)) {
        return $subscription + $response['body'];
      }
    }
    catch (\Exception $e){}
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
    $authenticator =  $this->buildAuthenticator($key, $body);
    dpm('sendNspi $authenticator: ');
    dpm($authenticator);
    $ip = isset($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : '';
    $host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : '';
    $ssl = \Drupal::request()->isSecure();
    $data = array(
      'body' => $body,
      'authenticator' => $authenticator,
      'ip' => $ip,
      'host' => $host,
      'ssl' => $ssl,
    );
    dpm('sendNspi $data: ');
    dpm($data);

    try{
      $response = $this->request('POST', '/spi-api/site', $data);
      if ($this->validateResponse($key, $response, $authenticator)) {
        return $response;
      }
    }
    catch (\Exception $e){}
    return FALSE;
  }

  public function getDefinition($apiEndpoint) {
    try{
      $response = $this->request('GET', $apiEndpoint, array());
      return $response;
    }
    catch (\Exception $e){
    }
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
    switch ($method) {
      case 'GET':
        try {
          $options = array(
            'headers' => $this->headers,
            'json' => json_encode($data),
          );

          $response = $this->client->get($uri, $options);
        }
        catch (ClientException $e) {
          drupal_set_message($e->getMessage(), 'error');
        }
        break;
      case 'POST':
        try {
          $options = array(
            'headers' => $this->headers,
            'json' => json_encode($data),
          );

          $response = $this->client->post($uri, $options);

        }
        catch (ClientException $e) {
          drupal_set_message($e->getMessage(), 'error');
        }
        break;
    }
    // @todo support response code
    if (!empty($response)) {
      $body = $response->json();
      if (!empty($body['error'])) {
        drupal_set_message($body['code'] . ' : ' .$body['message'], 'error');
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
   * Creates an authenticator based on xmlrpc params and a HMAC-SHA1.
   * D7: _acquia_agent_authenticator().
   */
//  public function _acquia_agent_authenticator($params = array(), $identifier = NULL, $key = NULL) {
//    if (empty($identifier)) {
//      $identifier = acquia_agent_settings('acquia_identifier');
//    }
//    if (empty($key)) {
//      $key = acquia_agent_settings('acquia_key');
//    }
//    $time = REQUEST_TIME;
//    $nonce = base64_encode(hash('sha256', drupal_random_bytes(55), TRUE));
//    $authenticator['identifier'] = $identifier;
//    $authenticator['time'] = $time;
//    $authenticator['hash'] = _acquia_agent_hmac($key, $time, $nonce, $params);
//    $authenticator['nonce'] = $nonce;
//    return $authenticator;
//  }

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
   * D7: acquia_agent_call().
   */
  public function acquia_agent_call($method, $params, $key = NULL) {
//    $acquia_network_address = self::getNetworkAddress($acquia_network_address);
    if (empty($key)) {
      $config = \Drupal::config('acquia_connector.settings');
      $key = $config->get('key');
    }
    $params['rpc_version'] = ACQUIA_SPI_DATA_VERSION; // Used in HMAC validation
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
//    $data['result'] = _acquia_agent_request($acquia_network_address, $method, $data);
    return $data;
  }

  /**
   * Send a XML-RPC request.
   *
   * This function should never be called directly - use acquia_agent_call().
   * D7: _acquia_agent_request().
   */
//  function _acquia_agent_request($url, $method, $data) {
//    $ctx = acquia_agent_stream_context_create($url);
//    if (!$ctx) {
//      // TODO: what's a meaningful fault code?
//      xmlrpc_error(-1, t('SSL is not supported or setup failed'));
//      $result = FALSE;
//    }
//    else {
//      $result = xmlrpc($url, array($method => array($data)), array('context' => $ctx));
//    }
//    if ($errno = xmlrpc_errno()) {
//      $acquia_debug = \Drupal::config('acquia_agent')->get('debug');
//      if ($acquia_debug) {
//        watchdog('acquia agent', '@message (@errno): %server - %method - <pre>@data</pre>', array('@message' => xmlrpc_error_msg(), '@errno' => xmlrpc_errno(), '%server' => $url, '%method' => $method, '@data' => var_export($data, TRUE)), WATCHDOG_ERROR);
//      }
//      else {
//        watchdog('acquia agent', '@message (@errno): %server - %method', array('@message' => xmlrpc_error_msg(), '@errno' => xmlrpc_errno(), '%server' => $url, '%method' => $method), WATCHDOG_ERROR);
//      }
//      $result = FALSE;
//    }
//    return $result;
//  }

  /**
   * Helper function to build the xmlrpc target address.
   * D7: acquia_agent_network_address().
   */
//  public function getNetworkAddress($acquia_network_address = NULL) {
//    $config = \Drupal::config('acquia_connector.settings');
//    if (empty($acquia_network_address)) {
//      $acquia_network_address = $config->get('spi.server');
//    }
//    // Strip protocol (scheme) from Network address
//    $uri = parse_url($acquia_network_address);
//    if (isset($uri['host'])) {
//      $acquia_network_address = $uri['host'];
//    }
//    $acquia_network_address .= isset($uri['port']) ? ':' . $uri['port'] : '';
//    $acquia_network_address .= (isset($uri['path']) && isset($uri['host'])) ? $uri['path'] : '';
//    // Add a scheme based on PHP's capacity.
//    if (in_array('ssl', stream_get_transports(), TRUE) && !defined('ACQUIA_DEVELOPMENT_NOSSL')) {
//      // OpenSSL is available in PHP
//      $acquia_network_address = 'https://' . $acquia_network_address;
//    }
//    else {
//      $acquia_network_address = 'http://' . $acquia_network_address;
//    }
//    return $acquia_network_address;
//  }

}
