<?php

namespace Drupal\acquia_connector;

use \Exception;

/**
 *
 */
class ConnectorException extends Exception {
  private $custom = [];

  /**
   * Construction method.
   *
   * @param string $message
   * @param int $code
   * @param array $custom
   *   Exceotion messages as key => value
   * @param Exception $previous
   */
  public function __construct($message, $code = 0, $custom = [], Exception $previous = NULL) {
    parent::__construct($message, $code, $previous);
    $this->custom = $custom;
  }

  /**
   * Check is customized.
   *
   * @return bool
   */
  public function isCustomized() {
    return !empty($this->custom);
  }

  /**
   * Set custom message.
   *
   * @param $key
   * @param $value
   */
  public function setCustomMessage($value, $key = 'message') {
    $this->custom[$key] = $value;
  }

  /**
   * Get custom message.
   *
   * @param string $key
   * @param bool $fallback.
   *   Default is TRUE. Return standard code or message.
   *
   * @return mixed
   */
  public function getCustomMessage($key = 'message', $fallback = TRUE) {
    if (isset($this->custom[$key])) {
      return $this->custom[$key];
    }
    if (!$fallback) {
      return FALSE;
    }
    switch ($key) {
      case 'code':
        return $this->getCode();

      break;
      case 'message':
        return $this->getMessage();

      break;
    }
    return FALSE;
  }

  /**
   * Get all custom messages.
   *
   * @return array
   */
  public function getAllCustomMessages() {
    return $this->custom;
  }

}
