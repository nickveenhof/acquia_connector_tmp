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

  public function __construct(ClientInterface $client, ConfigFactoryInterface $config) {
    $this->client = $client;
    $this->headers = array(
      'Content-Type' => 'application/json',
      'Accept' => 'application/json'

    );
    $this->config = $config->get('acquia_connector.settings');
    $this->server = $this->config->get('network_address');
  }

  /**
   * Get account settings to use for creating request authorizations.
   *
   * @param string $email Acquia Network account email
   * @param string $password
   *   Plain-text password for Acquia Network account. Will be hashed for
   *   communication.
   */
  public function getSubscriptionCredentials($email, $password) {
    $body = array('email' => $email, 'pass' => $password);
    $authenticator = $this->buildAuthenticator($password, $body);
    $data = array(
      'body' => $body,
      'authenticator' => $authenticator,
    );
    $response = $this->request('POST', '/agent-api/subscription/credentials', $data);
    //if ($this->validateResponse($password, $response, $authenticator)) {
      return $response['body'];
    //}
    //return FALSE;
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
   */
  public function getSubscription($id, $key, array $body = array()) {
    $body += array('identifier' => $id);
    $authenticator =  $this->buildAuthenticator($key, $body);
    $data = array(
      'body' => $body,
      'authenticator' => $authenticator,
    );
    try{
      $response = $this->request('POST', '/agent-api/subscription/' . $id, $data);
      if ($this->validateResponse($key, $response, $authenticator)) {
        return $response['body'];
      }
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
    switch ($method) {
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
   * Calculates a HMAC-SHA1 according to RFC2104 (http://www.ietf.org/rfc/rfc2104.txt).
   *
   * @param string $key
   * @param int $time
   * @param string $nonce
   * @param array $params
   * @return string
   */
  protected function hash($key, $time, $nonce, $params = array()) {

    if (empty($params['rpc_version']) || $params['rpc_version'] < 2) {
      $string = $time . ':' . $nonce . ':' . $key . ':' . serialize($params);

      return base64_encode(
        pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
        pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) .
        $string)))));
    }
    elseif ($params['rpc_version'] == 2) {
      $string = $time . ':' . $nonce . ':' . json_encode($params);
      return sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) . pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) . $string)));
    }
    else {
      $string = $time . ':' . $nonce;
      return sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) . pack("H*", sha1((str_pad($key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) . $string)));
    }
  }

  /**
   * Get a random base 64 encoded string.
   *
   * @return string
   */
  protected function getNonce() {
    return Crypt::hashBase64(uniqid(mt_rand(), TRUE) . Crypt::randomBytes(55));
  }
}
