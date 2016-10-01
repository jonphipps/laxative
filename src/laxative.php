<?php

namespace Codeception\Extension;

use Codeception\Event\SuiteEvent;
use Codeception\Platform\Extension;

/**
 *
 * Fiber, for your dump.
 * Easy to use, saves you time.
 * DevOps for the win.
 *  - Benjamin Franklin (probably)
 *
 */
class Laxative extends Extension
{

  // listen to these events
  public static $events = [
      'suite.before' => 'beforeSuite',
      'suite.after'  => 'afterSuite',

  ];

  private $_backupBefore;
  private $_backupAfter;
  private $_backupPath;
  private $_currentEnvironment;
  private $_migrations;
  private $_seed;
  private $_host;
  private $_port;
  private $_login;
  private $_database;
  private $_dumpDatabase;
  private $_databaseType;
  private $_user;
  private $_password;
  // the Codeception Db module
  /** @var  \Codeception\Module\Db */
  private $_db;


  public function beforeSuite(SuiteEvent $e)
  {
    // read config
    $this->setParams($e);

    // If enabled, create a mysql|pgsql_dump backup of the current local database
    // from mysql|pgsql to '_backupPath'.sql
    if ($this->_backupBefore) {
      $this->backup($this->_backupPath . '_before' . $this->_currentEnvironment . '.sql');
    }

    // Let Codeception restore the database from '_masterDbPath'.sql
    // by reinitializing the db module, which loads the database
    $this->localRestore();

    // Run all migrations against the freshly restored database.
    $this->migrate();

    // Run seeder(s) against the freshly restored database.
    $this->seed();

    // Create a new dump file ($this->_db->_getConfig('dump')) from the updated database
    // for the Codeception Db module to restore for each of the tests in this suite.
    $dump = 'tests/_data/dump.sql';
    $this->dump($dump);

    // update Codeception to populate the database
    $this->updateDbModule($dump);
  }


  public function afterSuite(SuiteEvent $e)
  {
    $this->setParams($e);

    if ($this->_backupAfter) {
      $this->backup($this->_backupPath . '_after' . $this->_currentEnvironment . '.sql');
    }

    if ($this->_backupBefore && $this->_db->_getConfig('cleanup')) {
      $this->restore($this->_backupPath . '_before' . $this->_currentEnvironment . '.sql');
    }
  }


  /**
   * Create a binary back up of the local database.
   *
   * @param string $destination
   */
  private function backup($destination)
  {
    $this->writeln('Laxative: Backing up your local database to: ' . $this->_backupPath . '...');

    $this->dumpCommand($destination);

    $this->writeln('Done.');

  }


  /**
   * Let Codeception restore the database from base.sql
   */
  private function localRestore()
  {
    $this->writeln('Laxative: Restoring local database from base...');

    $this->_db->_reconfigure([ 'populate' => true, 'dump' => $this->_dumpDatabase ]);
    $this->_db->_initialize();

    $this->writeln('Done.');
  }


  /**
   * Restore local database from a binary backup.
   * @see backup()
   *
   * @param $source
   */
  private function restore($source)
  {
    $this->writeln('Laxative: Restoring your database from backup...');
    $this->restoreCommand($source);

    $this->writeln('Done.');
  }


  /**
   * Run all migrations against fresh database.
   */
  private function migrate()
  {
    if ( ! empty( $this->_migrations )) {
      $this->writeln('Laxative: Running migrations...');
      exec($this->_migrations);
      $this->writeln('Done.');
    }
  }


  /**
   * Run all seeders against newly created database.
   */
  private function seed()
  {
    if ( ! empty( $this->_seed )) {
      $this->writeln('Laxative: Seeding database...');
      exec($this->_seed);
      $this->writeln('Done.');
    }
  }


  /**
   * Create a dump file for the Codeception Db module.
   *
   * @param $dump
   */
  private function dump($dump)
  {
    $this->writeln('Laxative: Creating Codeception dump...');

    $this->dumpCommand($dump);

    $this->writeln('Done.');
  }


  /**
   * Re-configure the Db module to ensure we populate from the dump file.
   *
   * @param $dump
   */
  private function updateDbModule($dump)
  {
    $this->writeln('Laxative: Re-configuring Codeception Db module...');

    $this->_db->_reconfigure([ 'dump' => $dump ]);
    $this->_db->_initialize();

    $this->writeln('Done.');
  }


