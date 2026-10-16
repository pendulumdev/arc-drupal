<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Opaque id for an idempotency key.
 */
interface IdGenerator {

  public function generate(): string;

}
