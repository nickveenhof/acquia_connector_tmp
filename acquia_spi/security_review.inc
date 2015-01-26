<?php

/**
 * @file
 * Stand-alone security checks and review system.
 */

/**
 * Check that files aren't writeable by the server.
 */
function acquia_spi_security_review_check_file_perms() {
  $result = TRUE;
  // Extract ending folder for file directory path.
  $file_path = './' . rtrim(variable_get('file_public_path', conf_path() . '/files'), '/');
  // Set files to ignore.
  $ignore = array('..', 'CVS', '.git', '.svn', '.bzr', $file_path);
  // Add private files directory if it's set.
  $private_files = variable_get('file_private_path', '');
  if (!empty($private_files)) {
    $private_files = substr($private_files, strrpos($private_files, '/') + 1);
    $ignore[] = $private_files;
  }
  drupal_alter('security_review_file_ignore', $ignore);
  $files = _acquia_spi_security_review_check_file_perms_scan('.', $ignore);

  // Try creating or appending files.
  // Assume it doesn't work.
  $create_status = $append_status = FALSE;

  $directory = drupal_get_path('module', 'security_review');
  // Write a file with the timestamp
  $file = './' . $directory . '/file_write_test.' . date('Ymdhis');
  if ($file_create = @fopen($file, 'w')) {
    $create_status = fwrite($file_create, t("This is a vulnerable directory.\n"));
    fclose($file_create);
  }
  // Try to append to our IGNOREME file.
  $file = './'. $directory . '/IGNOREME.txt';
  if ($file_append = @fopen($file, 'a')) {
    $append_status = fwrite($file_append, date('Ymdhis') . "\n");
    fclose($file_append);
  }

  if (count($files) || $create_status || $append_status) {
    $result = FALSE;
  }
  return array('result' => $result, 'value' => $files);
}

function _acquia_spi_security_review_check_file_perms_scan($directory, $ignore) {
  $items = array();
  if ($handle = opendir($directory)) {
    while (($file = readdir($handle)) !== FALSE) {
      // Don't check hidden files or ones we said to ignore.
      if ($file[0] != "." && !in_array($file, $ignore)) {
        $file = $directory . "/" . $file;
        if (is_dir($file) && !in_array($file, $ignore)) {
          $items = array_merge($items, _acquia_spi_security_review_check_file_perms_scan($file, $ignore));
          if (is_writable($file)) {
            $items[] = preg_replace("/\/\//si", "/", $file);
          }
        }
        elseif (is_writable($file) && !in_array($file, $ignore)) {
          $items[] = preg_replace("/\/\//si", "/", $file);
        }
      }
    }
    closedir($handle);
  }
  return $items;
}

function acquia_spi_security_review_check_error_reporting() {
  $error_level = variable_get('error_level', NULL);
  if (is_null($error_level) || intval($error_level) >= 1) {
    // When the variable isn't set, or its set to 1 or 2 errors are printed to the screen.
    $result = FALSE;
  }
  else {
    $result = TRUE;
  }
  return array('result' => $result);
}

/**
 * If private files is enabled check that the directory is not under the web root.
 *
 * There is ample room for the user to get around this check. @TODO get more sophisticated?
 */
function acquia_spi_security_review_check_private_files() {
  $file_directory_path = variable_get('file_private_path', '');
  if (empty($file_directory_path)) {
    $result = NULL; // Ignore this check.
  }
  elseif (strpos(realpath($file_directory_path), DRUPAL_ROOT) === 0) {
    // Path begins at root.
    $result = FALSE;
  }
  else {
    $result = TRUE;
  }
  return array('result' => $result, 'value' => $file_directory_path);
}

