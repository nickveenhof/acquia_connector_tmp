<?php

/**
 * Communicate intent to Acquia.com
 *  * If site has already been imported deny
 * Determine local system capabilities
 *  * is drush, mysqldump, tar usable?
 *  * Drupal 7: new Archive_Tar()
 *  * Drupal 6: PHP 5.3: new PharData('filename') OR use bundled tar lib
 * Start migration process
 *  * check system
 *    * acquia_migrate_destination_pantheon_migrate::check_libs()
 *  * check if timeout, set shutdown function, perhaps incremental files
 *  * batch if so
 * Create tar file
 *  * create tar file in temp dir
 * Add Drupal installation to tar file
 *  * tar all but files directory
 * Dump database tables and data
 * Add db to tar file
 * Compress
 *  * gzopen, bzopen, ZipArchive
 * POST file to Acquia.com
 */

function acquia_migration_prepare() {
  // Detect if a prior migration is unfinished @todo
  $last_migration = variable_get('acquia_agent_cloud_migration', array());
  /*if (!empty($last_migration) && _acquia_migrate_unfinished($last_migration)) {
    // @todo complete
  }*/

  timer_start('acquia_migrate');
  //register_shutdown_function('backup_migrate_shutdown', $settings);

  // Internal migration store is an array because objects cannot be stored
  // by Drupal's Batch API.
  $time = REQUEST_TIME;
  $date = gmdate('Ymd_his', $time);
  $migration = array(
    'error' => FALSE,
    'id' => uniqid() . '_' . $date,
    'date' => $date,
    'time' => $time,
    'compression_ext' => 'gz', //@todo determine available libs
    // Parameters used in transmission request.
    'request_params' => array(
      'r' => url('admin/config/system/acquia-agent', array('absolute' => TRUE)), // Return URL on this site.
      'y' => 'sar', // not changed
    ),
  );
  // Authorize migration process with Acquia Cloud. @todo
  _acquia_migrate_authorize($migration);
  if ($migration['error'] === TRUE) {
    return $migration;
  }

  // Validate final destination file is a file that does not exist in a
  // writeable directory.
  //_acquia_migrate_destination($migration);
  if ($migration['error'] === TRUE) {
    return $migration;
  }
$migration['tar_file'] = '/tmp/d6.sql.tgz';
  return $migration;
}

function _acquia_migrate_process_setup() {
  // If not in 'safe mode', increase the maximum execution time:
  if (!ini_get('safe_mode') && strpos(ini_get('disable_functions'), 'set_time_limit') === FALSE && ini_get('max_execution_time') < 1200) {
    set_time_limit(variable_get('acquia_migrate_max_time', 1200));
  }
}

function acquia_migration_complete() {
  $time = timer_stop('acquia_migrate');
}

function _acquia_migrate_destination(&$migration) {
  $tmp_dir = realpath(file_directory_temp()) . '/acquia_migrate' . $migration['id'];
  if (!mkdir($tmp_dir) || !is_writable($tmp_dir)) {
    $migration['error'] = TRUE;
  }
  $migration['dir'] = $tmp_dir;
  $migration['file'] = $tmp_dir . '/archive-' . $migration['date'];
}

/**
 * Returns TRUE if a past migration is unfinished and can be restarted.
 */
function _acquia_migrate_unfinished($migration) {
  
}

function _acquia_migrate_authorize(&$migration) {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');

  // @todo complete this
  return;

  $data = array(
    'id' => $identifier,
    'key' => $key,
  );
  $result = xmlrpc(acquia_agent_network_address(), array('acquia.agent.cloud.migration.authorize' => array($data)));
  // @todo setup $site['stage'] e.g. 'dev', 'test', or 'prod'
  // $migration['request_params'][$site['stage']] = acquia_hosting_get_token($now, $return_url, $secret);
  // $migration['request_params']['stage'] => $site['stage'];
  if ($errno = xmlrpc_errno()) {
    acquia_agent_report_xmlrpc_error();
    $migration['error'] = TRUE;
    return;
  }
  elseif (!$result) {
    // @todo
    $migration['error'] = TRUE;
    return;
  }
}

