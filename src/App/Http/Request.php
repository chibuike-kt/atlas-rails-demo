<?php
declare(strict_types=1);

namespace App\Http;

final class Request {
  public static function raw(): string {
    return file_get_contents('php://input') ?: '';
  }

  public static function json(): array {
    $raw = self::raw();
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
  }

  public static function header(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
  }

  public static function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  }

  public static function path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return parse_url($uri, PHP_URL_PATH) ?? '/';
  }
}
