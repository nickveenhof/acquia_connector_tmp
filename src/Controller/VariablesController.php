<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\VariablesController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\NodeType;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility;

/**
 * Class MappingController.
 */
class VariablesController extends ControllerBase {

  protected $mapping = array(
    'acquia_spi_send_node_user' => array('acquia_connector.settings', 'spi', 'send_node_user'), //@todo: Values returned only over SSL
    'acquia_spi_admin_priv' => array('acquia_connector.settings', 'spi', 'admin_priv'), //@todo: Values returned only over SSL
    'acquia_spi_module_diff_data' => array('acquia_connector.settings', 'spi', 'module_diff_data'), //@todo: Values returned only over SSL
    'acquia_spi_send_watchdog' => array('acquia_connector.settings', 'spi', 'send_watchdog'), //@todo: Values returned only over SSL
    'acquia_spi_use_cron' => array('acquia_connector.settings', 'use_cron'), //@todo: Values returned only over SSL
    'cache_backends' => array(),//@todo:  memcache and oter external  Values returned only over SSL
    'cache_default_class' => array('cache_classes', 'cache'),//@todo:  memcache and oter external  Values returned only over SSL
    'cache_inc' => array(),//@todo:  memcache and oter external  Values returned only over SSL
    'cron_safe_threshold' => array('system.cron', 'threshold', 'autorun'), //working
    'googleanalytics_cache' => array(),// @todo
    'error_level' => array('system.logging', 'error_level'), // @todo: Needs mapping sends the value
    'preprocess_js' => array('system.performance', 'js', 'preprocess'),//working
    'page_cache_maximum_age' => array('system.performance', 'cache', 'page', 'max_age'),//working
    'block_cache' => array(),// @todo NOT USED
    'preprocess_css' => array('system.performance', 'css', 'preprocess'),//working
    'page_compression' => array('system.performance', 'response', 'gzip'),//working
    'cache' => array('system.performance', 'cache', 'page', 'use_internal'), //working
    'cache_lifetime' => array(), // @todo: NOT USED
    'cron_last' => array('state', 'system.cron_last'), //@todo: Not variable. \Drupal::state()->get('system.cron_last'). Values returned only over SSL
    'clean_url' => array(),// @todo: Removed. @see https://www.drupal.org/node/1659580
    'redirect_global_clean' => array(),// @todo used in Global redirect module  Values returned only over SSL
    'theme_zen_settings' => array(),// @todo no release for Drupal 8
    'site_offline' => array('state', 'system.maintenance_mode'),  // @todo duplicate of the maintenance_mode variable.
    'site_name' => array('system.site', 'name'), //working
    'user_register' => array('user.settings', 'register'),//@todo:   Values returned only over SSL
    'user_signatures' => array('user.settings', 'signatures'),//@todo:   Values returned only over SSL
    'user_admin_role' => array('user.settings', 'admin_role'),//@todo:   Values returned only over SSL
    'user_email_verification' => array('user.settings', 'verify_mail'),//@todo:   Values returned only over SSL
    'user_cancel_method' => array('user.settings', 'cancel_method'),//@todo:   Values returned only over SSL
    'filter_fallback_format' => array('filter.settings', 'fallback_format'),//@todo:   Values returned only over SSL
    'dblog_row_limit' => array('dblog.settings', 'row_limit'),//@todo:   Values returned only over SSLx`xxx
    'date_default_timezone' => array('system.date', 'timezone', 'default'),//@todo:   Values returned only over SSL
    'file_default_scheme' => array('system.file', 'default_scheme'),//@todo:   Values returned only over SSL
    'install_profile' => array('callback'),// @todo: @see https://www.drupal.org/node/2235431
    'maintenance_mode' => array('state', 'system.maintenance_mode'),//@todo:   Values returned only over SSL Not variable. \Drupal::state()->get('system.maintenance_mode').
    'update_last_check' => array('state', 'update.last_check'),//@todo:   Values returned only over SSL Not variable. \Drupal::state()->get('update.last_check').
    'site_default_country' => array('system.date', 'country', 'default'),//@todo:   Values returned only over SSL
    'acquia_spi_saved_variables' => array('acquia_connector.settings', 'spi', 'saved_variables'),//working
    'acquia_spi_set_variables_automatic' => array('acquia_connector.settings', 'spi', 'set_variables_automatic'),//working
    'acquia_spi_ignored_set_variables' => array('acquia_connector.settings', 'spi', 'ignored_set_variables'),//working
    'acquia_spi_set_variables_override' => array('acquia_connector.settings', 'spi', 'set_variables_override'),//working
    // @todo: Good variables to add
    'fast_404' => array('system.performance', 'fast_404', 'enabled'),
    'allow_insecure_uploads' => array('system.file', 'allow_insecure_uploads'),
  );