/**
 * Entire process callback, used by drush.
 */
function acquia_migrate_process() {
  $migration = acquia_migration_prepare();
  if (isset($migration['error']) && $migration['error'] == TRUE) {
    // @todo
    return FALSE;
  }

  // @todo Figure out which environment is being installed.

  // Setup each environment for each process.
  _acquia_migrate_process_setup();

  // Dump database.
  // @todo match drush archive dump? whats the db called?
  //_acquia_migrate_backup_db_to_file_mysql($migration);
  if (isset($migration['error']) && $migration['error'] == TRUE) {
    return FALSE;
  }

  // Archive installation with db file.
  //_acquia_migrate_archive_site($migration);
  // Validate archive file. @todo
  if (isset($migration['error']) && $migration['error'] == TRUE) {
    return FALSE;
  }

  // Remove db file.
  _acquia_migration_cleanup_db($migration);

  // Send file to Acquia Cloud.
  _acquia_migrate_transmit_process($migration);

  // Final cleanup.
  // Remove tar file and destination directory. @todo
  //_acquia_migration_cleanup($migration);

  acquia_migration_complete();

  return TRUE;
}

function acquia_migrate_batch_db($migration, &$context) {
  _acquia_migrate_process_setup();
  // Latest migration might be in $context.
  if (!empty($context['results']['migration'])) {
    $migration = $context['results']['migration'];
  }
  // Check for error and hault.
  if (empty($migration) || $migration['error'] == TRUE) {
    $context['message'] = t('Encountered error, haulting');
    return;
  }

  _acquia_migrate_backup_db_to_file_mysql($migration);
  $context['results']['debug'][] = print_r($migration, TRUE);

  // Store migration in results so it can be used by next operation.
  $context['results']['migration'] = $migration;
  $context['message'] = t('Exported database');
}

function acquia_migrate_batch_tar($migration, &$context) {
  _acquia_migrate_process_setup();
  // Latest migration is in $context.
  if (!empty($context['results']['migration'])) {
    $migration = $context['results']['migration'];
  }
  // Check for error and hault.
  if (empty($migration) || $migration['error'] == TRUE) {
    $context['message'] = t('Encountered error, haulting');
    return;
  }

  _acquia_migrate_archive_site($migration);
  $context['results']['debug'][] = print_r($migration, TRUE);

  // Store migration in results so it can be used by next operation.
  $context['results']['migration'] = $migration;
  $context['message'] = t('Archived site and database');
}

function acquia_migrate_batch_transmit($migration, &$context) {
  _acquia_migrate_process_setup();
  // Latest migration is in $context.
  if (!empty($context['results']['migration'])) {
    $migration = $context['results']['migration'];
  }
  // Check for error and hault.
  if (empty($migration) || $migration['error'] == TRUE) {
    $context['message'] = t('Encountered error, haulting');
    return;
  }

  $length = 32768;
  if (empty($context['sandbox'])) {
    $context['sandbox']['position'] = 0;
    $size = filesize($migration['tar_file']);
    $context['sandbox']['size'] = $size;
    $context['sandbox']['chunk'] = ($size / $length) > 1 ? TRUE : FALSE;
    $nonce = base64_encode(hash('sha256', drupal_random_bytes(55), TRUE));
    $time = time();
    $migration['request_params']['t'] = $time;
    $migration['request_params']['nonce'] = $nonce;
    $migration['request_params']['file_size'] = $size;
    $migration['request_params']['file_name'] = basename($migration['tar_file']);
  }
  // @todo maybe send more each time rather than looping?
  for ($i = 0; $i < 3 && $context['sandbox']['position'] !== FALSE; $i++) {
    $position = _acquia_migrate_transmit_chunk($migration['tar_file'], $context['sandbox']['position'], $length, $context['sandbox']['chunk']);
    $context['sandbox']['position'] = $position;
  }

  // Store migration in results so it can be used by next operation.
  $context['results']['migration'] = $migration;
  if ($context['sandbox']['position'] !== FALSE) {
    $context['message'] = t('Transmitted !pos of !size', array('!pos' => $context['sandbox']['position'], '!size' => $context['sandbox']['size']));
    $context['finished'] = $context['sandbox']['position'] / $context['sandbox']['size'];
  }
  else {
    $context['finished'] = 1;
  }
}

