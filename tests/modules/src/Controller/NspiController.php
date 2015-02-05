<?php
/**
 * @file
 * Test endpoint for Acquia Connector.
 */

namespace Drupal\acquia_connector_test\Controller;

use Drupal\Core\Access\AccessInterface;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\acquia_connector\Client;
use Drupal\acquia_connector\CryptConnector;

/**
 * Class NspiController
 * @package Drupal\acquia_connector_test\Controller
 */
class NspiController extends ControllerBase {

  protected $data = array();

  const ACQTEST_SUBSCRIPTION_NOT_FOUND = 1000;
  const ACQTEST_SUBSCRIPTION_KEY_MISMATCH = 1100;
  const ACQTEST_SUBSCRIPTION_EXPIRED = 1200;
  const ACQTEST_SUBSCRIPTION_REPLAY_ATTACK = 1300;
  const ACQTEST_SUBSCRIPTION_KEY_NOT_FOUND = 1400;
  const ACQTEST_SUBSCRIPTION_MESSAGE_FUTURE = 1500;
  const ACQTEST_SUBSCRIPTION_MESSAGE_EXPIRED = 1600;
  const ACQTEST_SUBSCRIPTION_MESSAGE_INVALID = 700;
  const ACQTEST_SUBSCRIPTION_VALIDATION_ERROR = 1800;
  const ACQTEST_SUBSCRIPTION_SITE_NOT_FOUND = 1900;
  const ACQTEST_SUBSCRIPTION_PROVISION_ERROR = 9000;
  const ACQTEST_SUBSCRIPTION_MESSAGE_LIFETIME = 900; //15*60
  const ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE = 503;
  const ACQTEST_EMAIL = 'TEST_networkuser@example.com';
  const ACQTEST_PASS = 'TEST_password';
  const ACQTEST_ID = 'TEST_AcquiaConnectorTestID';
  const ACQTEST_KEY = 'TEST_AcquiaConnectorTestKey';
  const ACQTEST_ERROR_ID = 'TEST_AcquiaConnectorTestIDErr';
  const ACQTEST_ERROR_KEY = 'TEST_AcquiaConnectorTestKeyErr';
  const ACQTEST_EXPIRED_ID = 'TEST_AcquiaConnectorTestIDExp';
  const ACQTEST_EXPIRED_KEY = 'TEST_AcquiaConnectorTestKeyExp';
  const ACQTEST_503_ID = 'TEST_AcquiaConnectorTestID503';
  const ACQTEST_503_KEY = 'TEST_AcquiaConnectorTestKey503';

  public function nspiUpdate(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    $fields = array(
      'time' => 'is_numeric',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );
    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return new JsonResponse($result);
    }
    if (!empty($data['authenticator']['identifier'])) {
      if ($data['authenticator']['identifier'] != self::ACQTEST_ID && $data['authenticator']['identifier'] != self::ACQTEST_ERROR_ID) {
        return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Subscription not found')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
      }
      if ($data['authenticator']['identifier'] == self::ACQTEST_ERROR_ID) {
        return new JsonResponse(FALSE);
      }
      else {
        $result = $this->validateAuthenticator($data);
        // Needs for update definition
        $data['body']['spi_def_update'] = TRUE;
        $spi_data = $data['body'];
        $result['body'] = array('spi_data_received' => TRUE);
        if (isset($spi_data['spi_def_update'])) {
          $result['body']['update_spi_definition'] = TRUE;
        }

        // Reflect send_method as nspi_messages if set.
        if (isset($spi_data['send_method'])) {
          $result['body']['nspi_messages'][] = $spi_data['send_method'];
        }
        $result['authenticator']['hash'] = CryptConnector::acquiaHash($result['secret']['key'], $result['authenticator']['time'] . ':' . $result['authenticator']['nonce']);
        if (isset($spi_data['test_validation_error'])) {
          $result['authenticator']['nonce'] = 'TEST'; // Force a validation fail.
        }
        unset($result['secret']);
        return new JsonResponse($result);
      }
    }
    else {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
  }