  /**
   * @param string $variable
   * @return NULL|value
   */
  public function mappingCallback($variable) {
    switch ($variable) {
      case 'install_profile':
        return Settings::get('install_profile');
    }
    return NULL;
  }

  /**
   * Load configs for all enabled modules.
   *
   * @return array
   */
  public function getAllConfigs() {
    $result = array();
    $names = \Drupal::configFactory()->listAll();
    foreach ($names as $key => $config_name) {
      $result[$config_name] = \Drupal::config($config_name)->get();
    }
    return $result;
  }

  /**
   * Get all system variables
   *
   * @return array()
   * D7: acquia_spi_get_variables_data
   */
  public function getVariablesData() {
    $data = array();
    $variables =  array('acquia_spi_send_node_user', 'acquia_spi_admin_priv', 'acquia_spi_module_diff_data', 'acquia_spi_send_watchdog', 'acquia_spi_use_cron', 'cache_backends', 'cache_default_class', 'cache_inc', 'cron_safe_threshold', 'googleanalytics_cache', 'error_level', 'preprocess_js', 'page_cache_maximum_age', 'block_cache', 'preprocess_css', 'page_compression', 'cache', 'cache_lifetime', 'cron_last', 'clean_url', 'redirect_global_clean', 'theme_zen_settings', 'site_offline', 'site_name', 'user_register', 'user_signatures', 'user_admin_role', 'user_email_verification', 'user_cancel_method', 'filter_fallback_format', 'dblog_row_limit', 'date_default_timezone', 'file_default_scheme', 'install_profile', 'maintenance_mode', 'update_last_check', 'site_default_country', 'acquia_spi_saved_variables', 'acquia_spi_set_variables_automatic', 'acquia_spi_ignored_set_variables', 'acquia_spi_set_variables_override');

    $allConfigData = self::getAllConfigs();
    $spi_def_vars = \Drupal::config('acquia_connector.settings')->get('spi.def_vars');
    $waived_spi_def_vars = \Drupal::config('acquia_connector.settings')->get('spi.def_waived_vars');
    // Merge hard coded $variables with vars from SPI definition.
    foreach($spi_def_vars as $var_name => $var) {
      if (!in_array($var_name, $waived_spi_def_vars) && !in_array($var_name, $variables)) {
        $variables[] = $var_name;
      }
    }

    // @todo - implement comments settings for D8
    // Add comment settings for node types.
    $types = NodeType::loadMultiple();
    if (!empty($types)) {
      foreach ($types as $name => $NodeType) {
//        dpm('Node type: ' . $NodeType->type);   // @todo: $nodeType->type removed in latest dev.
//        $variables[] = 'comment_' . $name;
      }
    }
    foreach ($variables as $name) {
      if (!empty($this->mapping[$name])) {
        // state
        if ($this->mapping[$name][0] == 'state' and !empty($this->mapping[$name][1])) {
          dpm('YES! (state):' . $name . ' = ' . print_r(\Drupal::state()->get($this->mapping[$name][1]), 1)); // @todo: remove dpm
          $data[$name] = \Drupal::state()->get($this->mapping[$name][1]);
        }
        elseif($this->mapping[$name][0] == 'callback') {
          $data[$name] = self::mappingCallback($name);
        }
        // variable
        else {
          $key_exists = NULL;
          $value = Utility\NestedArray::getValue($allConfigData, $this->mapping[$name], $key_exists);
          if ($key_exists) {
            $data[$name] = $value;
          }
          else {
            dpm('YES! (variable - not set):' . $name . ' = 0' ); // @todo: remove dpm
            $data[$name] = 0;
          }
        }
      }
      else {
        // @todo: log errors
        dpm('Variable is not implemented: ' . $name);
      }
    }

    // Exception handling.
//    $data['cron_last'] = \Drupal::state()->get('system.cron_last');
//    $data['maintenance_mode'] = \Drupal::state()->get('system.maintenance_mode');
//    $data['update_last_check'] = \Drupal::state()->get('update.last_check');
    // @todo - the module highly unstable!
//    if (\Drupal::moduleHandler()->moduleExists('globalredirect') && function_exists('_globalredirect_get_settings')) {
//      // Explicitly get Global Redirect settings since it deletes its variable
//      // if the settings match the defaults.
//      $data['globalredirect_settings'] = _globalredirect_get_settings();
//    }

    // Drush overrides cron_safe_threshold so extract DB value if sending via drush.
    // @todo research it for D8
//    if (PHP_SAPI === 'cli') {
//      $cron_safe_threshold = acquia_spi_get_db_variable('cron_safe_threshold');
//      $data['cron_safe_threshold'] = !is_null($cron_safe_threshold) ? $cron_safe_threshold : DRUPAL_CRON_DEFAULT_THRESHOLD;
//    }

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
   * Set variables from NSPI response.
   *
   * @param  array $set_variables Variables to be set.
   * @return NULL
   * D7: acquia_spi_set_variables
   */
  public function setVariables($set_variables) {
    dpm('----set variables----');
    dpm($set_variables);
    \Drupal::logger('acquia spi')->notice('SPI set variables: @messages', array('@messages' => implode(', ', $set_variables)));
    if (empty($set_variables)) {
      return;
    }
    $saved = array();
    $ignored = \Drupal::config('acquia_connector.settings')->get('spi.ignored_set_variables');

    if (!\Drupal::config('acquia_connector.settings')->get('spi.set_variables_override')) {
      $ignored[] = 'acquia_spi_set_variables_automatic';
    }
    // Some variables can never be set.
    $ignored = array_merge($ignored, array('drupal_private_key', 'site_mail', 'site_name', 'maintenance_mode', 'user_register'));
    // Variables that can be automatically set.
    $whitelist = \Drupal::config('acquia_connector.settings')->get('spi.set_variables_automatic');
    foreach($set_variables as $key => $value) {
      // Approved variables get set immediately unless ignored.
      if (1 || in_array($key, $whitelist) && !in_array($key, $ignored)) { // @todo: remove 1
        if (!empty($this->mapping[$key])) {
          // state
          if ($this->mapping[$key][0] == 'state' and !empty($this->mapping[$key][1])) {
            dpm('Set Variable (state):' . $key . ' = ' . print_r(\Drupal::state()->get($this->mapping[$key][1]), 1)); // @todo: remove dpm
            \Drupal::state()->set($this->mapping[$key][1], $value);
            $saved[] = $key;
          }
          elseif($this->mapping[$key][0] == 'callback') {
            // @todo implemets setter
          }
          // variable
          else {
            dpm('Set Variable (variable):' . $key . ' = ' . print_r($value, 1)); // @todo: remove dpm
            $mapping_row_copy = $this->mapping[$key];
            $config_name = array_shift($mapping_row_copy);
            $variable_name = implode('.', $mapping_row_copy);
            \Drupal::configFactory()->getEditable($config_name)->set($variable_name, $value);
            \Drupal::configFactory()->getEditable($config_name)->save();
            $saved[] = $key;
          }
        }
        // todo: for future D8 implementation. "config.name:variable.name"
        elseif (preg_match('/^([^\s]+):([^\s]+)$/ui', $key, $regs)) {
          $config_name = $regs[1];
          $variable_name = $regs[2];
          \Drupal::configFactory()->getEditable($config_name)->set($variable_name, $value);
          \Drupal::configFactory()->getEditable($config_name)->save();
          $saved[] = $key;
          dpm('Set Variable (variable):' . $key . ' = ' . print_r($value, 1)); // @todo: remove dpm
        }
        else {
          // @todo: log errors
          dpm('Variable is not implemented (set): ' . $key);
        }
      }
    }
    if (!empty($saved)) {
      \Drupal::configFactory()->getEditable('acquia_connector.settings')->set('spi.saved_variables', array('variables' => $saved, 'time' => time()));
      \Drupal::configFactory()->getEditable('acquia_connector.settings')->save();
      \Drupal::logger('acquia spi')->notice('Saved variables from the Acquia Network: @variables', array('@variables' => implode(', ', $saved)));
    }
    else {
      \Drupal::logger('acquia spi')->notice('Did not save any variables from the Acquia Network.', array());
    }
  }

}
