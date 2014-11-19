<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\SpiController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Database;
use Drupal\Component\Utility\String;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\acquia_connector\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Serialization\Json;

/**
 * Class SpiController.
 */
class SpiController extends ControllerBase {

  /**
   * The Acquia client.
   *
   * @var \Drupal\acquia_connector\Client
   */
  protected $client;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param Client $client
   */
  public function __construct(Client $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('acquia_connector.client')
    );
  }

  /**
   * Gather site profile information about this site.
   *
   * @return array
   *   An associative array keyed by types of information.
   */
  public function get($method = '') {

    // Get file hashes and compute serialized version.
    list($hashes, $fileinfo) = $this->getFileHashes();
    $hashes_string = serialize($hashes);

    // Get the Drupal version
    $drupal_version = $this->getVersionInfo();

    $stored = $this->dataStoreGet(array('platform'));
    if (!empty($stored['platform'])) {
      $platform = $stored['platform'];
    }
    else {
      $platform = $this->getPlatform();
    }
    $spi = array(
      'spi_data_version' => ACQUIA_SPI_DATA_VERSION,
      'site_key'       => sha1(\Drupal::service('private_key')->get()),
      'modules'        => $this->getModules(),
      'platform'       => $platform,
      'quantum'        => $this->getQuantum(),
//    'system_status'  => acquia_spi_get_system_status(),
//    'failed_logins'  => variable_get('acquia_spi_send_watchdog', 1) ? acquia_spi_get_failed_logins() : array(),
//    '404s'           => variable_get('acquia_spi_send_watchdog', 1) ? acquai_spi_get_404s() : array(),
//    'watchdog_size'  => acquai_spi_get_watchdog_size(),
//    'watchdog_data'  => variable_get('acquia_spi_send_watchdog', 1) ? acquia_spi_get_watchdog_data() : array(),
//    'last_nodes'     => variable_get('acquia_spi_send_node_user', 1) ? acquai_spi_get_last_nodes() : array(),
//    'last_users'     => variable_get('acquia_spi_send_node_user', 1) ? acquai_spi_get_last_users() : array(),
//    'extra_files'    => acquia_spi_check_files_present(),
//    'ssl_login'      => acquia_spi_check_login(),
      'file_hashes'    => $hashes,
      'hashes_md5'     => md5($hashes_string),
      'hashes_sha1'    => sha1($hashes_string),
      'fileinfo'       => $fileinfo,
      'distribution'   => isset($drupal_version['distribution']) ? $drupal_version['distribution'] : '',
      'base_version'   => $drupal_version['base_version'],
      'build_data'     => $drupal_version,
      'roles'          => Json::encode(user_roles()),
      'uid_0_present'  => acquia_connector_spi_uid_0_present(),
    );

//  $scheme = parse_url(variable_get('acquia_spi_server', 'https://nspi.acquia.com'), PHP_URL_SCHEME);
//  $via_ssl = (in_array('ssl', stream_get_transports(), TRUE) && $scheme == 'https') ? TRUE : FALSE;
//  if (variable_get('acquia_spi_ssl_override', FALSE)) {
//    $via_ssl = TRUE;
//  }

    $additional_data = array();
//  $security_review_results = acquia_spi_run_security_review();

    // It's worth sending along node access control information even if there are
    // no modules implementing it - some alerts are simpler if we know we don't
    // have to worry about node access.

    // Check for node grants modules.
    $additional_data['node_grants_modules'] = module_implements("node_grants", TRUE);

    // Check for node access modules.
    $additional_data['node_access_modules'] = module_implements("node_access", TRUE);

    if (!empty($security_review_results)) {
      $additional_data['security_review'] = $security_review_results['security_review'];
    }

    // Collect all user-contributed custom tests that pass validation.
//  $custom_tests_results = acquia_spi_test_collect();
    if (!empty($custom_tests_results)) {
      $additional_data['custom_tests'] = $custom_tests_results;
    }

//  $spi_data = module_invoke_all('acquia_spi_get');
    if (!empty($spi_data)) {
      foreach ($spi_data as $name => $data) {
        if (is_string($name) && is_array($data)) {
          $additional_data[$name] = $data;
        }
      }
    }

    // Database updates required?
    // Based on code from system.install
    include_once DRUPAL_ROOT . '/includes/install.inc';
//  drupal_load_updates();

    $additional_data['pending_updates'] = FALSE;
    $modules = system_rebuild_module_data();
    uasort($modules, 'system_sort_modules_by_info_name');
    foreach ($modules as $key => $module) {
      $updates = drupal_get_schema_versions($key);
      if ($updates !== FALSE) {
        $default = drupal_get_installed_schema_version($key);
        if (max($updates) > $default) {
          $additional_data['pending_updates'] = TRUE;
          break;
        }
      }
    }

    if (!empty($additional_data)) {
      // JSON encode this additional data.
      $spi['additional_data'] = json_encode($additional_data);
    }

    if (!empty($method)) {
      $spi['send_method'] = $method;
    }

    if (!$via_ssl) {
      return $spi;
    }
    else {
      // Values returned only over SSL
      $spi_ssl = array(
        'system_vars' => acquia_connector_spi_get_variables_data(),
        'settings_ra' => acquia_spi_get_settings_permissions(),
        'admin_count' => variable_get('acquia_spi_admin_priv', 1) ? acquia_spi_get_admin_count() : '',
        'admin_name' => variable_get('acquia_spi_admin_priv', 1) ? acquia_spi_get_super_name() : '',
      );

      return array_merge($spi, $spi_ssl);
    }
  }


  /**
   * Gather hashes of all important files, ignoring line ending and CVS Ids
   *
   * @param array $exclude_dirs
   *   Optional array of directory paths to be excluded.
   *
   * @return array
   *   An associative array keyed by filename of hashes.
   */
  private function getFileHashes($exclude_dirs = array()) {
    // The list of directories for the third parameter are the only ones that
    // will be recursed into.  Thus, we avoid sending hashes for any others.
    list($hashes, $fileinfo) = _acquia_connector_spi_generate_hashes('.', $exclude_dirs, array('modules', 'profiles', 'themes', 'includes', 'misc', 'scripts'));
    ksort($hashes);
    // Add .htaccess file.
    $htaccess = DRUPAL_ROOT . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($htaccess)) {
      $owner = fileowner($htaccess);
      if (function_exists('posix_getpwuid')) {
        $userinfo = posix_getpwuid($owner);
        $owner = $userinfo['name'];
      }
      $fileinfo['.htaccess'] = 'mt:' . filemtime($htaccess) . '$p:' . substr(sprintf('%o', fileperms($htaccess)), -4) . '$o:' . $owner . '$s:' . filesize($htaccess);
    }
    return array($hashes, $fileinfo);
  }

  /**
   * Attempt to determine the version of Drupal being used.
   * Note, there is better information on this in the common.inc file.
   *
   * @return array
   *    An array containing some detail about the version
   */
  private function getVersionInfo() {
    $store = $this->dataStoreGet(array('platform'));
    $server = (!empty($store) && isset($store['platform'])) ? $store['platform']['php_quantum']['SERVER'] : $_SERVER;
    $ver = array();

    $ver['base_version'] = \Drupal::VERSION;
    $install_root = $server['DOCUMENT_ROOT'] . base_path();
    $ver['distribution']  = '';

    // Determine if this puppy is Acquia Drupal
    // @todo: do something with next 5 defines.
//    acquia_agent_load_versions();
    /**
     * Is this an Acquia Drupal install?
     */
    define('IS_ACQUIA_DRUPAL', FALSE);

    /**
     * Acquia Drupal version information (only used if IS_ACQUIA_DRUPAL).
     */
    define('ACQUIA_DRUPAL_VERSION' , 'ACQ_version_ACQ');
    define('ACQUIA_DRUPAL_SERIES'  , 'ACQ_series_ACQ');
    define('ACQUIA_DRUPAL_BRANCH'  , 'ACQ_branch_ACQ');
    define('ACQUIA_DRUPAL_REVISION', 'ACQ_rev_ACQ');

    if (IS_ACQUIA_DRUPAL) {
      $ver['distribution']   = 'Acquia Drupal';
      $ver['ad']['version']  = ACQUIA_DRUPAL_VERSION;
      $ver['ad']['series']   = ACQUIA_DRUPAL_SERIES;
      $ver['ad']['branch']   = ACQUIA_DRUPAL_BRANCH;
      $ver['ad']['revision'] = ACQUIA_DRUPAL_REVISION;
    }

    // @todo: Review all D8 distributions!
    // Determine if we are looking at Pressflow
    if (defined('CACHE_EXTERNAL')) {
      $ver['distribution']  = 'Pressflow';
      $press_version_file = $install_root . './PRESSFLOW.txt';
      if (is_file($press_version_file)) {
        $ver['pr']['version'] = trim(file_get_contents($press_version_file));
      }
    }
    // Determine if this is Open Atrium
    elseif (is_dir($install_root . '/profiles/openatrium')) {
      $ver['distribution']  = 'Open Atrium';
      $version_file = $install_root . 'profiles/openatrium/VERSION.txt';
      if (is_file($version_file)) {
        $ver['oa']['version'] = trim(file_get_contents($version_file));
      }
    }
    // Determine if this is Commons
    elseif (is_dir($install_root . '/profiles/commons')) {
      $ver['distribution']  = 'Commons';
    }
    // Determine if this is COD.
    elseif (is_dir($install_root . '/profiles/cod')) {
      $ver['distribution']  = 'COD';
    }

    return $ver;
  }


  /**
   * Get SPI data out of local storage.
   *
   * @param array Array of keys to extract data for.
   *
   * @return array Stored data or false if no data is retrievable from storage.
   */
  private function dataStoreGet($keys) {
    $store = array();
    foreach ($keys as $key) {
      if ($cache = \Drupal::cache()->get('acquia.spi.' . $key) && !empty($cache->data)) {
        $store[$key] = $cache->data;
      }
    }
    return $store;
  }


  /**
   * Gather platform specific information.
   *
   * @return array
   *   An associative array keyed by a platform information type.
   */
  public function getPlatform() {
    $server = \Drupal::request()->server;
    // Database detection depends on the structure starting with the database
    $db_class = '\Drupal\Core\Database\Driver\\' . Database\Database::getConnection()->driver() . '\Install\Tasks';
    $db_tasks = new $db_class();
    // Webserver detection is based on name being before the slash, and
    // version being after the slash.
    preg_match('!^([^/]+)(/.+)?$!', $server->get('SERVER_SOFTWARE'), $webserver);

    if (isset($webserver[1]) && stristr($webserver[1], 'Apache') && function_exists('apache_get_version')) {
      $webserver[2] = apache_get_version();
      $apache_modules = apache_get_modules();
    }
    else {
      $apache_modules = '';
    }

    // Get some basic PHP vars
    $php_quantum = array(
      'memory_limit' => ini_get('memory_limit'),
      'register_globals' => ini_get('register_globals'),
      'post_max_size' => ini_get('post_max_size'),
      'max_execution_time' => ini_get('max_execution_time'),
      'upload_max_filesize' => ini_get('upload_max_filesize'),
      'error_log' => ini_get('error_log'),
      'error_reporting' => ini_get('error_reporting'),
      'display_errors' => ini_get('display_errors'),
      'log_errors' => ini_get('log_errors'),
      'session.cookie_domain' => ini_get('session.cookie_domain'),
      'session.cookie_lifetime' => ini_get('session.cookie_lifetime'),
      'newrelic.appname' => ini_get('newrelic.appname'),
      'SERVER' => $server->all(),
      'sapi' => php_sapi_name(),
    );

    $platform = array(
      'php'               => PHP_VERSION,
      'webserver_type'    => isset($webserver[1]) ? $webserver[1] : '',
      'webserver_version' => isset($webserver[2]) ? $webserver[2] : '',
      'apache_modules'    => $apache_modules,
      'php_extensions'    => get_loaded_extensions(),
      'php_quantum'       => $php_quantum,
      'database_type'     => $db_tasks->name(),
      'database_version'  => Database\Database::getConnection()->version(),
      'system_type'       => php_uname('s'),
      // php_uname() only accepts one character, so we need to concatenate ourselves.
      'system_version'    => php_uname('r') . ' ' . php_uname('v') . ' ' . php_uname('m') . ' ' . php_uname('n'),
      'mysql'             => (Database\Database::getConnection()->driver() == 'mysql') ? SpiController::getPlatformMysqlData() : array(),
    );

    // Never send NULL (or FALSE?) - that causes hmac errors.
    foreach ($platform as $key => $value) {
      if (empty($platform[$key])) {
        $platform[$key] = '';
      }
    }

    return $platform;
  }

  /**
   * Gather mysql specific information.
   *
   * @return array
   *   An associative array keyed by a mysql information type.
   */
  private function getPlatformMysqlData() {
    $connection = Database\Database::getConnection('default');
    $result = $connection->query('SHOW GLOBAL STATUS', array(), array())->fetchAll();

    $ret = array();
    if (empty($result)) {
      return $ret;
    }

    foreach ($result as $record) {
      if (!isset($record->Variable_name)) {
        continue;
      }
      switch ($record->Variable_name) {
        case 'Table_locks_waited':
          $ret['Table_locks_waited'] = $record->Value;
          break;
        case 'Slow_queries':
          $ret['Slow_queries'] = $record->Value;
          break;
        case 'Qcache_hits':
          $ret['Qcache_hits'] = $record->Value;
          break;
        case 'Qcache_inserts':
          $ret['Qcache_inserts'] = $record->Value;
          break;
        case 'Qcache_queries_in_cache':
          $ret['Qcache_queries_in_cache'] = $record->Value;
          break;
        case 'Qcache_lowmem_prunes':
          $ret['Qcache_lowmem_prunes'] = $record->Value;
          break;
        case 'Open_tables':
          $ret['Open_tables'] = $record->Value;
          break;
        case 'Opened_tables':
          $ret['Opened_tables'] = $record->Value;
          break;
        case 'Select_scan':
          $ret['Select_scan'] = $record->Value;
          break;
        case 'Select_full_join':
          $ret['Select_full_join'] = $record->Value;
          break;
        case 'Select_range_check':
          $ret['Select_range_check'] = $record->Value;
          break;
        case 'Created_tmp_disk_tables':
          $ret['Created_tmp_disk_tables'] = $record->Value;
          break;
        case 'Created_tmp_tables':
          $ret['Created_tmp_tables'] = $record->Value;
          break;
        case 'Handler_read_rnd_next':
          $ret['Handler_read_rnd_next'] = $record->Value;
          break;
        case 'Sort_merge_passes':
          $ret['Sort_merge_passes'] = $record->Value;
          break;
        case 'Qcache_not_cached':
          $ret['Qcache_not_cached'] = $record->Value;
          break;

      }
    }

    return $ret;
  }


  /**
   * Gather information about modules on the site.
   *
   * @return array
   *   An associative array keyed by filename of associative arrays with
   *   information on the modules.
   */
  private function getModules() {
    // @todo
    // Only do a full rebuild of the module cache every 1 at the most
//  $last_build = variable_get('acquia_spi_module_rebuild', 0);
//  if ($last_build < REQUEST_TIME - 86400) {
//    $modules = system_rebuild_module_data();
//    variable_set('acquia_spi_module_rebuild', REQUEST_TIME);
//  }
//  else {
//    $result = db_query("SELECT filename, name, type, status, schema_version, info FROM {system} WHERE type = 'module'");
//    foreach ($result as $file) {
//      $file->info = unserialize($file->info);
//      $modules[$file->filename] = $file;
//    }
//  }

    $modules = system_rebuild_module_data();
    uasort($modules, 'system_sort_modules_by_info_name');

    $result = array();
    $keys_to_send = array('name', 'version', 'package', 'core');
    foreach ($modules as $module_key => $module) {
      $info = array();
      $info['status'] = $module->status;
      foreach ($keys_to_send as $key) {
        $info[$key] = isset($module->info[$key]) ? $module->info[$key] : '';
      }
      $info['project'] = $module_key;
      $info['filename'] = $module->subpath;

      // @todo
//    // Determine which files belong to this module and hash them
//    $module_path = explode('/', $file->filename);
//    array_pop($module_path);
//
//    // We really only care about this module if it is in 'sites' folder.
//    // Otherwise it is covered by the hash of the distro's modules
//    if ($module_path[0]=='sites') {
//      $contrib_path = implode('/', $module_path);
//
//      // Get a hash for this module's files. If we nest into another module, we'll return
//      // and that other module will be covered by it's entry in the system table.
//      //
//      // !! At present we aren't going to do a per module hash, but rather a per-project hash. The reason being that it is
//      // too hard to tell an individual module appart from a project
//      //$info['module_data'] = _acquia_nspi_generate_hashes($contrib_path,array(),array(),TRUE,$contrib_path);
//      list($info['module_data']['hashes'], $info['module_data']['fileinfo']) = _acquia_spi_generate_hashes($contrib_path);
//    }
//    else {
      $info['module_data']['hashes'] = array();
      $info['module_data']['fileinfo'] = array();
//    }

      $result[] = $info;
    }
    return $result;
  }

  /**
   * Gather information about nodes, users and comments.
   *
   * @return array
   *   An associative array.
   */
  private function getQuantum() {
    $quantum = array();
    // @todo
    // Get only published nodes.
    $quantum['nodes'] = db_select('node', 'n')->fields('n', array('nid'))->countQuery()->execute()->fetchField();
//  $quantum['nodes'] = db_select('node', 'n')->fields('n', array('nid'))->condition('n.status', NODE_PUBLISHED)->countQuery()->execute()->fetchField();
    // @todo
    // Get only active users.
//  $quantum['users'] = db_select('users', 'u')->fields('u', array('uid'))->condition('u.status', 1)->countQuery()->execute()->fetchField();
    $quantum['users'] = db_select('users', 'u')->fields('u', array('uid'))->countQuery()->execute()->fetchField();
    // @todo
//  if (module_exists('comment')) {
//    // Get only active comments.
//    $quantum['comments'] = db_select('comment', 'c')->fields('c', array('cid'))->condition('c.status', COMMENT_PUBLISHED)->countQuery()->execute()->fetchField();
//  }

    return $quantum;
  }

  /**
   * Send SPI data.
   */
  //@todo: In routing.yml replace _content with _controller.ß
  public function send(Request $request) {
    $config = $this->config('acquia_connector.settings');
    $method = ACQUIA_SPI_METHOD_CALLBACK;

    // Insight's set variable feature will pass method insight.
    if ($request->query->has('method') && ($request->query->get('method') === ACQUIA_SPI_METHOD_INSIGHT)) {
      $method = ACQUIA_SPI_METHOD_INSIGHT;
    }

    $spi = $this->get($method);
//    dpm($spi);

    $response = $this->client->sendNspi($config->get('identifier'), $config->get('key'), $spi);

//    $result = acquia_spi_send_data($spi);

    dpm($response);
//    if ($result === FALSE) {
//      return FALSE;
//    }

    acquia_connector_spi_handle_server_response($response);

    $config->set('cron_last', REQUEST_TIME);



//    $response = acquia_connector_send_full_spi($method);

    if ($request->get('destination')) {
      if (!empty($response)) {
        $message = array();
        if (isset($response['spi_data_received']) && $response['spi_data_received'] === TRUE) {
          $message[] = $this->t('SPI data sent.');
        }
        if (!empty($response['nspi_messages'])) {
          $message[] = $this->t('Acquia Network returned the following messages. Further information may be in the logs.');
          foreach ($response['nspi_messages'] as $nspi_message) {
            $message[] = String::checkPlain($nspi_message);
          }
        }

        drupal_set_message(implode('<br/>', $message));
      }
      else {
        drupal_set_message($this->t('Error sending SPI data. Consult the logs for more information.'), 'error');
      }

//      $this->redirect('<front>');
    }
    // @todo: remove
    return array();
  }

  /**
   * Access callback check for SPI send independent call.
   */
  public function sendAccess() {
    $request = \Drupal::request();
    $acquia_key = \Drupal::config('acquia_connector.settings')->get('key');

    if (!empty($acquia_key) && $request->get('key')) {
      $key = sha1(\Drupal::service('private_key')->get());
      if ($key === $request->get('key')) {
        return AccessResultAllowed::allowed();
      }
    }
    return AccessResultForbidden::forbidden();
  }

  /**
   * Determines the status of all user-contributed tests and logs any failures to
   * a tracking table.
   *
   * @param boolean $log
   *  (Optional) If TRUE, log all failures.
   *
   * @return array $custom_data
   *  An associative array containing any tests which failed validation.
   *
   */
  public function testStatus($log = FALSE) {
    $custom_data = array();

    // Iterate through modules which contain hook_acquia_spi_test().
    foreach (\Drupal::moduleHandler()->getImplementations('acquia_spi_test') as $module) {
      $function = $module . '_acquia_spi_test';
      if (function_exists($function)) {
        $result = $this->testValidate($function());

        if (!$result['result']) {
          $custom_data[$module] = $result;

          foreach ($result['failure'] as $test_name => $test_failures) {
            foreach ($test_failures as $test_param => $test_value) {
              $variables = array(
                '!module' => $module,
                '@message'     => $test_value['message'],
                '!param_name'  => $test_param,
                '!test'   => $test_name,
                '!value'       => $test_value['value'],
              );

              // Only log if we're performing a full validation check.
              if ($log) {
                drupal_set_message($this->t("Custom test validation failed for !test in !module and has been logged: @message for parameter '!param_name'; current value '!value'.", $variables), 'error');
                watchdog('acquia spi test', "<em>Custom test validation failed</em>: @message for parameter '!param_name'; current value '!value'. (<em>Test '!test_name' in module '!module_name'</em>)", $variables, WATCHDOG_WARNING);
              }
            }
          }
        }
      }
    }

    // If a full validation check is being performed, go to the status page to
    // show the results.
    if ($log) {
      $this->redirect('system.status');
    }

    return $custom_data;
  }

  /**
   * Validates data from custom test callbacks.
   *
   * @param array $collection
   *  An associative array containing a collection of user-contributed tests.
   *
   * @return array
   *  An associative array containing the validation result of the given tests,
   *  along with any failed parameters.
   *
   */
  protected function testValidate($collection) {
    $result = TRUE;
    $check_result_value = array();

    // Load valid categories and severities.
    $categories = array('performance', 'security', 'best_practices');
    $severities = array(0, 1, 2, 4, 8, 16, 32, 64, 128);

    foreach ($collection as $machine_name => $tests) {
      foreach ($tests as $check_name => $check_value) {
        $fail_value = '';
        $message    = '';

        $check_name  = strtolower($check_name);
        $check_value = (is_string($check_value)) ? strtolower($check_value) : $check_value;

        // Validate the data inputs for each check.
        switch ($check_name) {
          case 'category':
            if (!is_string($check_value) || !in_array($check_value, $categories)) {
              $type       = gettype($check_value);
              $fail_value = "$check_value ($type)";
              $message    = 'Value must be a string and one of ' . implode(', ', $categories);
            }
            break;

          case 'solved':
            if (!is_bool($check_value)) {
              $type       = gettype($check_value);
              $fail_value = "$check_value ($type)";
              $message    = 'Value must be a boolean';
            }
            break;

          case 'severity':
            if (!is_int($check_value) || !in_array($check_value, $severities)) {
              $type       = gettype($check_value);
              $fail_value = "$check_value ($type)";
              $message    = 'Value must be an integer and set to one of ' . implode(', ', $severities);
            }
            break;

          default:
            if (!is_string($check_value) || strlen($check_value) > 1024) {
              $type       = gettype($check_value);
              $fail_value = "$check_value ($type)";
              $message    = 'Value must be a string and no more than 1024 characters';
            }
            break;
        }

        if (!empty($fail_value) && !empty($message)) {
          $check_result_value['failed'][$machine_name][$check_name]['value']   = $fail_value;
          $check_result_value['failed'][$machine_name][$check_name]['message'] = $message;
        }
      }
    }

    // If there were any failures, the test has failed. Into exile it must go.
    if (!empty($check_result_value)) {
      $result = FALSE;
    }

    return array('result' => $result, 'failure' => (isset($check_result_value['failed'])) ? $check_result_value['failed'] : array());
  }

}
