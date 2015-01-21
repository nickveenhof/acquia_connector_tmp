<?php
/**
 * @file
 * Test endpoint for Acquia Connector.
 */

namespace Drupal\acquia_connector_test\Controller;

//use SebastianBergmann\Exporter\Exception;
use Drupal\Core\Access\AccessInterface;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\acquia_connector\Client;
use Drupal\Core\Url;


class NspiController extends ControllerBase {

  protected $data = array();

  const ACQTEST_SUBSCRIPTION_NOT_FOUND = 1000;
  const ACQTEST_SUBSCRIPTION_KEY_MISMATCH = 1100;
  const ACQTEST_SUBSCRIPTION_EXPIRED = 200;
  const ACQTEST_SUBSCRIPTION_REPLAY_ATTACK = 1300;
  const ACQTEST_SUBSCRIPTION_KEY_NOT_FOUND = 1400;
  const ACQTEST_SUBSCRIPTION_MESSAGE_FUTURE = 1500;
  const ACQTEST_SUBSCRIPTION_MESSAGE_EXPIRED = 1600;
  const ACQTEST_SUBSCRIPTION_MESSAGE_INVALID = 700;
  const ACQTEST_SUBSCRIPTION_VALIDATION_ERROR = 1800;
  const ACQTEST_SUBSCRIPTION_SITE_NOT_FOUND = 1900;
  const ACQTEST_SUBSCRIPTION_PROVISION_ERROR = 9000;
  const ACQTEST_SUBSCRIPTION_MESSAGE_LIFETIME = 900; //15*60
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

  /**
   * @param Request $request
   * @return array|bool|\stdClass
   */
  public function getCommunicationSettings(Request $request) {
    $data_decode = json_decode($request->getContent(), TRUE);//todo
    $data = json_decode($data_decode, TRUE);//todo
    //\Drupal::logger('getCommunicationSettings')->info(print_r($data, TRUE));//todo
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
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')));
    }

    $account = user_load_by_mail($data['body']['email']);

