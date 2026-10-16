<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Opaque id for an idempotency key.
 */
interface IdGeneratorInterface {

  public function generate(): string;

}
