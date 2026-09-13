<?php

declare(strict_types=1);

use App\Core\Session;

require __DIR__ . '/includes/bootstrap.php';

Session::destruir();

header('Location: ' . url('login.php'));
exit;
