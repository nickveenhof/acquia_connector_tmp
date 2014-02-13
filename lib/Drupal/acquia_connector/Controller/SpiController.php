<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\SpiController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Component\Utility\String;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class SpiController.
 */
class SpiController extends ControllerBase {

  /**
   * Send SPI data.
   */
  public function send(Request $request) {
    $method = ACQUIA_SPI_METHOD_CALLBACK;

    // Insight's set variable feature will pass method insight.
    if ($request->query->has('method') && ($request->query->get('method') === ACQUIA_SPI_METHOD_INSIGHT)) {
      $method = ACQUIA_SPI_METHOD_INSIGHT;
    }

    $response = acquia_connector_send_full_spi($method);

    if ($request->query->has('destination')) {
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

      $this->redirect('<front>');
    }
  }

  /**
   * Access callback check for SPI send independent call.
   */
  public function sendAccess(Request $request) {
    $acquia_key = \Drupal::config('acquia_connector.settings')->get('key');

    if (!empty($acquia_key) && $request->query->has('key')) {
      $key = sha1(\Drupal::service('private_key')->get());
      if ($key === $request->query->get('key')) {
        return TRUE;
      }
    }
    return FALSE;
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
