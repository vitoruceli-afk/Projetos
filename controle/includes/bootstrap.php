<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BOOTSTRAP
|--------------------------------------------------------------------------
|
| Ponto único de inicialização. Toda página inclui este arquivo.
|
*/

use App\Core\Config;
use App\Core\Session;

$raiz = dirname(__DIR__);

require $raiz . '/src/autoload.php';

Config::carregar($raiz . '/config/config.php');

date_default_timezone_set(Config::get('app.timezone', 'America/Sao_Paulo'));

Session::iniciar();