function acquia_migrate_batch_finished($success, $results, $operations) {
  $migration = isset($results['migration']) ? $results['migration'] : FALSE;
  if ($success && $migration && $migration['error'] == FALSE) {
    $migration = $results['migration'];
    dpm($results);
    // @todo store migration completion time
    // @todo request info from Acquia Cloud about migration
    $message = t('Migration success.');
    // Cleanup migration.
    _acquia_migration_cleanup($migration);
  }
  else {
    // @todo store migration failure
    variable_set('acquia_agent_cloud_migration', $migration);
    $message = t('Finished with an error.');
  }

  drupal_set_message($message);
}

function acquia_migrate_exclude() {
  $exclude = array('.', '..', '.git', '.svn', 'CVS');
  $exclude[] = realpath('.') . '/' . str_replace(realpath('.') . '/', '', realpath(variable_get('file_public_path', conf_path() . '/files') ));
  // @todo exclude multisite files dirs.
  return $exclude;
}

function _acquia_migrate_archive_site(&$migration) {
  $exclude = acquia_migrate_exclude();
  $files = acquia_migrate_files_to_backup(DRUPAL_ROOT, $exclude);

  if (!empty($files) && isset($migration['file'])) {
    // @todo open up file permissions?
    $dest_file = $migration['file'] . '.tar';
    if (!empty($migration['compression_ext'])) {
      $dest_file .= '.' . $migration['compression_ext'];
    }
    $gz = new Archive_Tar($dest_file, $migration['compression_ext'] ? $migration['compression_ext'] : NULL);
    // @todo figure out bad symlinks,
    $gz->addModify($files, '', DRUPAL_ROOT);
    if (!empty($migration['db_file'])) {
      // Add db file.
      $gz->addModify(array($migration['db_file']), '', $migration['dir']);
    }
    $migration['tar_file'] = $dest_file;
  }
  else {
    $migration['error'] = TRUE;
  }
}

function _acquia_migrate_transmit_process(&$migration) {
  if (!isset($migration['tar_file'])) {
    $migration['error'] = TRUE;
    return;
  }
  $size = filesize($migration['tar_file']);
  $position = 0;
  $length = 32768;
  $chunk = ($size / $length) > 1 ? TRUE : FALSE;

  // Prepare parameters for transmit request.
  $nonce = base64_encode(hash('sha256', drupal_random_bytes(55), TRUE));
  $time = time();
  $migration['request_params']['t'] = $time;
  $migration['request_params']['nonce'] = $nonce;
  $migration['request_params']['file_size'] = $size;
  $migration['request_params']['file_name'] = basename($migration['tar_file']);
  // Read in file and transmit.
  while ($position = _acquia_migrate_transmit_chunk($migration, $position, $length, $chunk)) {
    // @todo Check for halt.
  }
}

function _acquia_migrate_transmit_chunk(&$migration, $position = 0, $length, $chunk) {
  $handle = fopen($migration['tar_file'], "r");
  // Move to position in file.
  if ($position) {
    fseek($handle, $position);
  }
  $contents = fread($handle, $length);
  // Transmit contents.
  $result = _acquia_migrate_transmit($contents, $migration, $chunk);

  // Set position to FALSE if the whole file has been read or if transmit failed.
  if (feof($handle) || $result === FALSE) {
    $position = FALSE;
  }
  else {
    // Get current position.
    $position = ftell($handle);
  }
  fclose($handle);
  return $position;
}