function acquia_spi_security_review_check_query_errors($last_check = NULL) {
  $timestamp = NULL;
  $check_result_value = array();
  $query = db_select('watchdog', 'w')->fields('w', array('message', 'hostname'))
    ->condition('type', 'php')
    ->condition('severity', WATCHDOG_ERROR);
  if (isset($last_check['lastrun'])) {
    $query->condition('timestamp', $last_check['lastrun'], '>=');
  }
  $result = $query->execute();
  foreach ($result as $row) {
    if (strpos($row->message, 'SELECT') !== FALSE) {
      $entries[$row->hostname][] = $row;
    }
  }
  $result = TRUE;
  if (!empty($entries)) {
    foreach ($entries as $ip => $records) {
      if (count($records) > 10) {
        $check_result_value[] = $ip;
      }
    }
  }
  if (!empty($check_result_value)) {
    $result = FALSE;
  }
  else {
    // Rather than worrying the user about the idea of query errors we skip reporting a pass.
    $result = NULL;
  }
  return array('result' => $result, 'value' => $check_result_value);
}

function acquia_spi_security_review_check_failed_logins($last_check = NULL) {
  $result = TRUE;
  $timestamp = NULL;
  $check_result_value = array();
  $query = db_select('watchdog', 'w')->fields('w', array('message', 'hostname'))
    ->condition('type', 'php')
    ->condition('severity', WATCHDOG_NOTICE);
  if (isset($last_check['lastrun'])) {
    $query->condition('timestamp', $last_check['lastrun'], '>=');
  }
  $result = $query->execute();
  foreach ($result as $row) {
    if (strpos($row->message, 'Login attempt failed') !== FALSE) {
      $entries[$row->hostname][] = $row;
    }
  }
  if (!empty($entries)) {
    foreach ($entries as $ip => $records) {
      if (count($records) > 10) {
        $check_result_value[] = $ip;
      }
    }
  }
  if (!empty($check_result_value)) {
    $result = FALSE;
  }
  else {
    // Rather than worrying the user about the idea of failed logins we skip reporting a pass.
    $result = NULL;
  }
  return array('result' => $result, 'value' => $check_result_value);
}

/**
 * Look for admin permissions granted to untrusted roles.
 */
function acquia_spi_security_review_check_admin_permissions() {
  $result = TRUE;
  $check_result_value = array();
  $untrusted_roles = acquia_spi_security_review_untrusted_roles();
  // Collect permissions marked as for trusted users only.
  $all_permissions = module_invoke_all('permission');
  $all_keys = array_keys($all_permissions);
  // Get permissions for untrusted roles.
  $untrusted_permissions = user_role_permissions($untrusted_roles);
  foreach ($untrusted_permissions as $rid => $permissions) {
    $intersect = array_intersect($all_keys, array_keys($permissions));
    foreach ($intersect as $permission) {
      if (isset($all_permissions[$permission]['restrict access'])) {
        $check_result_value[$rid][] = $permission;
      }
    }
  }

  if (!empty($check_result_value)) {
    $result = FALSE;
  }
  return array('result' => $result, 'value' => $check_result_value);
}

