<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\SecurityReviewController
 */

namespace Drupal\acquia_connector\Controller;
use Drupal\Core\Controller\ControllerBase;

/**
 * Class SecurityReviewController.
 */

class SecurityReviewController extends ControllerBase {
  /**
   * Run some checks from the Security Review module.
   * D7: acquia_spi_run_security_review
   */
  private function runSecurityReview() {
    if (!$this->securityReviewCompatible()) {
      // Older versions of Security Review are not compatible and the results
      // cannot easily be retrieved.
      return array();
    }

    // Collect the checklist.
    $checklist = $this->securityReviewGetChecks();
    // Run only specific checks.
    $to_check = array('views_access', 'temporary_files', 'base_url_set', 'executable_php', 'input_formats', 'admin_permissions', 'untrusted_php', 'private_files', 'upload_extensions');
    foreach ($checklist as $module => $checks) {
      foreach ($checks as $check_name => $args) {
        if (!in_array($check_name, $to_check)) {
          unset($checklist[$module][$check_name]);
        }
      }
      if (empty($checklist[$module])) {
        unset($checklist[$module]);
      }
    }
    $checklist_results = $this->securityReviewRun($checklist);
    foreach ($checklist_results as $module => $checks) {
      foreach ($checks as $check_name => $check) {
        // Unset data that does not need to be sent.
        if (is_null($check['result'])) {
          unset($checklist_results[$module][$check_name]);
        }
        else {
          unset($check['success']);
          unset($check['failure']);
          $checklist_results[$module][$check_name] = $check;
        }
      }
      if (empty($checklist_results[$module])) {
        unset($checklist_results[$module]);
      }
    }
    return $checklist_results;
  }

  /**
   * Function for running Security Review checklist and returning results.
   *
   * @param array $checklist Array of checks to run, indexed by module namespace.
   * @param boolean $log Whether to log check processing using security_review_log.
   * @param boolean $help Whether to load the help file and include in results.
   * @return array Results from running checklist, indexed by module namespace.
   * D7: acquia_spi_security_review_run
   */
  private function securityReviewRun($checklist = NULL, $log = FALSE, $help = FALSE) {
    // Use Security Review module if available.
    if (\Drupal::moduleHandler()->moduleExists('security_review') && function_exists('security_review_run')) {
      if (!$checklist) {
        $checklist = \Drupal::moduleHandler()->moduleExists('security_checks');
      }
      module_load_include('inc', 'security_review');
      return security_review_run($checklist, $log, $help);
    }
    else {
      return $this->_securityReviewRun($checklist, $log);
    }
  }

  /**
   * Private function the review and returns the full results.
   * D7: _acquia_spi_security_review_run
   */
  private function _securityReviewRun($checklist, $log = FALSE) {
    $results = array();
    foreach ($checklist as $module => $checks) {
      foreach ($checks as $check_name => $arguments) {
        $check_result = $this->_securityReviewRunCheck($module, $check_name, $arguments, $log);
        if (!empty($check_result)) {
          $results[$module][$check_name] = $check_result;
        }
      }
    }
    return $results;
  }

  /**
   * Run a single Security Review check.
   * D7: _acquia_spi_security_review_run_check
   */
  private function _securityReviewRunCheck($module, $check_name, $check, $log, $store = FALSE) {
    $last_check = array();
    $return = array('result' => NULL);
    if (isset($check['file'])) {
      // Handle Security Review defining checks for other modules.
      if (isset($check['module'])) {
        $module = $check['module'];
      }
      module_load_include('inc', $module, $check['file']);
    }
    $function = $check['callback'];
    if (function_exists($function)) {
      $return = call_user_func($function, $last_check);
    }
    $check_result = array_merge($check, $return);
    $check_result['lastrun'] = REQUEST_TIME;

    if ($log && !is_null($return['result'])) { // Do not log if result is NULL.
      $variables = array('!name' => $check_result['title']);
      if ($check_result['result']) {
        $this->_securityReviewLog($module, $check_name, '!name check passed', $variables, WATCHDOG_INFO);
      }
      else {
        $this->_securityReviewLog($module, $check_name, '!name check failed', $variables, WATCHDOG_ERROR);
      }
    }
    return $check_result;
  }

  private function _securityReviewLog($module, $check_name, $message, $variables, $type) {
    module_invoke_all('acquia_spi_security_review_log', $module, $check_name, $message, $variables, $type);
  }

  /**
   * Helper function allows for collection of this file's security checks.
   * D7: acquia_spi_security_review_get_checks
   */
  private function securityReviewGetChecks() {
    // Use Security Review's checks if available.
    if (\Drupal::moduleHandler()->moduleExists('security_review') && function_exists('security_review_security_checks')) {
      return \Drupal::moduleHandler()->invokeAll('security_checks');
    }
    else {
      return $this->securityReviewSecurityChecks();
    }
  }