function _acquia_migrate_transmit($content, &$migration, $chunk) {
  $params = $migration['request_params'];
  $params['chunk'] = $chunk;
  $data = '';
  $boundary = _acquia_migrate_multipart_boundary();

  $data = _acquia_migrate_multipart_encode_params($boundary, $params, $params['file_name'], $content);

  $headers = array(
    'Content-type' => "multipart/form-data, boundary=$boundary",
    'User-Agent' => 'Acquia Migrate Client/1.x (Drupal '. VERSION .';)',
  );

  $url = 'http://nspi.acquia.dev/acquia-cloud';
  $return = drupal_http_request($url, array('headers' => $headers, 'method' => 'POST', 'data' => $data));

  if ($return->code == 200) {
    $output = drupal_json_decode($response->data);

    // Validate signature
    $response_signature = $output['sig'];
    unset($output['sig']);
    $sig = '';
    foreach ($output as $value) {
      $sig .= $value;
    }
    // @todo Call rpc?
    $signature = hash_hmac('sha256', $sig, $secret);

    // Check if response is correct, if yes proceed
    if ($signature == $response_signature) {
      // Update tokend and refresh time in request
      $now = time();
      $migration['request_params']['t'] = $now;
      $migration['request_params'][$migration['stage']] = acquia_hosting_get_token($now, $migration['request_params']['return_url'], $migration['secret']);
    }
    else {
      $error = 'Singature from server is wrong.';
      return FALSE;
    }
  }
  else {
    watchdog('tst', 'transmit error');
    return FALSE;
  }
}

/**
 * Get upload security token
 *
 * @param $now
 * @param $return
 * @param $stage
 */
function acquia_hosting_get_token($now, $return, $secret) {
  // @todo use _acquia_agent_hmac() instead?
  return hash_hmac('sha256', $now . $return, $secret);
}

function acquia_migrate_files_to_backup($directory, $exclude) {
  $array_items = array();
  // if (class_exists(SkipDotsRecursiveDirectoryIterator)) {
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DRUPAL_ROOT), RecursiveIteratorIterator::SELF_FIRST) as $filename => $file) {
    // @todo this needs exclusion
    if (!strpos($filename, '.git')) {
      $array_items[] = $filename;
    }
  }
  // file_scan_directory
  // OR
  /*
  if ($handle = opendir($directory)) {
    while (false !== ($file = readdir($handle))) {
      if (!in_array($file, $exclude)) {
        if (is_dir($directory. "/" . $file)) {
          $array_items = array_merge($array_items, acquia_migrate_files_to_backup($directory. "/" . $file, $exclude));
          $file = $directory . "/" . $file;
          $array_items[] = preg_replace("/\/\//si", "/", $file);
        }
        else {
          $file = $directory . "/" . $file;
          $array_items[] = preg_replace("/\/\//si", "/", $file);
        }
      }
    }
    closedir($handle);
  }*/
  return $array_items;
}

function _acquia_migration_cleanup_db(&$migration) {
  if (isset($migration['db_file'])) {
    drupal_unlink($migration['db_file']);
    unset($migration['db_file']);
  }
}

function _acquia_migration_cleanup(&$migration) {
  if (isset($migration['db_file'])) {
    _acquia_migration_cleanup_db($migration);
  }
  if (isset($migration['tar_file'])) {
    drupal_unlink($migration['tar_file']);
    unset($migration['tar_file']);
  }
  if (isset($migration['dir']) && is_dir($migration['dir'])) {
    rmdir($migration['dir']);
    unset($migration['dir']);
  }
}

/**
 * Dump mysql datbase, modified from Backup & Migrate module by ronan.
 */

/**
 * Dump the database to the specified file.
 */
function _acquia_migrate_backup_db_to_file_mysql(&$migration) {
  // Check migration file at first to avoid dumping db to a hidden file.
  if (!isset($migration['file'])) {
    $migration['error'] = TRUE;
    return;
  }
  $file = $migration['file'] . '.sql';
  $handle = fopen($file, 'w');
  $lines = 0;
  $exclude = array();
  $nodata = array();
  if ($handle) {
    $data = _acquia_migrate_get_sql_file_header_mysql();
    fwrite($handle, $data);
    $alltables = _acquia_migrate_get_tables_mysql();
    foreach ($alltables as $table) {
      // @todo timeout handling
      /*if (_acquia_migrate_check_timeout()) {
        $migration['error'] = TRUE;
        return;
      }*/
      if ($table->name && !isset($exclude[$table->name])) {
        $data = _acquia_migrate_get_table_structure_sql_mysql($table);
        fwrite($handle, $data);
        $lines++;
        if (!in_array($table->name, $nodata)) {
          $lines += _acquia_migrate_dump_table_data_sql_to_file($handle, $table);
        }
      }
    }
    $data = _acquia_migrate_get_sql_file_footer_mysql();
    fwrite($handle, $data);
    $stat = fstat($handle);
    fclose($handle);
    // Set migration details.
    $migration['db_size'] = $stat['size'];
    $migration['db_file'] = $file;
  }
  else {
    $migration['error'] = TRUE;
  }
}