function acquia_spi_security_review_check_field($last_check = NULL) {
  $check_result = TRUE;
  $check_result_value = $tables = $found = array();
  $timestamp = NULL;
  $instances = field_info_instances();
  // Loop through instances checking for fields of type text.
  foreach ($instances as $entity_type => $type_bundles) {
    foreach ($type_bundles as $bundle => $bundle_instances) {
      foreach ($bundle_instances as $field_name => $instance) {
        $field = field_info_field($field_name);
        // Check into text fields that are stored in SQL.
        if ($field['module'] == 'text' && $field['storage']['module'] == 'field_sql_storage') {
          // Build array of tables and columns to search.
          $current_table = key($field['storage']['details']['sql'][FIELD_LOAD_CURRENT]);
          $revision_table = key($field['storage']['details']['sql'][FIELD_LOAD_REVISION]);
          if (!array_key_exists($current_table, $tables)) {
            $tables[$current_table] = $field['storage']['details']['sql'][FIELD_LOAD_CURRENT][$current_table]['value'];
          }
          if (!array_key_exists($revision_table, $tables)) {
            $tables[$revision_table] = $field['storage']['details']['sql'][FIELD_LOAD_REVISION][$revision_table]['value'];
          }
        }
      }
    }
  }
  if (empty($tables)) {
    return array('result' => $check_result, 'value' => $check_result_value);
  }
  // Search for PHP or Javascript tags in text columns.
  foreach ($tables as $table => $column) {
    $sql = "SELECT DISTINCT entity_id, entity_type FROM {" . $table . "} WHERE " . $column . " LIKE :text";
    // Handle changed? @todo
    foreach (array('Javascript' => '%<script%', 'PHP' => '%<?php%') as $description => $comparison) {
      $results = db_query($sql, array(':text' => $comparison)); // @pager query?
      foreach ($results as $result) {
        $check_result = FALSE;
        if (!isset($check_result_value[$result->entity_type]) || !array_key_exists($result->entity_id, $check_result_value[$result->entity_type])) {
          $check_result_value[$result->entity_type][$result->entity_id] = $description;
        }
      }
    }
  }

  return array('result' => $check_result, 'value' => $check_result_value);
}

function acquia_spi_security_review_check_upload_extensions($last_check = NULL) {
  $check_result = TRUE;
  $check_result_value = array();
  $instances = field_info_instances();
  $unsafe_extensions = acquia_spi_security_review_unsafe_extensions();
  // Loop through instances checking for fields of file.
  foreach ($instances as $entity_type => $type_bundles) {
    foreach ($type_bundles as $bundle => $bundle_instances) {
      foreach ($bundle_instances as $field_name => $instance) {
        $field = field_info_field($field_name);
        if ($field['module'] == 'image' || $field['module'] == 'file') {
          // Check instance file_extensions.
          foreach ($unsafe_extensions as $unsafe_extension) {
            if (strpos($instance['settings']['file_extensions'], $unsafe_extension) !== FALSE) {
              // Found an unsafe extension.
              $check_result_value[$instance['field_name']][$instance['bundle']] = $unsafe_extension;
              $check_result = FALSE;
            }
          }
        }
      }
    }
  }

  return array('result' => $check_result, 'value' => $check_result_value);
}

/**
 * Helper function collects the permissions untrusted roles have.
 */
function acquia_spi_security_review_untrusted_permissions() {
  static $permissions;
  if (empty($permissions)) {
    $permissions = array();
    // Collect list of untrusted roles' permissions.
    $untrusted_roles = acquia_spi_security_review_untrusted_roles();
    foreach ($untrusted_roles as $rid) {
      $perms = array();
      $results = db_query('SELECT r.rid, p.permission FROM {role} r LEFT JOIN {role_permission} p ON r.rid = p.rid WHERE r.rid = :rid', array(':rid' => $rid))
        ->fetchArray();
      if ($results !== FALSE) {
        $perms = explode(',', str_replace(', ', ',', $results['permission']));
        $permissions[$rid] = $perms;
      }
    }
  }
  return $permissions;
}

/**
 * Helper function for assumed trusted roles.
 */
function acquia_spi_security_review_trusted_roles() {
  $trusted_roles = array();
  $untrusted_roles = acquia_spi_security_review_untrusted_roles();
  $results = db_query('SELECT rid, name FROM {role} WHERE rid NOT IN (:rids)', array(':rids' => $untrusted_roles));
  foreach ($results as $role) {
    $trusted_roles[$role->rid] = $role->name;
  }
  return array_filter($trusted_roles);
}

/**
 * Check if role has been granted a permission.
 */
function acquia_spi_security_review_role_permission($rid, $permission) {
  $return = FALSE;
  $result = db_select('role_permission', 'p')->fields('p', array('permission'))->condition('rid', $rid)->execute()->fetchField();
  if ($result['permission'] && strpos($result['permission'], $permission) !== FALSE) {
    $return = TRUE;
  }
  return $return;
}
