<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

Auth::logout();

setFlashMessage('success', 'Você saiu do sistema com sucesso.');
redirect('login.php');
