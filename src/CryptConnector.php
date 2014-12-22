<?php

namespace Drupal\acquia_connector;

//use Drupal\Core\Password;
use Drupal\Core\Password\PhpassHashedPassword;

class CryptConnector extends PhpassHashedPassword {

  public $crypt_pass;

  function __construct($algo, $password, $setting, $extra_md5) {
    $this->algo = $algo;
    $this->password = $password;
    $this->setting = $setting;
    $this->extra_md5 = $extra_md5;
  }

  public function cryptPass() {
    // Server may state that password needs to be hashed with MD5 first.
    if ($this->extra_md5) {
      $this->password = md5($this->password);
    }
    $crypt_pass = $this->crypt($this->algo, $this->password, $this->setting);

    if ($this->extra_md5) {
      $crypt_pass = 'U' . $crypt_pass;
    }

    return $crypt_pass;
  }
}
