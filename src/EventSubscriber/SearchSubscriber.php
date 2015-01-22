<?php

namespace Drupal\acquia_connector\EventSubscriber;

use Solarium\Core\Event\Events;
use Solarium\Core\Plugin\Plugin;
use Drupal\Component\Utility\Crypt;
use Symfony\Component\EventDispatcher\Event;

class SearchSubscriber extends Plugin {

  protected $client;
  protected $derived_key = [];
  protected $nonce = '';
  protected $uri = '';

  public function initPlugin($client, $options) {
    $this->client = $client;
    $dispatcher = $this->client->getEventDispatcher();
    $dispatcher->addListener(Events::PRE_EXECUTE_REQUEST, array($this, 'preExecuteRequest'));
    $dispatcher->addListener(Events::POST_EXECUTE_REQUEST, array($this, 'postExecuteRequest'));
  }

  /**
   * Build Acquia Solr Search Authenticator.
   *
   * @param PreExecuteRequestEvent $event
   */
  public function preExecuteRequest($event) {
    $request = $event->getRequest();
    $this->request = $request;
    $request->addParam('request_id', uniqid(), TRUE);
    $endpoint = $this->client->getEndpoint();
    $this->uri = $endpoint->getBaseUri() . $request->getUri();

    $this->nonce = Crypt::randomBytesBase64(24);
    $string = $request->getRawData();
    if (!$string) {
      $parsed_url = parse_url($this->uri);
      $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
      $query = isset($parsed_url['query']) ? '?'. $parsed_url['query'] : '';
      $string = $path . $query; // For pings only.
    }

    $cookie = $this->acquia_search_authenticator($string, $this->nonce);
    $request->addHeader('Cookie: ' . $cookie);
    $request->addHeader('User-Agent: ' . 'acquia_search/'. \Drupal::VERSION);
  }

  /**
   * Validate response.
   *
   * @param PostExecuteRequestEvent $event
   */
  public function postExecuteRequest($event) {
    $this->authenticateResponse($event->getResponse(), $this->nonce, $this->uri);
  }

  /**
   * Validate the hmac for the response body.
   *
   * @return Solarium\Core\Client\Response
   * @throws \Exception
   */
  protected function authenticateResponse($response, $nonce, $url) {
    $hmac = $this->acquia_search_extract_hmac($response->getHeaders());
    if (!$this->acquia_search_valid_response($hmac, $nonce, $response->getBody())) {
      throw new \Exception('Authentication of search content failed url: '. $url);
    }
    return $response;
  }

  /**
   * Validate the authenticity of returned data using a nonce and HMAC-SHA1.
   *
   * @return bool
   */
  public function acquia_search_valid_response($hmac, $nonce, $string, $derived_key = NULL, $env_id = NULL) {
    if (empty($derived_key)) {
      $derived_key = $this->_acquia_search_derived_key($env_id);
    }
    return $hmac == hash_hmac('sha1', $nonce . $string, $derived_key);
  }

  /**
   * Look in the headers and get the hmac_digest out
   *
   * @return string hmac_digest
   */
  public function acquia_search_extract_hmac($headers) {
    $reg = array();
    if (is_array($headers)) {
      foreach ($headers as $value) {
        if (stristr($value, 'pragma') && preg_match("/hmac_digest=([^;]+);/i", $value, $reg)) {
          return trim($reg[1]);
        }
      }
    }
    return '';
  }



//  /**
//   * Modify a solr base url and construct a hmac authenticator cookie.
//   *
//   * @param $url
//   *  The solr url beng requested - passed by reference and may be altered.
//   * @param $string
//   *  A string - the data to be authenticated, or empty to just use the path
//   *  and query from the url to build the authenticator.
//   * @param $derived_key
//   *  Optional string to supply the derived key.
//   *
//   * @return
//   *  An array containing the string to be added as the content of the
//   *  Cookie header to the request and the nonce.
//   */
//  public function acquia_search_auth_cookie($url, $string = '', $derived_key = NULL, $env_id = NULL) {
//    $uri = parse_url($url);
//
//    // Add a scheme - should always be https if available.
//    if (in_array('ssl', stream_get_transports(), TRUE) && !defined('ACQUIA_DEVELOPMENT_NOSSL')) {
//      $scheme = 'https://';
//      $port = '';
//    }
//    else {
//      $scheme = 'http://';
//      $port = (isset($uri['port']) && $uri['port'] != 80) ? ':'. $uri['port'] : '';
//    }
//    $path = isset($uri['path']) ? $uri['path'] : '/';
//    $query = isset($uri['query']) ? '?'. $uri['query'] : '';
//    $url = $scheme . $uri['host'] . $port . $path . $query;
//
//    // 32 character nonce.
//    $nonce = base64_encode(drupal_random_bytes(24));
//
//    if ($string) {
//      $auth_header = acquia_search_authenticator($string, $nonce, $derived_key, $env_id);
//    }
//    else {
//      $auth_header = acquia_search_authenticator($path . $query, $nonce, $derived_key, $env_id);
//    }
//    return array($auth_header, $nonce);
//  }

