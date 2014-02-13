<?php
/**
 * Created by PhpStorm.
 * User: ben.jeavons
 * Date: 2/11/14
 * Time: 7:17 PM
 */

namespace Drupal\acquia_connector;

use Drupal\Component\Utility\Crypt;
use Guzzle\Http\ClientInterface;

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

  public function __construct(ClientInterface $client) {
    $this->client = $client;
    $this->headers = array(
      'Content-Type' => 'application/json',
      'Accept' => 'application/json'

    );
    // @todo get from config
    $this->server = 'http://nspi.acquia.local/agent-api/';
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
    // @todo get token from authentication with Accounts
    try {
      $settings = $this->getAccountSettings($email);
      // Hash $password according to account salt and algorithm.
      //$hash = $this->passwordCrypt($password, $settings);
    }
    catch (\Exception $e) {

    }
    $body = array('email' => $email);
    $data = array(
      'body' => $body,
      // Build authenticator based on account email.
      'authenticator' => $this->buildAuthenticator($password, $body),
    );
    return $this->request('POST', 'subscription-credentials', $data);
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
   * @return array|false
   */
  public function getSubscription($id, $key) {
    $body = array('identifier' => $id);
    $authenticator =  $this->buildAuthenticator($key, $body);
    $data = array(
      'body' => $body,
      'authenticator' => $authenticator,
    );
    $response = $this->request('POST', 'subscription/' . $id, $data);
    if ($this->validateResponse($key, $response, $authenticator)) {
      return $response['body'];
    }
    return FALSE;
  }

  /**
   * Get account settings to use for creating request authorizations.
   *
   * @param string $email
   */
  protected function getAccountSettings($email) {
    $body = array('email' => $email);
    $data = array(
      'body' => $body,
      // Build authenticator based on account email.
      'authenticator' => $this->buildAuthenticator($email, $body),
    );
    return $this->request('POST', 'account-settings', $data);
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
        $request = $this->client->post($uri, array(), json_encode($data));
        // Guzzle requires resetting headers??? @todo
        $request->setHeaders($this->headers);
        $response = $request->send();
    }
    // @todo support response code
    if (!empty($response)) {
      $body = $response->json();
      if (!empty($body['error'])) {
        throw new \Exception($body['message'], $body['code']);

      }
      return $body;
    }
    return FALSE;
  }

  /**
   * @param string $algo
   * @param string $password
   * @param string $setting
   * @return string
   */
  protected function passwordCrypt($algo, $password, $setting) {
    // The first 12 characters of an existing hash are its setting string.
    $setting = substr($setting, 0, 12);

    if ($setting[0] != '$' || $setting[2] != '$') {
      return FALSE;
    }
    $salt = substr($setting, 4, 8);
    // Hashes must have an 8 character salt.
    if (strlen($salt) != 8) {
      return FALSE;
    }

    // Convert the base 2 logarithm into an integer.
    $count = 1 << $count_log2;

    // We rely on the hash() function being available in PHP 5.2+.
    $hash = hash($algo, $salt . $password, TRUE);
    do {
      $hash = hash($algo, $hash . $password, TRUE);
    } while (--$count);

    $len = strlen($hash);
    $output =  $setting . $this->base64Encode($hash, $len);
    // $this->base64Encode() of a 16 byte MD5 will always be 22 characters.
    // $this->base64Encode() of a 64 byte sha512 will always be 86 characters.
    $expected = 12 + ceil((8 * $len) / 6);
    return (strlen($output) == $expected) ? substr($output, 0, 55) : FALSE;
  }

  /**
   * Encodes bytes into printable base 64 using the *nix standard from crypt().
   *
   * @param String $input
   *   The string containing bytes to encode.
   * @param Integer $count
   *   The number of characters (bytes) to encode.
   *
   * @return String
   *   Encoded string
   */
  protected function base64Encode($input, $count) {
    $output = '';
    $i = 0;
    do {
      $value = ord($input[$i++]);
      $output .= static::$ITOA64[$value & 0x3f];
      if ($i < $count) {
        $value |= ord($input[$i]) << 8;
      }
      $output .= static::$ITOA64[($value >> 6) & 0x3f];
      if ($i++ >= $count) {
        break;
      }
      if ($i < $count) {
        $value |= ord($input[$i]) << 16;
      }
      $output .= static::$ITOA64[($value >> 12) & 0x3f];
      if ($i++ >= $count) {
        break;
      }
      $output .= static::$ITOA64[($value >> 18) & 0x3f];
    } while ($i < $count);

    return $output;
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