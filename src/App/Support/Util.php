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

  // Canonicalize JSON bodies so whitespace/key-order changes don't break idempotency.
  public static function hashRequest(string $method, string $path, string $rawBody): string {
    $canon = $rawBody;

    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
      $canon = json_encode(self::ksortDeep($decoded), JSON_UNESCAPED_SLASHES);
    }

    return hash('sha256', $method . "\n" . $path . "\n" . $canon);
  }

  private static function ksortDeep(array $arr): array {
    foreach ($arr as $k => $v) {
      if (is_array($v)) $arr[$k] = self::ksortDeep($v);
    }
    ksort($arr);
    return $arr;
  }
}
