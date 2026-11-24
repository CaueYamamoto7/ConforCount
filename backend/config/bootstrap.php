<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\Utility\Security;

require __DIR__ . '/paths.php';
require CORE_PATH . 'config/bootstrap.php';

Configure::config('default', new PhpConfig());
Configure::load('app');
date_default_timezone_set(Configure::read('App.defaultTimezone'));
mb_internal_encoding('UTF-8');
ConnectionManager::setConfig(Configure::consume('Datasources'));
Security::setSalt(Configure::consume('Security.salt'));
Log::setConfig('error', ['className' => 'Console', 'stream' => 'php://stderr', 'levels' => ['error', 'critical', 'alert', 'emergency']]);
