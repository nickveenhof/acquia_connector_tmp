<?php
// $Id: Acquia_Search_Service.php 5183 2010-01-08 04:28:12Z buildbot $

/**
 * Starting point for the Solr API. Represents a Solr server resource and has
 * methods for pinging, adding, deleting, committing, optimizing and searching.
 *
 */
class AcquiaSearchService extends DrupalApacheSolrService {

  protected function add_request_id(&$url) {
    $id = uniqid();
    if (!stristr($url,'?')) {
      $url .= "?";
    }
    else {
      $url .= "&";
    }
    $url .= 'request_id=' . $id;
    return $id;
  }
  /**
   * Central method for making a get operation against this Solr Server
   *
   * @see Drupal_Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawGet($url, $timeout = FALSE) {
    $id = $this->add_request_id($url);
    list($cookie, $nonce) = acquia_search_auth_cookie($url);
    if (empty($cookie)) {
      throw new Exception('Invalid authentication string - subscription keys expired or missing.');
    }
    $request_headers = array(
      'Cookie' => $cookie,
      'User-Agent' => 'acquia_search/'. variable_get('acquia_search_version', '7.x'),
    );
    list ($data, $headers) = $this->_makeHttpRequest($url, 'GET', $request_headers, '', $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $hmac = acquia_search_extract_hmac($headers);
    $code = (int) $response->getHttpStatus();
    if ($code != 200) {
      $message = $response->getHttpStatusMessage() . "\n request ID: $id \n";
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $message .= $response->getRawResponse();
      }
      throw new Exception('"' . $code . '" Status: ' . $message);
    }
    elseif (!acquia_search_valid_response($hmac, $nonce, $data)) {
      throw new Exception('Authentication of search content failed url: '. $url);
    }
    return $response;
  }

  /**
   * Central method for making a post operation against this Solr Server
   *
   * @see Drupal_Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawPost($url, $rawPost, $timeout = FALSE, $contentType = 'text/xml; charset=UTF-8')  {
    if (variable_get('apachesolr_read_only', 0)) {
      throw new Exception('Operating in read-only mode; updates are disabled.');
    }
    $id = $this->add_request_id($url);
    list($cookie, $nonce) = acquia_search_auth_cookie($url, $rawPost);
    if (empty($cookie)) {
      throw new Exception('Invalid authentication string - subscription keys expired or missing.');
    }
    $request_headers = array(
      'Content-Type' => $contentType,
      'Cookie' => $cookie,
      'User-Agent' => 'acquia_search/'. variable_get('acquia_search_version', '7.x'),
    );
    list ($data, $headers) = $this->_makeHttpRequest($url, 'POST', $request_headers, $rawPost, $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $code = (int) $response->getHttpStatus();
    if ($code != 200) {
      $message = $response->getHttpStatusMessage() . "\n request ID: $id \n";
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $message .= $response->getRawResponse();
      }
      throw new Exception('"' . $code . '" Status: ' . $message);
    }
    return $response;
  }

  /**
   * Make a request to a servlet (a path) that's not a standard path.
   *
   * @param string $servlet
   *   A path to be added to the base Solr path. e.g. 'extract/tika'
   *
   * @param array $params
   *   Any request parameters when constructing the URL.
   *
   * @param string $method
   *   'GET', 'POST', 'PUT', or 'HEAD'.
   *
   * @param array $request_headers
   *   Keyed array of header names and values.  Should include 'Content-Type'
   *   for POST or PUT.
   *
   * @param string $rawPost
   *   Must be an empty string unless method is POST or PUT.
   *
   * @param float $timeout
   *   Read timeout in seconds or FALSE.
   *
   * @return 
   *  Apache_Solr_Response object
   */
  public function makeServletRequest($servlet, $params = array(), $method = 'GET', $request_headers = array(), $rawPost = '', $timeout = FALSE) {
    if ($method == 'GET' || $method == 'HEAD') {
      // Make sure we are not sending a request body.
      $rawPost = '';
    }
    // Add default params.
    $params += array(
      'wt' => self::SOLR_WRITER,
    );

    $url = $this->_constructUrl($servlet, $params);
    $id = $this->add_request_id($url);
    // We assume we only authenticate the URL for other servlets.
    list($cookie, $nonce) = acquia_search_auth_cookie($url);
    if (empty($cookie)) {
      throw new Exception('Invalid authentication string - subscription keys expired or missing.');
    }
    $request_headers += array(
      'Cookie' => $cookie,
      'User-Agent' => 'acquia_search/'. variable_get('acquia_search_version', '7.x'),
    );
    list ($data, $headers) = $this->_makeHttpRequest($url, $method, $request_headers, $rawPost, $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $hmac = acquia_search_extract_hmac($headers);
    $code = (int) $response->getHttpStatus();
    if ($code != 200) {
      $message = $response->getHttpStatusMessage();
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $message .= $response->getRawResponse();
      }
      throw new Exception('"' . $code . '" Status: ' . $message);
    }
    elseif (!acquia_search_valid_response($hmac, $nonce, $data)) {
      throw new Exception('Authentication of search content failed url: '. $url);
    }
    return $response;
  }

  /**
   * Send an optimize command.
   *
   * We want to control the schedule of optimize commands ourselves,
   * so do a method override to make ->optimize() a no-op.
   *
   * @see Drupal_Apache_Solr_Service::optimize()
   */
  public function optimize($waitFlush = true, $waitSearcher = true, $timeout = 3600) {
    return TRUE;
  }

  protected function _makeHttpRequest($url, $method = 'GET', $headers = array(), $content = '', $timeout = FALSE) {
    // Set a response timeout
    if ($timeout) {
      $default_socket_timeout = ini_set('default_socket_timeout', $timeout);
    }

    $ctx = acquia_agent_stream_context_create($url, 'acquia_search');
    if (!$ctx) {
      throw new Exception(t("Could not create stream context"));
    }

    $result = drupal_http_request($url, array(
      'headers' => $headers, 
      'method' => $method, 
      'data' => $content, 
      'context' => $ctx
    ));

    // Restore the response timeout
    if ($timeout) {
      ini_set('default_socket_timeout', $default_socket_timeout);
    }

    if (!isset($result->code) || $result->code < 0) {
      $result->code = '0';
      $result->status_message = 'Request failed';
    }

    if (!isset($result->status_message)) {
      $result->status_message = '';
    }

    if (isset($result->error)) {
      $responses[0] .= ': ' . check_plain($result->error);
    }

    if (!isset($result->data)) {
      $result->data = '';
    }

    if (!isset($result->error)) {
      $result->error = '';
    }

    $headers[] = "{$result->protocol} {$result->code} {$result->status_message}. {$result->error}";
    if (isset($result->headers)) {
      foreach ($result->headers as $name => $value) {
        $headers[] = "$name: $value";
      }
    }
    return array($result->data, $headers);
  }

}