/**
 * Get the sql for the structure of the given table.
 */
function _acquia_migrate_get_table_structure_sql_mysql($table) {
  $out = "";

  $result = db_query("SHOW CREATE TABLE `". $table->name ."`");
  if ($record = $result->fetchAssoc()) {
    $out .= "DROP TABLE IF EXISTS `". $table->name ."`;\n";
    $out .= strtr($record['create table'], array("\n" => " ", '"' => '`'));
    if ($table->auto_increment) {
      $out .= " AUTO_INCREMENT=". $table->auto_increment;
    }
    $out .= ";\n";
  }
  return $out;
}

/**
 *  Get the sql to insert the data for a given table
 */
function _acquia_migrate_dump_table_data_sql_to_file($handle, $table) {
  $lines = 0;
  $result = db_query("SELECT * FROM `". $table->name ."`");
  while($row = $result->fetchAssoc()) {
    $items = array();
    foreach ($row as $key => $value) {
      $items[] = is_null($value) ? "null" : "'". mysql_escape_string($value) ."'";
    }
    if ($items) {
      $data = "INSERT INTO `". $table->name ."` VALUES (". implode(",", $items) .");\n";
      fwrite($handle, $data);
      $lines++;
    }
  }
  return $lines;
}

/**
 * The header for the top of the sql dump file. These commands set the connection
 *  character encoding to help prevent encoding conversion issues.
 */
function _acquia_migrate_get_sql_file_header_mysql() {
  return "
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=NO_AUTO_VALUE_ON_ZERO */;

SET NAMES utf8;
";
}

/**
 * The footer of the sql dump file.
 */
function _acquia_migrate_get_sql_file_footer_mysql() {
  return "

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
";
}

/**
 * Get a list of tables in the db.
 */
function _acquia_migrate_get_tables_mysql() {
  $out = array();
  // get auto_increment values and names of all tables
  $tables = db_query("show table status");
  foreach ($tables as $table) {
    $out[$table->name] = $table;
  }
  return $out;
}


/**
 * Get the list of table names.
 */
function _acquia_migrate_get_table_names_mysql() {
  $out = array();
  foreach (_acquia_migrate_get_tables_mysql() as $key => $table) {
    $out[$key] = $table->name;
  }
  return $out;
}

/**
 * Create multipart boundary
 * 
 * @return
 *   Boundary
 */
function _acquia_migrate_multipart_boundary() {
  return '---------------------------'. substr(md5(rand(0,32000)),0,10);
}

/**
 * Encode params array as multipart/form-data string
 *
 * @param $boundary
 *   Boundary to delimit parameters
 * @param $params
 *   Form data array
 * @return
 *   Encoded string for drupal_http_request
 */
function _acquia_migrate_multipart_encode_params($boundary, $params, $filename, $content){
  $output = "";
  foreach ($params as $key => $value) {
    $output .= "--$boundary\r\n";
    $output .= _acquia_migrate_multipart_enc_text($key, $value);
  }
  $output .= "--$boundary\r\n";
  $output .= _acquia_migrate_multipart_enc_file($key, $filename, $content);
  $output .= "--$boundary--";
  return $output;
}

/**
 * Encode simple param
 * @param type $name
 * @param type $value
 * @return type
 */
function _acquia_migrate_multipart_enc_text($name,  $value){
  return "Content-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
}

function _acquia_migrate_multipart_enc_file($name, $filename, $file_content){
  $mimetype = "application/octet-stream";
  $data = "Content-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\n";
  $data .= "Content-Transfer-Encoding: binary\r\n";
  $data .= "Content-Type: $mimetype\r\n\r\n";
  $data .= $file_content . "\r\n";
  return $data;
}