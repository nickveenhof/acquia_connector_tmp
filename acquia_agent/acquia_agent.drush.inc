<?php

/**
 * Implements hook_drush_command().
 */
function acquia_agent_drush_command() {
  $items['acquia-migrate'] = array(
    'description' => dt('Migrate site to Acquia Cloud'),
    'callback' => 'drush_acquia_migrate',
  );
  return $items;
}

function drush_acquia_migrate() {
  module_load_include('inc', 'acquia_agent', 'acquia_agent.migrate');
  /*$tmpdir = realpath(file_directory_temp()) .'/'. uniqid('acquia_dump');
  mkdir($tmpdir);
  $filepath = $tmpdir . '/' . uniqid('acquia_archive') . '.sql';
  //acquia_migrate_backup_db_to_file_mysql($filepath);*/
  //_acquia_migrate_tst('/tmp/acquia-test');
  $env = array('secret' => 'secret', 'nonce' => 'nonce', 'stage' => 'stage', 'url' => 'url');
  //acquia_migrate_process($env);
  //$exclude = acquia_migrate_exclude();
  //print_r($exclude);
  /*$files = acquia_migrate_files_to_backup(DRUPAL_ROOT, $exclude);
  print_r($files);*/
}

/**
 * Entire process callback, used by drush.
 */
function acquia_migrate_process($environment) {
  // This file is for CLI testing and will be removed for prod. @todo
  $migration = acquia_migration_prepare($environment);
  if (isset($migration['error']) && $migration['error'] !== FALSE) {
    return FALSE;
  }

  // Setup environment for each process.
  //_acquia_migrate_process_setup();

  // Dump database.
  _acquia_migrate_backup_db_to_file_mysql($migration);
  if (isset($migration['error']) && $migration['error'] !== FALSE) {
    return FALSE;
  }

  // Archive installation with db file.
  _acquia_migrate_archive_site($migration);
  if (isset($migration['error']) && $migration['error'] !== FALSE) {
    return FALSE;
  }

  // Remove db file.
  //_acquia_migrate_cleanup_db($migration);

  // Send file to Acquia Cloud.
  //_acquia_migrate_transmit_process($migration);
  if (isset($migration['error']) && $migration['error'] !== FALSE) {
    return FALSE;
  }

  //acquia_migration_complete($migration);

  // Final cleanup.
  // Remove tar file and destination directory.
  //_acquia_migrate_cleanup($migration);

  return TRUE;
}

function _acquia_migrate_transmit_process(&$migration) {
  // This file is for CLI testing and will be removed for prod. @todo
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