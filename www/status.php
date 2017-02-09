<?php

use Sil\PhpEnv\Env;
use Sil\SilAuth\config\ConfigManager;
use Sil\SilAuth\system\System;
use Sil\SilAuth\log\Psr3SyslogLogger;
use Throwable;

try {
    header('Content-Type: text/plain');
    
    ConfigManager::initializeYii2WebApp(['components' => ['db' => [
        'dsn' => sprintf(
            'mysql:host=%s;dbname=%s',
            Env::get('MYSQL_HOST'),
            Env::get('MYSQL_DATABASE')
        ),
        'username' => Env::get('MYSQL_USER'),
        'password' => Env::get('MYSQL_PASSWORD'),
    ]]]);
    $logger = new Psr3SyslogLogger('silauth', 'status');
    $system = new System($logger);
    $system->reportStatus();
    
} catch (Throwable $t) {
    
    echo sprintf(
        '%s (%s)',
        $t->getMessage(),
        $t->getCode()
    );
    \http_response_code(500);
}