  /**
   * Get the derived key for the solr hmac using the information shared with acquia.com.
   */
  public function _acquia_search_derived_key($env_id = NULL) {
    if (empty($env_id)) {
      $env_id = $this->client->getEndpoint()->getKey();
//      $env_id = 'acquia_search_server_1';
    }
    if (!isset($this->derived_key[$env_id])) {
      // If we set an explicit environment, check if this needs to overridden
      // Use the default
//      $identifier = acquia_agent_settings('acquia_identifier');
      $identifier = \Drupal::config('acquia_connector.settings')->get('identifier');
//      $key = acquia_agent_settings('acquia_key');
      $key = \Drupal::config('acquia_connector.settings')->get('key');
      // See if we need to overwrite these values
      if ($env_id) {
        // Load the explicit environment and a manually set search key.
//        if ($search_key = apachesolr_environment_variable_get($env_id, 'acquia_search_key')) {
//          $this->derived_key[$env_id] = $search_key;
//        }
      }
      // In any case, this is equal for all subscriptions. Also
      // even if the search sub is different, the main subscription should be
      // active
      $derived_key_salt = $this->acquia_search_derived_key_salt();

      // We use a salt from acquia.com in key derivation since this is a shared
      // value that we could change on the AN side if needed to force any
      // or all clients to use a new derived key.  We also use a string
      // ('solr') specific to the service, since we want each service using a
      // derived key to have a separate one.
      if (empty($derived_key_salt) || empty($key) || empty($identifier)) {
        // Expired or invalid subscription - don't continue.
        $this->derived_key[$env_id] = '';
      }
      elseif (!isset($derived_key[$env_id])) {
        $this->derived_key[$env_id] = $this->_acquia_search_create_derived_key($derived_key_salt, $identifier, $key);
      }
    }

    return $this->derived_key[$env_id];
  }

  /**
   * Returns the subscription's salt used to generate the derived key.
   *
   * The salt is stored in a system variable so that this module can continue
   * connecting to Acquia Search even when the subscription data is not available.
   * The most common reason for subscription data being unavailable is a failed
   * heartbeat connection to rpc.acquia.com.
   *
   * Acquia Connector versions <= 7.x-2.7 pulled the derived key salt directly
   * from the subscription data. In order to allow for seamless upgrades, this
   * function checks whether the system variable exists and sets it with the data
   * in the subscription if it doesn't.
   *
   * @return string
   *   The derived key salt.
   *
   * @see http://drupal.org/node/1784114
   */
  public function acquia_search_derived_key_salt() {
//    $salt = variable_get('acquia_search_derived_key_salt', '');
    $salt = \Drupal::config('acquia_connector.settings')->get('search.derived_key_salt');
    if (!$salt) {
      // If the variable doesn't exist, set it using the subscription data.
      $subscription = \Drupal::config('acquia_connector.settings')->get('subscription_data');
//      $subscription = acquia_agent_settings('acquia_subscription_data');
      if (isset($subscription['derived_key_salt'])) {
        \Drupal::config('acquia_connector.settings')->set('search.derived_key_salt', $subscription['derived_key_salt'])->save();
//        variable_set('acquia_search_derived_key_salt', $subscription['derived_key_salt']);
        $salt = $subscription['derived_key_salt'];
      }
    }
    return $salt;
  }

  /**
   * Derive a key for the solr hmac using a salt, id and key.
   */
  public function _acquia_search_create_derived_key($salt, $id, $key) {
    $derivation_string = $id . 'solr' . $salt;
    return hash_hmac('sha1', str_pad($derivation_string, 80, $derivation_string), $key);
  }
  /**
   * Creates an authenticator based on a data string and HMAC-SHA1.
   */
  public function acquia_search_authenticator($string, $nonce, $derived_key = NULL, $env_id = NULL) {
    if (empty($derived_key)) {
      $derived_key = $this->_acquia_search_derived_key($env_id);
    }
    if (empty($derived_key)) {
      // Expired or invalid subscription - don't continue.
      return '';
    }
    else {
      $time = REQUEST_TIME;
      return 'acquia_solr_time=' . $time . '; acquia_solr_nonce=' . $nonce . '; acquia_solr_hmac=' . hash_hmac('sha1', $time . $nonce . $string, $derived_key) . ';';
    }
  }

}