  function spiDefinition(Request $request, $version) {
    $vars = array('file_temporary_path' => array('optional' => FALSE, 'description' => 'file_temporary_path'), 'page_compression' => array('optional' => TRUE, 'description' => 'page_compression'), 'user_admin_role' => array('optional' => TRUE, 'description' => 'user_admin_role'));
    $data = array(
      'drupal_version' => (string) $version,
      'timestamp' => (string) (REQUEST_TIME + 9),
      'acquia_spi_variables' => $vars,
    );
    return new JsonResponse($data);
  }

  /**
   * @param Request $request
   * @return array|bool|\stdClass
   */
  public function getCommunicationSettings(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $fields = array(
      'time' => 'is_numeric',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );

    // Authenticate.
    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return new JsonResponse($result);
    }

    if (!isset($data['body']) || !isset($data['body']['email'])) {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
    $account = user_load_by_mail($data['body']['email']);
    if (empty($account) || $account->isAnonymous()) {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Account not found')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
    $result = array(
      'algorithm' => 'sha512',
      'hash_setting' => substr($account->getPassword(), 0, 12),
      'extra_md5' => FALSE,
    );
    return new JsonResponse($result);
  }

  /**
   * @param $fields
   * @param $data
   * @return array
   */
  protected function basicAuthenticator($fields, $data) {
    $result = array();
    foreach ($fields as $field => $type) {
      if (empty($data['authenticator'][$field]) || !$type($data['authenticator'][$field])) {
        return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_MESSAGE_INVALID, t('Authenticator field @field is missing or invalid.', array('@field' => $field)));
      }
    }
    $now = REQUEST_TIME;
    if ($data['authenticator']['time'] > ($now + self::ACQTEST_SUBSCRIPTION_MESSAGE_LIFETIME)) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_MESSAGE_FUTURE, t('Message time ahead of server time.'));
    }
    else {
      if ($data['authenticator']['time'] < ($now - self::ACQTEST_SUBSCRIPTION_MESSAGE_LIFETIME)) {
        return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_MESSAGE_EXPIRED, t('Message is too old.'));
      }
    }

    $result['error'] = FALSE;
    return $result;
  }

  /**
   * @param Request $request
   * @return JsonResponse
   */
  public function getCredentials(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    $fields = array(
      'time' => 'is_numeric',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );
    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return new JsonResponse($result, self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }

    if (!empty($data['body']['email'])) {
      $account = user_load_by_mail($data['body']['email']);
      \Drupal::logger('getCredentials password')->debug($account->getPassword());
      if (empty($account) || $account->isAnonymous()) {
        return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Account not found')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
      }
    }
    else {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }

    $hash = CryptConnector::acquiaHash($account->getPassword(), $data['authenticator']['time'] . ':' . $data['authenticator']['nonce']);
    if ($hash === $data['authenticator']['hash']) {
      $result = array();
      $result['is_error'] = FALSE;
      $result['body']['subscription'][] = array(
        'identifier' => self::ACQTEST_ID,
        'key' => self::ACQTEST_KEY,
        'name' => self::ACQTEST_ID,
      );
      return new JsonResponse($result);
    }
    else {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Incorrect password.')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getSubscription(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $result = $this->validateAuthenticator($data);
    if (empty($result['error'])) {
      $result['authenticator']['hash'] = CryptConnector::acquiaHash($result['secret']['key'], $result['authenticator']['time'] . ':' . $result['authenticator']['nonce']);
      unset($result['secret']);
      return new JsonResponse($result);
    }
    unset($result['secret']);
    return new JsonResponse($result, self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
  }

  /**
   * @param array $data
   * @return array
   */
  protected function validateAuthenticator($data) {
    $fields = array(
      'time' => 'is_numeric',
      'identifier' => 'is_string',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );

    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return $result;
    }

    if (strpos($data['authenticator']['identifier'], 'TEST_') !== 0) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_NOT_FOUND, t('Subscription not found'));
    }

    switch ($data['authenticator']['identifier']) {
      case self::ACQTEST_ID:
        $key = self::ACQTEST_KEY;
        break;
      case self::ACQTEST_EXPIRED_ID:
        $key = self::ACQTEST_EXPIRED_KEY;
        break;
      case self::ACQTEST_503_ID:
        $key = self::ACQTEST_503_KEY;
        break;
      default:
        $key = self::ACQTEST_ERROR_KEY;
        break;
    }

    $hash = CryptConnector::acquiaHash($key, $data['authenticator']['time'] . ':' . $data['authenticator']['nonce']);
    $hash_simple = CryptConnector::acquiaHash($key, $data['authenticator']['time'] . ':' . $data['authenticator']['nonce']);

    if (($hash !== $data['authenticator']['hash']) && ($hash_simple != $data['authenticator']['hash'])) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('HMAC validation error: ') . "{$hash} != {$data['authenticator']['hash']}");
    }

    if ($key === self::ACQTEST_EXPIRED_KEY) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_EXPIRED, t('Subscription expired.'));
    }

    // Record connections.
    $connections = \Drupal::config('acquia_connector.settings')->get('test_connections' . $data['authenticator']['identifier']);
    $connections++;
    \Drupal::configFactory()->getEditable('acquia_connector.settings')->set('test_connections' . $data['authenticator']['identifier'], $connections)->save();
    if ($connections == 3 && $data['authenticator']['identifier'] == self::ACQTEST_503_ID) {
      // Trigger a 503 response on 3rd call to this (1st is
      // acquia.agent.subscription and 2nd is acquia.agent.validate)
      $this->headers->set("Status", "503 Server Error");
      print '';
      exit;
    }
    $result['error'] = FALSE;
    $result['body']['subscription_name'] = 'TEST_AcquiaConnectorTestID';
    $result['body']['active'] = 1;
    $result['body']['href'] = 'http://acquia.com/network';
    $result['body']['expiration_date']['value'] = '2023-10-08T06:30:00';
    $result['body']['product'] = '91990';
    $result['body']['derived_key_salt'] = $data['authenticator']['identifier'] . '_KEY_SALT';
    $result['body']['update_service'] = 1;
    $result['body']['search_service_enabled'] = 1;
    if (isset($data['body']['rpc_version'])) {
      $result['body']['rpc_version'] = $data['body']['rpc_version'];
    }
    $result['secret']['data'] = $data;
    $result['secret']['nid'] = '91990';
    $result['secret']['node'] = $data['authenticator']['identifier'] . '_NODE';
    $result['secret']['key'] = $key;
    //$result['secret']['nonce'] = '';
    $result['authenticator'] = $data['authenticator'];
    $result['authenticator']['hash'] = '';
    $result['authenticator']['time'] += 1;
    $result['authenticator']['nonce'] = $data['authenticator']['nonce'];
    return $result;
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function cloudMigrationEnvironments(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    global $base_url;
    $fields = array(
      'time' => 'is_numeric',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );
    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return new JsonResponse($result, self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
    if (!empty($data['body']['identifier'])) {
      if (strpos($data['body']['identifier'], 'TEST_') !== 0) {
        return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Subscription not found')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
      }
    }
    else {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
    if ($data['body']['identifier'] == self::ACQTEST_ERROR_ID) {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_SITE_NOT_FOUND, t("Hosting not available under your subscription. Upgrade your subscription to continue with import.")), self::ACQTEST_SUBSCRIPTION_SERVICE_UNAVAILABLE);
    }
    $result = array();
    $result['is_error'] = FALSE;
    foreach (array('dev' => 'Development', 'test' => 'Stage', 'prod' => 'Production') as $key => $name) {
      $result['body']['environments'][$key] = array(
        'url' => $base_url . '/system/acquia-connector-test-upload/AH_UPLOAD',
        'stage' => $key,
        'nonce' => 'nonce',
        'secret' => 'secret',
        'site_name' => $name,
      );
    }
    return new JsonResponse($result);
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param $id
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function testMigrationUpload(Request $request, $id) {
    return new Response('', Response::HTTP_OK);
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function testMigrationComplete(Request $request) {
    return new JsonResponse(array('TRUE'));
  }

  /**
   * @return bool
   */
  public function access() {
    return AccessResultAllowed::allowed();
  }

  /**
   * Format the error response.
   *
   * @param $code
   * @param $message
   * @return array
   */
  protected function errorResponse($code, $message) {
    return array(
      'code' => $code,
      'message' => $message,
      'error' => TRUE,
    );
  }
}
