<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\SpiController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
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
   * @param string $method
   *   Optional identifier for the method initiating request.
   *   Values could be 'cron' or 'menu callback' or 'drush'.
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
      'system_status'  => $this->getSystemStatus(),
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
      'uid_0_present'  => $this->getUidZerroIsPresent(),
    );

    $scheme = parse_url($this->config('acquia_connector.settings')->get('network_address'), PHP_URL_SCHEME);
    $via_ssl = (in_array('ssl', stream_get_transports(), TRUE) && $scheme == 'https') ? TRUE : FALSE;
    // @todo: Implement acquia_spi_ssl_override!
    if ($this->config('acquia_connector.settings')->get('acquia_spi_ssl_override')) {
      $via_ssl = TRUE;
    }

    $additional_data = array();
//  $security_review_results = acquia_spi_run_security_review();

    // It's worth sending along node access control information even if there are
    // no modules implementing it - some alerts are simpler if we know we don't
    // have to worry about node access.

    // Check for node grants modules.
    $additional_data['node_grants_modules'] = \Drupal::moduleHandler()->getImplementations('node_grants');

    // Check for node access modules.
    $additional_data['node_access_modules'] = \Drupal::moduleHandler()->getImplementations('node_access');

    if (!empty($security_review_results)) {
      $additional_data['security_review'] = $security_review_results['security_review'];
    }

    // Collect all user-contributed custom tests that pass validation.
