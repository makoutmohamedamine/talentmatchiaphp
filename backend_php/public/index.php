<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$config = new Config(dirname(__DIR__));
$database = new Database($config);
$jwt = new Jwt($config);
$auth = new Auth($database, $jwt);
$groq = new GroqClient($config);
$cvExtractor = new CvTextExtractor($config);

$app = new App($config, $database, $auth, $jwt, $groq, $cvExtractor);
$app->handle();

