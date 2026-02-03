<?php
declare(strict_types=1);

namespace App\Http;

final class JsonResponse {
  public static function send(int $code, array $data, array $headers = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    foreach ($headers as $k => $v) header($k . ': ' . $v);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
  }
}
