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
  acquia_migrate_process();
}