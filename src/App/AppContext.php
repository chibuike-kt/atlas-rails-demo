<?php
declare(strict_types=1);

namespace App;

final class AppContext {
  public function __construct(
    public readonly string $correlationId,
    public readonly string $actor
  ) {}
}
