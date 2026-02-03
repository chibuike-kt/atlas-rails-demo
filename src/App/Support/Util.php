<?php
declare(strict_types=1);

namespace App\Support;

final class Util {
  public static function uuid(): string {
    return bin2hex(random_bytes(16));
  }

  public static function now(): string {
    return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
  }

  public static function jsonEncode(array $data): string {
    return json_encode($data, JSON_UNESCAPED_SLASHES);
  }

  public static function jsonDecode(string $raw): array {
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
  }

  public static function hashRequest(string $method, string $path, string $rawBody): string {
    return hash('sha256', $method . "\n" . $path . "\n" . $rawBody);
  }
}
