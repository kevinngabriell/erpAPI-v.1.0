<?php

require_once __DIR__ . '/../v2/config.php';

define('JWT_EXPIRY_SECONDS', 36000); // 10 hours
define('JWT_ALGORITHM', 'HS256');
define('JWT_LEEWAY_SECONDS', 5); // tolerate clock drift between app servers
