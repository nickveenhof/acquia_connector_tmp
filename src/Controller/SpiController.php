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
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\acquia_connector\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\RfcLogLevel;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Drupal\user\Entity\Role;

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
   * D7: acquia_spi_get
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
      'rpc_version'    => ACQUIA_SPI_DATA_VERSION,      // Used in HMAC validation
      'spi_data_version' => ACQUIA_SPI_DATA_VERSION,    // Used in Fix it now feature
      'site_key'       => sha1(\Drupal::service('private_key')->get()),
      'modules'        => $this->getModules(),
      'platform'       => $platform,
      'quantum'        => $this->getQuantum(),
      'system_status'  => $this->getSystemStatus(),
      'failed_logins'  => $this->config('acquia_connector.settings')->get('spi.send_watchdog') ? $this->getFailedLogins() : array(),
      '404s'           => $this->config('acquia_connector.settings')->get('spi.send_watchdog') ? $this->get404s() : array(),
      'watchdog_size'  => $this->getWatchdogSize(),
      'watchdog_data'  => $this->config('acquia_connector.settings')->get('spi.send_watchdog') ? $this->getWatchdogData() : array(),
      'last_nodes'     => $this->config('acquia_connector.settings')->get('spi.send_node_user') ? $this->getLastNodes() : array(),
      'last_users'     => $this->config('acquia_connector.settings')->get('spi.send_node_user') ? $this->getLastUsers() : array(),
      'extra_files'    => $this->checkFilesPresent(),
      'ssl_login'      => $this->checkLogin(),
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
    if ($this->config('acquia_connector.settings')->get('spi.ssl_override')) {
      $via_ssl = TRUE;
    }

    $additional_data = array();

    // @todo: security_review module for D8 not released yet.
    $security_review = new SecurityReviewController();
    $security_review_results = $security_review->runSecurityReview();
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
    $custom_tests_results = $this->testCollect();
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
    $additional_data['pending_updates'] = FALSE;
    foreach (\Drupal::moduleHandler()->getModuleList() as $module => $filename) {
      $updates = drupal_get_schema_versions($module);
      if ($updates !== FALSE) {
        $default = drupal_get_installed_schema_version($module);
        if (max($updates) > $default) {
          $additional_data['pending_updates'] = TRUE;
          break;
        }
      }
    }
    if (!$additional_data['pending_updates'] && \Drupal::service('entity.definition_update_manager')->needsUpdates()) {
      $additional_data['pending_updates'] = TRUE;
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
      $variablesController = new VariablesController();
      // Values returned only over SSL
      $spi_ssl = array(
        'system_vars' => $variablesController->getVariablesData(),
        'settings_ra' => $this->getSettingsPermissions(),
        'admin_count' => $this->config('acquia_connector.settings')->get('admin_priv') ? $this->getAdminCount() : '',
        'admin_name' => $this->config('acquia_connector.settings')->get('admin_priv') ? $this->getSuperName() : '',
      );

      return array_merge($spi, $spi_ssl);
    }
  }

  /**
   * Collects all user-contributed test results that pass validation.
   *
   * @return array $custom_data
   *  An associative array containing properly formatted user-contributed tests.
   * D7: acquia_spi_test_collect
   */
  private function testCollect() {
    $custom_data = array();

    // Collect all custom data provided by hook_insight_custom_data().
    $collections = \Drupal::moduleHandler()->invokeAll('acquia_spi_test');

    foreach ($collections as $test_name => $test_params) {
      $status = new TestStatusController();
      $result = $status->testValidate(array($test_name => $test_params));

      if ($result['result']) {
        $custom_data[$test_name] = $test_params;
      }
    }

    return $custom_data;
  }

  /**
   * Checks to see if SSL login is required
   *
   * @param n/a
   *
   * @return int 1|0
   * D7: acquia_spi_check_login
   */
  private function checkLogin() {
    $login_safe = 0;
    // @todo: securepages not ported yet.
    if (\Drupal::moduleHandler()->moduleExists('securepages')) {
//      if (drupal_match_path('user/login', variable_get('securepages_pages', ''))) {
//        $login_safe = 1;
//      }
//      if (drupal_match_path('user/login', variable_get('securepages_ignore', ''))) {
//        $login_safe = 0;
//      }
//      if (!variable_get('securepages_secure', FALSE) || !variable_get('securepages_enable', FALSE)) {
//        $login_safe = 0;
//      }
    }
    elseif (\Drupal::moduleHandler()->moduleExists('securelogin')) {
      $secureLoginConfig = $this->config('securelogin.settings')->get();
      if ($secureLoginConfig['all_forms']) {
        $forms_safe = TRUE;
      }
      else {
        // All the required forms should be enabled.
        $required_forms = array(
          'form_user_login_form',
          'form_user_form',
          'form_user_register_form',
          'form_user_pass_reset',
          'form_user_pass',
        );
        $forms_safe = TRUE;
        foreach ($required_forms as $form_variable) {
          if (!$secureLoginConfig[$form_variable]) {
            $forms_safe = FALSE;
            break;
          }
        }
      }
      // \Drupal::request()->isSecure() ($conf['https'] in D7) should be false for expected behavior
      if ($forms_safe && !\Drupal::request()->isSecure())  {
        $login_safe = 1;
      }
    }

    return $login_safe;
  }

  /**
   * Check to see if the unneeded release files with Drupal are removed
   *
   * @param n/a
   *
   * @return int 1|0
   *   True if they are removed, false if they aren't
   * D7: acquia_spi_check_files_present
   */
  private function checkFilesPresent() {
    $store = $this->dataStoreGet(array('platform'));
    $server = (!empty($store) && isset($store['platform'])) ? $store['platform']['php_quantum']['SERVER'] : \Drupal::request()->server->all();
    $files_exist = FALSE;
    $files_to_remove = array('CHANGELOG.txt', 'COPYRIGHT.txt', 'INSTALL.mysql.txt', 'INSTALL.pgsql.txt', 'INSTALL.txt', 'LICENSE.txt',
      'MAINTAINERS.txt', 'README.txt', 'UPGRADE.txt', 'PRESSFLOW.txt', 'install.php');

    foreach ($files_to_remove as $file) {
      $path = $server['DOCUMENT_ROOT'] . base_path() . $file;
      if (file_exists($path))
        $files_exist = TRUE;
    }

    return $files_exist ? 1 : 0;
  }

  /**
   * Get last 15 users created. Useful for determining if your site is compromised.
   *
   * @return array
   *   The details of last 15 users created.
   * D7: acquai_spi_get_last_users
   */
  private function getLastUsers() {
    $last_five_users = array();
    $result = db_select('users_field_data', 'u')
      ->fields('u', array('uid', 'name', 'mail', 'created'))
      ->condition('u.created', REQUEST_TIME - 3600, '>')
      ->orderBy('created', 'DESC')
      ->range(0, 15)
      ->execute();

    $count = 0;
    foreach ($result as $record) {
      $last_five_users[$count]['uid'] = $record->uid;
      $last_five_users[$count]['name'] = $record->name;
      $last_five_users[$count]['email'] = $record->mail;
      $last_five_users[$count]['created'] = $record->created;
      $count++;
    }
//TODO is this what we really want?
    return $last_five_users;
  }

  /**
   * Get last 15 nodes created--this can be useful to determine if you have some
   * sort of spamme on your site
   *
   * @param n/a
   *
   * @return array of the details of last 15 nodes created
   * D7: acquai_spi_get_last_nodes
   */
  private function getLastNodes() {
    $last_five_nodes = array();
    $result = db_select('node_field_data', 'n')
      ->fields('n', array('title', 'type', 'nid', 'created', 'langcode'))
      ->condition('n.created', REQUEST_TIME - 3600, '>')
      ->orderBy('n.created', 'DESC')
      ->range(0, 15)
      ->execute();

    $count = 0;
    foreach ($result as $record) {
      $last_five_nodes[$count]['url'] = \Drupal::service('path.alias_manager')->getAliasByPath('node/' . $record->nid, $record->langcode);;
      $last_five_nodes[$count]['title'] = $record->title;
      $last_five_nodes[$count]['type'] = $record->type;
      $last_five_nodes[$count]['created'] = $record->created;
      $count++;
    }

    return $last_five_nodes;
  }

  /**
   * Get the latest (last hour) critical and emergency warnings from watchdog
   * These errors are 'severity' 0 and 2.
   *
   * @param n/a
   *
   * @return array
   * D7: acquia_spi_get_watchdog_data
   */
  private function getWatchdogData() {
    $wd = array();
    if (\Drupal::moduleHandler()->moduleExists('dblog')) {
      $result = db_select('watchdog', 'w')
        ->fields('w', array('wid', 'severity', 'type', 'message', 'timestamp'))
        ->condition('w.severity', array(RfcLogLevel::EMERGENCY, RfcLogLevel::CRITICAL), 'IN')
        ->condition('w.timestamp', REQUEST_TIME - 3600, '>')
        ->execute();

      while ($record = $result->fetchAssoc()) {
        $wd[$record['severity']] = $record;
      }
    }

    return $wd;
  }

  /**
   * Get the number of rows in watchdog
   *
   * @return int
   * D7: acquai_spi_get_watchdog_size
   */
  private function getWatchdogSize() {
    if (\Drupal::moduleHandler()->moduleExists('dblog')) {
      return db_select('watchdog', 'w')->fields('w', array('wid'))->countQuery()->execute()->fetchField();
    }
  }


  /**
   * Grabs the last 404 errors in logs, excluding the checks we run for drupal files like README
   *
   * @return array
   *   An array of the pages not found and some associated data
   * D7: acquai_spi_get_404s
   */
  private function get404s() {
    $data = array();
    $row = 0;

    if (\Drupal::moduleHandler()->moduleExists('dblog')) {
      $result = db_select('watchdog', 'w')
        ->fields('w', array('message', 'hostname', 'referer', 'timestamp'))
        ->condition('w.type', 'page not found', '=')
        ->condition('w.timestamp', REQUEST_TIME - 3600, '>')
        ->condition('w.message', array("UPGRADE.txt", "MAINTAINERS.txt", "README.txt", "INSTALL.pgsql.txt", "INSTALL.txt", "LICENSE.txt", "INSTALL.mysql.txt", "COPYRIGHT.txt", "CHANGELOG.txt"), 'NOT IN')
        ->orderBy('w.timestamp', 'DESC')
        ->range(0, 10)
        ->execute();

      foreach ($result as $record) {
        $data[$row]['message'] = $record->message;
        $data[$row]['hostname'] = $record->hostname;
        $data[$row]['referer'] = $record->referer;
        $data[$row]['timestamp'] = $record->timestamp;
        $row++;
      }
    }

    return $data;
  }

  /**
   * Get the information on failed logins in the last cron interval
   *
   * @param n/a
   *
   * @return array
   * D7: acquia_spi_get_failed_logins
   */
  private function getFailedLogins() {
    $last_logins = array();
    $cron_interval = $this->config('acquia_connector.settings')->get('spi.cron_interval');

    if (\Drupal::moduleHandler()->moduleExists('dblog')) {
      $result = db_select('watchdog', 'w')
        ->fields('w', array('message', 'variables', 'timestamp'))
        ->condition('w.message', 'login attempt failed%', 'LIKE')
        ->condition('w.timestamp', REQUEST_TIME - $cron_interval, '>')
        ->condition('w.message', array("UPGRADE.txt", "MAINTAINERS.txt", "README.txt", "INSTALL.pgsql.txt", "INSTALL.txt", "LICENSE.txt", "INSTALL.mysql.txt", "COPYRIGHT.txt", "CHANGELOG.txt"), 'NOT IN')
        ->orderBy('w.timestamp', 'DESC')
        ->range(0, 10)
        ->execute();

      foreach ($result as $record) {
        $variables = unserialize($record->variables);
        if (!empty($variables['%user'])) {
          $last_logins['failed'][$record->timestamp] = String::checkPlain($variables['%user']);
        }
      }
    }
    return $last_logins;
  }

  /**
   * This function is a trimmed version of Drupal's system_status function
   *
   * @return array
   * D7: acquia_spi_get_system_status
   */
  private function getSystemStatus() {
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
   * Check the presence of UID 0 in the users table.
   *
   * @return bool Whether UID 0 is present.
   * D7: acquia_spi_uid_0_present
   */
  private function getUidZerroIsPresent() {
    $count = db_query("SELECT uid FROM {users} WHERE uid = 0")->fetchAll();
    return (boolean) $count;
  }

  /**
   * The number of users who have admin-level user roles.
   *
   * @return int
   * D7: acquia_spi_get_admin_count
   */
  private function getAdminCount() {
    $roles_name = array();
    $get_roles = Role::loadMultiple();
    unset($get_roles[DRUPAL_ANONYMOUS_RID]);
    $permission = array('administer permissions', 'administer users');
    foreach ($permission as $key => $value) {
      $filtered_roles = array_filter($get_roles, function ($role) use ($value) {
        return $role->hasPermission($value);
      });
      foreach ($filtered_roles as $role_name => $data) {
        $roles_name[] = $role_name;
      }
    }

    if (!empty($roles_name)) {
      $roles_name_unique = array_unique($roles_name);
      $query = db_select('user__roles', 'ur');
      $query->fields('ur', array('entity_id'));
      $query->condition('ur.bundle', 'user', '=');
      $query->condition('ur.deleted', '0', '=');
      $query->condition('ur.roles_target_id', $roles_name_unique, 'IN');
      $count = $query->countQuery()->execute()->fetchField();
    }

    return (isset($count) && is_numeric($count)) ? $count : NULL;
  }

  /**
   * Determine if the super user has a weak name
   *
   * @return int 0|1
   * D7: acquia_spi_get_super_name
   */
  private function getSuperName() {
    $result = db_query("SELECT name FROM {users_field_data} WHERE uid = 1 AND (name LIKE '%admin%' OR name LIKE '%root%')")->fetch();
    return (int) $result;
  }

  /**
   * Determines if settings.php is read-only
   *
   * @return boolean
   * D7: acquia_spi_get_settings_permissions
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
   * D7: acquia_spi_file_hashes
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
   * D7: _acquia_spi_generate_hashes
   */
  private function generateHashes($dir, $exclude_dirs = array(), $limit_dirs = array(), $module_break = FALSE, $orig_dir=NULL) {
    $hashes = array();
    $fileinfo = array();

    // Ensure that we have not nested into another module's dir
    if ($dir != $orig_dir && $module_break) {
      if (is_dir($dir) && $handle = opendir($dir)) {
        while ($file = readdir($handle)) {
          if (stristr($file, '.info.yml')) {
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
        if (!in_array($file, array('.', '..', 'CVS', '.svn', '.git'))) {
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
   * D7: acquia_spi_is_manifest_type
   */
  private function isManifestType($path) {
    $extensions = array(
      'yml' => 1,
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
   * D7: acquia_spi_hash_path
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
   * D7: acquia_spi_get_version_info
   */
  private function getVersionInfo() {
    $store = $this->dataStoreGet(array('platform'));
    $server = (!empty($store) && isset($store['platform'])) ? $store['platform']['php_quantum']['SERVER'] : \Drupal::request()->server->all();
    $ver = array();

    $ver['base_version'] = \Drupal::VERSION;
    $install_root = $server['DOCUMENT_ROOT'] . base_path();
    $ver['distribution']  = '';

    // Determine if this puppy is Acquia Drupal
    acquia_connector_load_versions();

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
   * Put SPI data in local storage.
   *
   * @param array $data Keyed array of data to store.
   * @param int $expire Expire time or null to use default of 1 day.
   */
  public function dataStoreSet($data, $expire = NULL) {
    if (is_null($expire)) {
      $expire = REQUEST_TIME + (60*60*24);
    }
    foreach ($data as $key => $value) {
      \Drupal::cache()->set('acquia.spi.' . $key, $value, $expire);
    }
  }

  /**
   * Get SPI data out of local storage.
   *
   * @param array Array of keys to extract data for.
   *
   * @return array Stored data or false if no data is retrievable from storage.
   * D7: acquia_spi_data_store_get
   */
  public function dataStoreGet($keys) {
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
  public static function getPlatform() {
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
      'mysql'             => (Database\Database::getConnection()->driver() == 'mysql') ? self::getPlatformMysqlData() : array(),
    );

    return $platform;
  }

  /**
   * Gather mysql specific information.
   *
   * @return array
   *   An associative array keyed by a mysql information type.
   */
  private static function getPlatformMysqlData() {
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
   * D7: acquia_spi_get_modules
   */
  private function getModules() {
    // @todo add cache if possible.
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
    $keys_to_send = array('name', 'version', 'package', 'core', 'project');
    foreach ($modules as $module_key => $module) {
      $info = array();
      $info['status'] = $module->status;
      foreach ($keys_to_send as $key) {
        $info[$key] = isset($module->info[$key]) ? $module->info[$key] : '';
      }
      $info['filename'] = $module->getPathname();
      if (empty($info['project']) && $module->origin == 'core') {
        $info['project'] = 'drupal';
      }

      // Determine which files belong to this module and hash them
      $module_path = explode('/', $info['filename']);
      array_pop($module_path);

      // We really only care about this module if it is in 'sites' or in 'modules' folder.
      // Otherwise it is covered by the hash of the distro's modules
      if ($module_path[0] == 'sites' || $module_path[0] == 'modules') {
        $contrib_path = implode('/', $module_path);

        // Get a hash for this module's files. If we nest into another module, we'll return
        // and that other module will be covered by it's entry in the system table.
        //
        // !! At present we aren't going to do a per module hash, but rather a per-project hash. The reason being that it is
        // too hard to tell an individual module appart from a project
        //$info['module_data'] = _acquia_nspi_generate_hashes($contrib_path,array(),array(),TRUE,$contrib_path);
        list($info['module_data']['hashes'], $info['module_data']['fileinfo']) = self::_generateHashes($contrib_path);
      }
      else {
        $info['module_data']['hashes'] = array();
        $info['module_data']['fileinfo'] = array();
      }

      $result[] = $info;
    }
    return $result;
  }

  /**
   * Recursive helper function for acquia_spi_file_hashes().
   */
  private function _generateHashes($dir, $exclude_dirs = array(), $limit_dirs = array(), $module_break = FALSE, $orig_dir = NULL) {
    $hashes = array();
    $fileinfo = array();

    // Ensure that we have not nested into another module's dir
    if ($dir != $orig_dir && $module_break) {
      if (is_dir($dir) && $handle = opendir($dir)) {
        while ($file = readdir($handle)) {
          if (stristr($file, '.info.yml')) {
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
        if (!in_array($file, array('.', '..', 'CVS', '.svn', '.git'))) {
          $path = $dir == '.' ? $file : "{$dir}/{$file}";
          if (is_dir($path) && !in_array($path, $exclude_dirs) && (empty($limit_dirs) || in_array($path, $limit_dirs)) && ($file != 'translations')) {
            list($sub_hashes, $sub_fileinfo) =  $this->_generateHashes($path, $exclude_dirs);
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
   * Gather information about nodes, users and comments.
   *
   * @return array
   *   An associative array.
   * D7: acquia_spi_get_quantum
   */
  private function getQuantum() {
    $quantum = array();

    // Get only published nodes.
    $quantum['nodes'] = db_select('node_field_data', 'n')->fields('n', array('nid'))->condition('n.status', NODE_PUBLISHED)->countQuery()->execute()->fetchField();

    // Get only active users.
    $quantum['users'] = db_select('users_field_data', 'u')->fields('u', array('uid'))->condition('u.status', 1)->countQuery()->execute()->fetchField();

    if (\Drupal::moduleHandler()->moduleExists('comment')) {
      // Get only active comments.
      $quantum['comments'] = db_select('comment_field_data', 'c')->fields('c', array('cid'))->condition('c.status', 1)->countQuery()->execute()->fetchField();
    }

    return $quantum;
  }

  /**
   * Gather full SPI data and send to Acquia Network.
   *
   * @param string $method Optional identifier for the method initiating request.
   *   Values could be 'cron' or 'menu callback' or 'drush'.
   * @return mixed FALSE if data not sent else NSPI result array
   */
  public function sendFullSpi($method = '') {
    $spi = self::get($method);
    $config = $this->config('acquia_connector.settings');

    $response = $this->client->sendNspi($config->get('identifier'), $config->get('key'), $spi);

    // @todo: remove dpm.
    dpm($response);
    if ($response === FALSE) {
      return FALSE;
    }

    $config->set('cron_last', REQUEST_TIME)->save();
    $this->handleServerResponse($response);

    return $response;
  }

  /**
   * Gather full SPI data and send to Acquia Network.
   *
   * @return mixed FALSE if data not sent else NSPI result array
   */
  public function send(Request $request) {
    // Mark this page as being uncacheable.
    \Drupal::service('page_cache_kill_switch')->trigger();
    $method = ACQUIA_SPI_METHOD_CALLBACK;

    // Insight's set variable feature will pass method insight.
    if ($request->query->has('method') && ($request->query->get('method') === ACQUIA_SPI_METHOD_INSIGHT)) {
      $method = ACQUIA_SPI_METHOD_INSIGHT;
    }

    $response = $this->sendFullSpi($method);

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
      $route_match = $route = RouteMatch::createFromRequest($request);
      return $this->redirect($route_match->getRouteName(), $route_match->getRawParameters()->all());
    }
    return array();
//    throw new ServiceUnavailableHttpException(3, t('Error sending SPI data. Consult the logs for more information.'));
  }


  /**
   * Act on specific elements of SPI update server response.
   *
   * @param array $spi_response Array response from SpiController->send().
   * D7: acquia_spi_handle_server_response
   */
  private function handleServerResponse($spi_response) {
    // Check result for command to update SPI definition.
    $update = isset($spi_response['body']['update_spi_definition']) ? $spi_response['body']['update_spi_definition'] : FALSE;
    if ($update === TRUE) {
      $this->updateDefinition();
    }
    // Check for set_variables command.
    $set_variables = isset($spi_response['body']['set_variables']) ? $spi_response['body']['set_variables'] : FALSE;
    if ($set_variables !== FALSE) {
      $variablesController = new VariablesController();
      $variablesController->setVariables($set_variables);
    }
    // Log messages.
    $messages = isset($spi_response['body']['nspi_messages']) ? $spi_response['body']['nspi_messages'] : FALSE;
    if ($messages !== FALSE) {
      \Drupal::logger('acquia spi')->notice('SPI update server response messages: @messages', array('@messages' => implode(', ', $messages)));
    }
  }

  /**
   * Checks if NSPI server has an updated SPI data definition.
   * If it does, then this function updates local copy of SPI definition data.
   *
   * @return boolean
   *   True if SPI definition data has been updated
   * D7: acquia_spi_update_definition
   */
  private function updateDefinition() {
    $core_version = substr(\Drupal::VERSION, 0, 1);
    $spi_def_end_point = '/spi_def/get/' . $core_version;

    $response = $this->client->getDefinition($spi_def_end_point);
    dpm($response);

    if (!$response) {
      \Drupal::logger('acquia spi')->error('Failed to obtain latest SPI data definition.');
      return FALSE;
    }
    else {
      $response_data = $response;
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
    if ((int) $response_data['timestamp'] > (int) $this->config('acquia_connector.settings')->get('spi.def_timestamp')) {
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
}