  private function dumpCommand($destination)
  {
    if ($this->getDatabaseTypeIsPostgres()) { // use pg_dump to create binary backup
      $command = sprintf('pg_dump -h %s -p %s -U %s -d %s -F t --file %s',
          $this->_host,
          $this->_port,
          $this->_login,
          $this->_database,
          $destination);
    } else { ////$cmd = "mysqldump --routines --databases -h {$server_name} -u {$username} -p{$password} {$database_name} > " . BACKUP_PATH . "{$date_string}_{$database_name}.sql";
      $command = sprintf('mysqldump --routines --databases -h %s -P %s -u %s -p%s %s > %s',
          $this->_host,
          $this->_port,
          $this->_user,
          $this->_password,
          $this->_database,
          $destination);
    }

    exec($command);
  }


  private function restoreCommand($source)
  {
    if ($this->getDatabaseTypeIsPostgres()) { // use pg_restore to restore from our binary backup
      $command = sprintf('pg_restore -h %s -p %s -U %s -d %s -c %s',
          $this->_host,
          $this->_port,
          'postgres',
          $this->_database,
          $source);
    } else { //"mysql -h {$server_name} -P {port} -u {$username} -p{$password} {$database_name} < $restore_file"
      $command = sprintf('mysql -h %s -P %s -u %s -p%s %s < %s',
          $this->_host,
          $this->_port,
          $this->_user,
          $this->_password,
          $this->_database,
          $source);
    }

    exec($command);
  }


  /**
   * @return bool
   */
  private function getDatabaseTypeIsPostgres()
  {
    return $this->_databaseType == strtolower('pgsql');
  }


  /**
   * @param SuiteEvent $e
   */
  private function setParams(SuiteEvent $e)
  {
    // get the db module
    try {
      $this->_db = $this->getModule('Db');
    }
    catch (\Exception $e) {
      return;
    }

    $dbDsn = $this->_db->_getConfig('dsn');

    if ($dbDsn) {
      $dbConfig = [];
      //pgsql:host=localhost;port=5432;dbname=testdb;user=bruce;password=mypass
      //"mysql:host=%DB_HOST%;port=%DB_PORT_VM%;dbname=%DB_DATABASE%"
      $dsn                 = explode(":", $dbDsn);
      $this->_databaseType = $dsn[0];
      $values              = explode(";", $dsn[1]);
      foreach ($values as $value) {
        $t = explode('=', $value);
        $dbConfig[$t[0]] = $t[1];
      }

      if ($this->_databaseType === 'mysql') {
        $this->_host     = $dbConfig['host'];
        $this->_port     = isset( $dbConfig['port'] ) ? $dbConfig['port'] : null;
        $this->_database = $dbConfig['dbname'];
        $this->_user     = $this->_db->_getConfig('user');
        $this->_password = $this->_db->_getConfig('password');
      }
      if ($this->_databaseType === 'pgsql') {
        $this->_host     = $dbConfig['host'];
        $this->_port     = isset( $dbConfig['port'] ) ? $dbConfig['port'] : null;
        $this->_database = $dbConfig['dbname'];
        $this->_user     = isset( $dbConfig['user'] ) ? $dbConfig['user'] : null;
        $this->_password = isset( $dbConfig['password'] ) ? $dbConfig['password'] : null;
      }
    } else {
      $this->_host         = $this->getConfig('host');
      $this->_port         = $this->getConfig('port');
      $this->_login        = $this->getConfig('login');
      $this->_database     = $this->getConfig('database');
      $this->_databaseType = $this->getConfig('database_type');
      $this->_user         = $this->_db->_getConfig('user');
      $this->_password     = $this->_db->_getConfig('password');
    }

    $this->_currentEnvironment = isset( $e->getSettings()['current_environment']) ? '_' . $e->getSettings()['current_environment'] : '';
    if ($this->_currentEnvironment) {
      $DbConfig = $this->getCurrentDbConfig($e);
      $this->_dumpDatabase = $DbConfig['dump'];
    } else {
      $this->_dumpDatabase = $this->_db->_getConfig('dump');
    }

    $this->_backupBefore = $this->getConfig('backupBefore');
    $this->_backupAfter  = $this->getConfig('backupAfter');
    $this->_backupPath   = $this->getConfig('backup_path');
    $this->_migrations   = $this->getConfig('migrations');
    $this->_seed         = $this->getConfig('seed');

    return;
  }


  private function getConfig($config)
  {
    return isset( $this->config[$config] ) ? $this->config[$config] : '';
  }


  private function getCurrentDbConfig(SuiteEvent $e)
  {
    $configs = $e->getSettings()['modules']['config'];
    foreach ((array) $configs as $config) {
      foreach ((array) $config as $module => $item) {
        if ($module == 'Db') {
          return $config[$module];
        }
      }
    }

    return false;
  }
}