  /**
   * Checks for acquia_spi_security_review_get_checks().
   * D7: _acquia_spi_security_review_security_checks
   */
  private function securityReviewSecurityChecks() {
    $checks['file_perms'] = array(
      'title' => t('File system permissions'),
      'callback' => 'acquia_spi_security_review_check_file_perms',
      'success' => t('Drupal installation files and directories (except required) are not writable by the server.'),
      'failure' => t('Some files and directories in your install are writable by the server.'),
    );
    $checks['input_formats'] = array(
      'title' => t('Text formats'),
      'callback' => 'acquia_spi_security_review_check_input_formats',
      'success' => t('Untrusted users are not allowed to input dangerous HTML tags.'),
      'failure' => t('Untrusted users are allowed to input dangerous HTML tags.'),
    );
    $checks['field'] = array(
      'title' => t('Content'),
      'callback' => 'acquia_spi_security_review_check_field',
      'success' => t('Dangerous tags were not found in any submitted content (fields).'),
      'failure' => t('Dangerous tags were found in submitted content (fields).'),
    );
    $checks['error_reporting'] = array(
      'title' => t('Error reporting'),
      'callback' => 'acquia_spi_security_review_check_error_reporting',
      'success' => t('Error reporting set to log only.'),
      'failure' => t('Errors are written to the screen.'),
    );
    $checks['private_files'] = array(
      'title' => t('Private files'),
      'callback' => 'acquia_spi_security_review_check_private_files',
      'success' => t('Private files directory is outside the web server root.'),
      'failure' => t('Private files is enabled but the specified directory is not secure outside the web server root.'),
    );
    // Checks dependent on dblog.
    if (\Drupal::moduleHandler()->moduleExists('dblog')) {
      $checks['query_errors'] = array(
        'title' => t('Database errors'),
        'callback' => 'acquia_spi_security_review_check_query_errors',
        'success' => t('Few query errors from the same IP.'),
        'failure' => t('Query errors from the same IP. These may be a SQL injection attack or an attempt at information disclosure.'),
      );

      $checks['failed_logins'] = array(
        'title' => t('Failed logins'),
        'callback' => 'acquia_spi_security_review_check_failed_logins',
        'success' => t('Few failed login attempts from the same IP.'),
        'failure' => t('Failed login attempts from the same IP. These may be a brute-force attack to gain access to your site.'),
      );
    }
    $checks['upload_extensions'] = array(
      'title' => t('Allowed upload extensions'),
      'callback' => 'acquia_spi_security_review_check_upload_extensions',
      'success' => t('Only safe extensions are allowed for uploaded files and images.'),
      'failure' => t('Unsafe file extensions are allowed in uploads.'),
    );
    $checks['admin_permissions'] = array(
      'title' => t('Drupal permissions'),
      'callback' => 'acquia_spi_security_review_check_admin_permissions',
      'success' => t('Untrusted roles do not have administrative or trusted Drupal permissions.'),
      'failure' => t('Untrusted roles have been granted administrative or trusted Drupal permissions.'),
    );
    // Check dependent on PHP filter being enabled.
    if (\Drupal::moduleHandler()->moduleExists('php')) {
      $checks['untrusted_php'] = array(
        'title' => t('PHP access'),
        'callback' => 'acquia_spi_security_review_check_php_filter',
        'success' => t('Untrusted users do not have access to use the PHP input format.'),
        'failure' => t('Untrusted users have access to use the PHP input format.'),
      );
    }
    $checks['executable_php'] = array(
      'title' => t('Executable PHP'),
      'callback' => 'acquia_spi_security_review_check_executable_php',
      'success' => t('PHP files in the Drupal files directory cannot be executed.'),
      'failure' => t('PHP files in the Drupal files directory can be executed.'),
    );
    $checks['base_url_set'] = array(
      'title' => t('Drupal base URL'),
      'callback' => 'acquia_spi_security_review_check_base_url',
      'success' => t('Base URL is set in settings.php.'),
      'failure' => t('Base URL is not set in settings.php.'),
    );
    $checks['temporary_files'] = array(
      'title' => t('Temporary files'),
      'callback' => 'acquia_spi_security_review_check_temporary_files',
      'success' => t('No sensitive temporary files were found.'),
      'failure' => t('Sensitive temporary files were found on your files system.'),
    );
    if (\Drupal::moduleHandler()->moduleExists('views') && function_exists('views_get_all_views')) {
      $checks['views_access'] = array(
        'title' => t('Views access'),
        'callback' => 'acquia_spi_security_review_check_views_access',
        'success' => t('Views are access controlled.'),
        'failure' => t('There are Views that do not provide any access checks.'),
      );
    }

    return array('security_review' => $checks);
  }

  /**
   * Helper function checks for conflict with full Security Review module.
   * D7: _acquia_spi_security_review_compatible
   */
  private function securityReviewCompatible() {
    if (\Drupal::moduleHandler()->moduleExists('security_review')) {
      return TRUE;
    }
    return TRUE;
  }
}