    if (empty($account) || $account->isAnonymous()) {
      $err = $this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Account not found'));
      return new JsonResponse($err);
    }
    else {
      $result = array();
      $result = array(
        'algorithm' => 'sha512',
        'hash_setting' => substr($account->getPassword(), 0, 12),
        'extra_md5' => FALSE,
      );
      return new JsonResponse($result);
    }
    //return new JsonResponse(array('TRUE')); //@todo
  }


  protected function basicAuthenticator($fields, $data) {
    $result = array();
    \Drupal::logger('basicAuthenticator')->info(print_r($data, TRUE));
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
    $data_decode = json_decode($request->getContent(), TRUE); //todo
    $data = json_decode($data_decode, TRUE);//todo

    $fields = array(
      'time' => 'is_numeric',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );
    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return new JsonResponse($result);
    }

    if (!empty($data['body']['email'])) {
      $account = user_load_by_mail($data['body']['email']);
      \Drupal::logger('getCredentials password')->debug($account->getPassword());
      if (empty($account) || $account->isAnonymous()) {
        return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Account not found')));
      }
    }
    else {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')));
    }

    $client = new testClient();
    $hash = $client->testHash($account->getPassword(), $data['authenticator']['time'], $data['authenticator']['nonce'], $data['body']);
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
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Incorrect password.')));
    }
  }

  /**
   * @param Request $request
   * @param $id
   * @return JsonResponse
   */
  public function getSubscription(Request $request, $id) {
    $data_decode = json_decode($request->getContent(), TRUE); //todo
    $data = json_decode($data_decode, TRUE);

    $result = $this->validateAuthenticator($data);
    if (empty($result['error'])) {
      $client = new testClient();
      $result['authenticator']['hash'] = $client->testHash($result['secret']['key'], $result['authenticator']['time'], $result['authenticator']['nonce'], $result['body']);
      unset($result['secret']);
      return new JsonResponse($result);
    }
    unset($result['secret']);
    return new JsonResponse($result);
  }



  protected function validateAuthenticator($data) {
    $fields = array(
      'time' => 'is_numeric',
      'identifier' => 'is_string',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );

    $result = $this->basicAuthenticator($fields, $data);
    \Drupal::logger('validateAuthenticato basicAuthenticator')->error(print_R($result, TRUE));
    if (!empty($result['error'])) {
      return $result;
    }

    if (strpos($data['authenticator']['identifier'], 'TEST_') !== 0) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_NOT_FOUND, t('Subscription not found.'));
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

    $client = new testClient();
    $hash = $client->testHash($key, $data['authenticator']['time'], $data['authenticator']['nonce'], $data['body']);
    $hash_simple = $client->testHash($key, $data['authenticator']['time'], $data['authenticator']['nonce'], $data['body']);

    if (($hash !== $data['authenticator']['hash']) && ($hash_simple != $data['authenticator']['hash'])) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('HMAC validation error: ') . "{$hash} != {$data['authenticator']['hash']}");
    }

    if ($key === self::ACQTEST_EXPIRED_KEY) {
      return $this->errorResponse(self::ACQTEST_SUBSCRIPTION_EXPIRED, t('Subscription expired.'));

    }

    // Record connections.
    $connections = \Drupal::config('acquia_connector.settings')->get('test_connections' . $data['authenticator']['identifier']);
    $connections++;
    \Drupal::config('acquia_connector.settings')->set('test_connections' . $data['authenticator']['identifier'], $connections)->save();
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
    $result['authenticator'] = $data['authenticator'];
    $result['authenticator']['hash'] = '';
    $result['authenticator']['time'] += 1;
    return $result;
  }

  /**
   * @param Request $request
   * @return JsonResponse
   */
  public function cloudMigrationEnvironments(Request $request) {
    $data_decode = json_decode($request->getContent(), TRUE); //todo
    $data = json_decode($data_decode, TRUE);

    $fields = array(
      'time' => 'is_numeric',
      'nonce' => 'is_string',
      'hash' => 'is_string',
    );
    $result = $this->basicAuthenticator($fields, $data);
    if (!empty($result['error'])) {
      return new JsonResponse($result);
    }
    if (!empty($data['body']['identifier'])) {
      if (strpos($data['body']['identifier'], 'TEST_') !== 0) {
        return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Subscription not found')));
      }
    }
    else {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_VALIDATION_ERROR, t('Invalid arguments')));
    }
    if ($data['body']['identifier'] == self::ACQTEST_ERROR_ID) {
      return new JsonResponse($this->errorResponse(self::ACQTEST_SUBSCRIPTION_SITE_NOT_FOUND, t("Hosting not available under your subscription. Upgrade your subscription to continue with import.")));
    }
    $result = array();
    $result['is_error'] = FALSE;
    foreach (array('dev' => 'Development', 'test' => 'Stage', 'prod' => 'Production') as $key => $name) {
      $result['body']['environments'][$key] = array(
        'url' => 'http://drupal-alerts.local:8083/system/acquia-connector-test-upload/AH_UPLOAD',
        'stage' => $key,
        'nonce' => 'nonce',
        'secret' => 'secret',
        'site_name' => $name,
      );
    }
    return new JsonResponse($result);
  }

  /**
   * @param Request $request
   * @param $id
   * @return Response
   *
   */
  public function testMigrationUpload(Request $request, $id) {
    return new Response('', Response::HTTP_OK);
  }

  /**
   * @param Request $request
   * @return JsonResponse
   * @return JsonResponse
   */
  public function testMigrationComplete(Request $request){
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
    //\Drupal::logger('errorResponse')->info($message);
    return array(
      'code' => $code,
      'message' => $message,
      'error' => TRUE,
    );
  }
}

/**
 * Class testClient
 * @package Drupal\acquia_connector_test\Controller
 */
class testClient extends Client{

  public function __construct() {}

  /**
   * @param $key
   * @param $time
   * @param $nonce
   * @param array $params
   * @return string
   */
  public function testHash($key, $time, $nonce, $params = array()) {
    \Drupal::logger('testHash')->error(print_R($params, TRUE));
    return parent::hash($key, $time, $nonce, $params);
  }
}