//  $custom_tests_results = acquia_spi_test_collect();
    if (!empty($custom_tests_results)) {
      $additional_data['custom_tests'] = $custom_tests_results;
    }

    $spi_data = \Drupal::moduleHandler()->invokeAll('acquia_spi_get');
    if (!empty($spi_data)) {
      foreach ($spi_data as $name => $data) {
        if (is_string($name) && is_array($data)) {
          $additional_data[$name] = $data;
        }
      }
    }

    // Database updates required?
    // Based on code from system.install
    include_once DRUPAL_ROOT . '/core/includes/install.inc';
    drupal_load_updates();

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
        // @todo getVariablesData
        'system_vars' => $this->getVariablesData(),
        'settings_ra' => $this->getSettingsPermissions(),
        // @todo getAdminCount
        'admin_count' => $this->config('acquia_connector.settings')->get('admin_priv') ? $this->getAdminCount() : '',
        'admin_name' => $this->config('acquia_connector.settings')->get('admin_priv') ? $this->getSuperName() : '',
      );

      return array_merge($spi, $spi_ssl);
    }
  }

  /**
   * This function is a trimmed version of Drupal's system_status function
   *
   * @return array
   */
  function getSystemStatus() {
    $data = array();

    $profile = drupal_get_profile();
    if ($profile != 'standard') {
      $info = system_get_info('module', $profile);
      $data['install_profile'] = array(
        'title' => 'Install profile',
        'value' => t('%profile_name (%profile-%version)', array(
          '%profile_name' => $info['name'],
          '%profile' => $profile,
          '%version' => $info['version'],
        )),
      );
    }
    $data['php'] = array(
      'title' => 'PHP',
      'value' => phpversion(),
    );
    $conf_dir = TRUE;
    $settings = TRUE;
    $dir = DrupalKernel::findSitePath(\Drupal::request(), TRUE);
    if (is_writable($dir) || is_writable($dir . '/settings.php')) {
      $value = 'Not protected';
      if (is_writable($dir)) {
        $conf_dir = FALSE;
      }
      elseif (is_writable($dir . '/settings.php')) {
        $settings = FALSE;
      }
    }
    else {
      $value = 'Protected';
    }
    $data['settings.php'] = array(
      'title' => 'Configuration file',
      'value' => $value,
      'conf_dir' => $conf_dir,
      'settings' => $settings,
    );
    $cron_last = $cron_last = \Drupal::state()->get('system.cron_last');
    if (!is_numeric($cron_last)) {
      $cron_last = \Drupal::state()->get('install_time', 0);
    }
    $data['cron'] = array(
      'title' => 'Cron maintenance tasks',
      'value' => t('Last run !time ago', array('!time' => \Drupal::service('date.formatter')->formatInterval(REQUEST_TIME - $cron_last))),
      'cron_last' => $cron_last,
    );
    if (!empty($GLOBALS['update_free_access'])) {
      $data['update access'] = array(
        'value' => 'Not protected',
        'protected' => FALSE,
      );
    }
    else {
      $data['update access'] = array(
        'value' => 'Protected',
        'protected' => TRUE,
      );
    }
    $data['update access']['title'] = 'Access to update.php';
    if (!\Drupal::moduleHandler()->moduleExists('update')) {
      $data['update status'] = array(
        'value' => 'Not enabled',
      );
    }
    else {
      $data['update status'] = array(
        'value' => 'Enabled',
      );
    }
    $data['update status']['title'] = 'Update notifications';
    return $data;
  }

  /**
   * Get all system variables
   *
   * @return array()
   */
  private function getVariablesData() {
    global $conf;
    $data = array();
    return $data;
    $variables = array('acquia_spi_send_node_user', 'acquia_spi_admin_priv', 'acquia_spi_module_diff_data', 'acquia_spi_send_watchdog', 'acquia_spi_use_cron', 'cache_backends', 'cache_default_class', 'cache_inc', 'cron_safe_threshold', 'googleanalytics_cache', 'error_level', 'preprocess_js', 'page_cache_maximum_age', 'block_cache', 'preprocess_css', 'page_compression', 'cache', 'cache_lifetime', 'cron_last', 'clean_url', 'redirect_global_clean', 'theme_zen_settings', 'site_offline', 'site_name', 'user_register', 'user_signatures', 'user_admin_role', 'user_email_verification', 'user_cancel_method', 'filter_fallback_format', 'dblog_row_limit', 'date_default_timezone', 'file_default_scheme', 'install_profile', 'maintenance_mode', 'update_last_check', 'site_default_country', 'acquia_spi_saved_variables', 'acquia_spi_set_variables_automatic', 'acquia_spi_ignored_set_variables', 'acquia_spi_set_variables_override');
    $spi_def_vars = variable_get('acquia_spi_def_vars', array());
    $waived_spi_def_vars = variable_get('acquia_spi_def_waived_vars', array());
    // Merge hard coded $variables with vars from SPI definition.
    foreach($spi_def_vars as $var_name => $var) {
      if (!in_array($var_name, $waived_spi_def_vars) && !in_array($var_name, $variables)) {
        $variables[] = $var_name;
      }
    }
    // Add comment settings for node types.
    $types = node_type_get_types();
    if (!empty($types)) {
      foreach ($types as $name => $type) {
        $variables[] = 'comment_' . $name;
      }
    }
    foreach ($variables as $name) {
      if (isset($conf[$name])) {
        $data[$name] = $conf[$name];
      }
    }
    // Exception handling.
    if (module_exists('globalredirect') && function_exists('_globalredirect_get_settings')) {
      // Explicitly get Global Redirect settings since it deletes its variable
      // if the settings match the defaults.
      $data['globalredirect_settings'] = _globalredirect_get_settings();
    }
    // Drush overrides cron_safe_threshold so extract DB value if sending via drush.
    if (drupal_is_cli()) {
      $cron_safe_threshold = acquia_spi_get_db_variable('cron_safe_threshold');
      $data['cron_safe_threshold'] = !is_null($cron_safe_threshold) ? $cron_safe_threshold : DRUPAL_CRON_DEFAULT_THRESHOLD;
    }
    // Unset waived vars so they won't be sent to NSPI.
    foreach($data as $var_name => $var) {
      if (in_array($var_name, $waived_spi_def_vars)) {
        unset($data[$var_name]);
      }
    }
    // Collapse to JSON string to simplify transport.
    return Json::encode($data);
  }

  /**
  * Check the presence of UID 0 in the users table.
  *
  * @return bool Whether UID 0 is present.
  */
  private function getUidZerroIsPresent() {
    $count = db_query("SELECT uid FROM {users} WHERE uid = 0")->fetchAll();
    return (boolean) $count;
  }

  /**
   * The number of users who have admin-level user roles.
   *
   * @return int
   */
  private function getAdminCount() {
    // @todo
    return '';
//    $count = NULL;
//    $sql = "SELECT COUNT(DISTINCT u.uid) as count
//              FROM {users} u, {users_roles} ur, {role_permission} p
//              WHERE u.uid = ur.uid
//                AND ur.rid = p.rid
//                AND u.status = 1
//                AND (p.permission = 'administer permissions' OR p.permission = 'administer users')";
//    $result = db_query($sql)->fetchObject();
//
//    return (isset($result->count) && is_numeric($result->count)) ? $result->count : NULL;
  }

  /**
   * Determine if the super user has a weak name
   *
   * @return boolean
   */
  private function getSuperName() {
    $result = db_query("SELECT name FROM {users_field_data} WHERE uid = 1 AND (name LIKE '%admin%' OR name LIKE '%root%')")->fetch();
    return (boolean) $result;
  }

  /**
   * Determines if settings.php is read-only
   *
   * @return boolean
   */
  private function getSettingsPermissions() {
    $settings_permissions_read_only = TRUE;
    $writes = array('2', '3', '6', '7'); // http://en.wikipedia.org/wiki/File_system_permissions
    $settings_file = './' . DrupalKernel::findSitePath(\Drupal::request(), TRUE) . '/settings.php';
    $permissions = Unicode::substr(sprintf('%o', fileperms($settings_file)), -4);

    foreach ($writes as $bit) {
      if (strpos($permissions, $bit)) {
        $settings_permissions_read_only = FALSE;
        break;
      }
    }

    return $settings_permissions_read_only;
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
    list($hashes, $fileinfo) = $this->generateHashes('.', $exclude_dirs, array('modules', 'profiles', 'themes', 'includes', 'misc', 'scripts'));
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
   * Recursive helper function for getFileHashes().
   */
  private function generateHashes($dir, $exclude_dirs = array(), $limit_dirs = array(), $module_break = FALSE, $orig_dir=NULL) {
    $hashes = array();
    $fileinfo = array();

    // Ensure that we have not nested into another module's dir
    if ($dir != $orig_dir && $module_break) {
      if (is_dir($dir) && $handle = opendir($dir)) {
        while ($file = readdir($handle)) {
          if (stristr($file, '.module')) {
            return;
          }
        }
      }
    }
    if (isset($handle)) {
      closedir($handle);
    }

    // Standard nesting function
    if (is_dir($dir) && $handle = opendir($dir)) {
      while ($file = readdir($handle)) {
        if (!in_array($file, array('.', '..', 'CVS', '.svn'))) {
          $path = $dir == '.' ? $file : "{$dir}/{$file}";
          if (is_dir($path) && !in_array($path, $exclude_dirs) && (empty($limit_dirs) || in_array($path, $limit_dirs)) && ($file != 'translations')) {
            list($sub_hashes, $sub_fileinfo) =  $this->generateHashes($path, $exclude_dirs);
            $hashes = array_merge($sub_hashes, $hashes);
            $fileinfo = array_merge($sub_fileinfo, $fileinfo);
            $hashes[$path] = $this->hashPath($path);
          }
          elseif ($this->isManifestType($file)) {
            $hashes[$path] = $this->hashPath($path);
            $owner = fileowner($path);
            if (function_exists('posix_getpwuid')) {
              $userinfo = posix_getpwuid($owner);
              $owner = $userinfo['name'];
            }
            $fileinfo[$path] = 'mt:' . filemtime($path) . '$p:' . substr(sprintf('%o', fileperms($path)), -4) . '$o:' . $owner . '$s:' . filesize($path);
          }
        }
      }
      closedir($handle);
    }

    return array($hashes, $fileinfo);
  }


  /**
   * Determine if a path is a file type we care about for modificaitons.
   */
  private function isManifestType($path) {
    $extensions = array(
      'php' => 1,
      'php4' => 1,
      'php5' => 1,
      'module' => 1,
      'inc' => 1,
      'install' => 1,
      'test' => 1,
      'theme' => 1,
      'engine' => 1,
      'profile' => 1,
      'css' => 1,
      'js' => 1,
      'info' => 1,
      'sh' => 1,
      // SSL certificates
      'pem' => 1,
      'pl' => 1,
      'pm' => 1,
    );
    $pathinfo = pathinfo($path);
    return isset($pathinfo['extension']) && isset($extensions[$pathinfo['extension']]);
  }

  /**
   * Calculate the sha1 hash for a path.
   *
   * @param string $path
   *   The name of the file or a directory.
   * @return string
   *   bas64 encoded sha1 hash. 'hash' is an empty string for directories.
   */
  private function hashPath($path = '') {
    $hash = '';
    if (file_exists($path)) {
      if (!is_dir($path)) {
        $string = file_get_contents($path);
        // Remove trailing whitespace
        $string = rtrim($string);
        // Replace all line endings and CVS/svn Id tags
        $string = preg_replace('/\$Id[^;<>{}\(\)\$]*\$/', 'x$' . 'Id$', $string);
        $string = preg_replace('/\r\n|\n|\r/', ' ', $string);
        $hash =  base64_encode(pack("H*", sha1($string)));
      }
    }
    return $hash;
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
   * Gather full SPI data and send to Acquia Network.
   *
   * @return mixed FALSE if data not sent else NSPI result array
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

    $this->handleServerResponse($response);

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
   * Act on specific elements of SPI update server response.
   *
   * @param array $spi_response Array response from SpiController->send().
   */
  private function handleServerResponse($spi_response) {
    // Check result for command to update SPI definition.
    $update = isset($spi_response['body']['update_spi_definition']) ? $spi_response['body']['update_spi_definition'] : FALSE;
    if ($update === TRUE) {
      // @todo: refactor
      $this->updateDefinition();
    }
    // Check for set_variables command.
    $set_variables = isset($spi_response['body']['set_variables']) ? $spi_response['body']['set_variables'] : FALSE;
    if ($set_variables !== FALSE) {
      // @todo: refactor
      $this->setVariables($set_variables);
    }
    // Log messages.
    $messages = isset($spi_response['body']['nspi_messages']) ? $spi_response['body']['nspi_messages'] : FALSE;
    if ($messages !== FALSE) {
      \Drupal::logger('acquia spi')->notice('SPI update server response messages: @messages', array('@messages' => implode(', ', $messages)));
    }
  }

  /**
   * Set variables from NSPI response.
   *
   * @param  array $set_variables Variables to be set.
   * @return NULL
   */
  private function setVariables($set_variables) {
    // @todo: refactor
    return;
    if (empty($set_variables)) {
      return;
    }
    $saved = array();
    $ignored = variable_get('acquia_spi_ignored_set_variables', array());

    if (!variable_get('acquia_spi_set_variables_override', 0)) {
      $ignored[] = 'acquia_spi_set_variables_automatic';
    }
    // Some variables can never be set.
    $ignored = array_merge($ignored, array('drupal_private_key', 'site_mail', 'site_name', 'maintenance_mode', 'user_register'));
    // Variables that can be automatically set.
    $whitelist = acquia_spi_approved_set_variables();
    foreach($set_variables as $key => $value) {
      // Approved variables get set immediately unless ignored.
      if (in_array($key, $whitelist) && !in_array($key, $ignored)) {
        $saved[] = $key;
        variable_set($key, $value);
      }
    }
    if (!empty($saved)) {
      variable_set('acquia_spi_saved_variables', array('variables' => $saved, 'time' => time()));
      watchdog('acquia spi', 'Saved variables from the Acquia Network: @variables', array('@variables' => implode(', ', $saved)), WATCHDOG_INFO);
    }
    else {
      watchdog('acquia spi', 'Did not save any variables from the Acquia Network.', array(), WATCHDOG_INFO);
    }
  }

  /**
   * Checks if NSPI server has an updated SPI data definition.
   * If it does, then this function updates local copy of SPI definition data.
   *
   * @return boolean
   *   True if SPI definition data has been updated
   */
  private function updateDefinition() {
    $core_version = substr(\Drupal::VERSION, 0, 1);
    $spi_def_end_point = $this->config('acquia_connector.settings')->get('spi.server');
    $spi_def_end_point .= '/spi_def/get/' . $core_version;
    // @todo: refactor
    return;
    $options = array(
      'method' => 'GET',
      'headers' => array('Content-type' => 'application/json'),
      'data' => drupal_http_build_query(array('spi_data_version' => ACQUIA_SPI_DATA_VERSION))
    );
    $response = drupal_http_request($spi_def_end_point, $options);
    if ($response->code != 200 || !isset($response->data)) {
      \Drupal::logger('acquia spi')->error('Failed to obtain latest SPI data definition. HTTP response: @response', array('@response' => var_export($response, TRUE)));
      return FALSE;
    }
    else {
      $response_data = drupal_json_decode($response->data);
      $expected_data_types = array(
        'drupal_version' => 'string',
        'timestamp' => 'string',
        'acquia_spi_variables' => 'array',
      );
      // Make sure that $response_data contains everything expected.
      foreach($expected_data_types as $key => $values) {
        if (!array_key_exists($key, $response_data) || gettype($response_data[$key]) != $expected_data_types[$key]) {
          \Drupal::logger('acquia spi')->error('Received SPI data definition does not match expected pattern while checking "@key". Received and expected data: @data', array('@key' => $key, '@data' => var_export(array_merge(array('expected_data' => $expected_data_types), array('response_data' => $response_data)), 1), TRUE));
          return FALSE;
        }
      }
      if ($response_data['drupal_version'] != $core_version) {
        \Drupal::logger('acquia spi')->notice('Received SPI data definition does not match with current version of your Drupal installation. Data received for Drupal @version', array('@version' => $response_data['drupal_version']));
        return FALSE;
      }
    }

    // NSPI response is in expected format.
    if ((int) $response_data['timestamp'] > (int) $this->config('acquia_connector.settings')->get('spi.def_timestamp', 0)) {
      // Compare stored variable names to incoming and report on update.
      $old_vars = $this->config('acquia_connector.settings')->get('spi.def_vars', array());
      $new_vars = $response_data['acquia_spi_variables'];
      $new_optional_vars = 0;
      foreach($new_vars as $new_var_name => $new_var) {
        // Count if received from NSPI optional variable is not present in old local SPI definition
        // or if it already was in old SPI definition, but was not optional
        if ($new_var['optional'] && !array_key_exists($new_var_name, $old_vars) ||
          $new_var['optional'] && isset($old_vars[$new_var_name]) && !$old_vars[$new_var_name]['optional']) {
          $new_optional_vars++;
        }
      }
      // Clean up waived vars that are not exposed by NSPI anymore.
      $waived_spi_def_vars = $this->config('acquia_connector.settings')->get('spi.def_waived_vars', array());
      $changed_bool = FALSE;
      foreach($waived_spi_def_vars as $key => $waived_var) {
        if (!in_array($waived_var, $new_vars)) {
          unset($waived_spi_def_vars[$key]);
          $changed_bool = TRUE;
        }
      }
      if ($changed_bool) {
        $this->config('acquia_connector.settings')->set('spi.def_waived_vars', $waived_spi_def_vars);
      }
      // Finally, save SPI definition data.
      if ($new_optional_vars > 0) {
        $this->config('acquia_connector.settings')->set('spi.new_optional_data', 1);
      }
      $this->config('acquia_connector.settings')->set('spi.def_timestamp', $response_data['timestamp']);
      $this->config('acquia_connector.settings')->set('spi.def_vars', $response_data['acquia_spi_variables']);
      $this->config('acquia_connector.settings')->save();
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Access callback check for SPI send independent call.
   */
  public function sendAccess() {
    $request = \Drupal::request();
    $acquia_key = $this->config('acquia_connector.settings')->get('key');

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
                \Drupal::logger('acquia spi test')->notice("<em>Custom test validation failed</em>: @message for parameter '!param_name'; current value '!value'. (<em>Test '!test_name' in module '!module_name'</em>)", $variables);
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
