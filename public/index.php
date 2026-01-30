<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Http\Router;
use App\Http\JsonResponse;

$router = new Router();

$router->add('GET', '/', function () {
  header('Content-Type: text/html; charset=utf-8');
  echo "<h1>Atlas Rails Demo</h1><p>API-first demo. Use curl.</p>";
});

$router->add('GET', '/health', function () {
  JsonResponse::send(200, ['ok' => true, 'ts' => (new DateTimeImmutable())->format(DATE_ATOM)]);
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
