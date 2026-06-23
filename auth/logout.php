<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
clear_auth_cookie();
session_destroy();
redirect('/login');
