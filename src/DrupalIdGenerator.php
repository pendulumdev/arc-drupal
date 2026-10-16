<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Component\Uuid\UuidInterface;

/**
 * Drupal UUID service as an idempotency key source.
 */
final class DrupalIdGenerator implements IdGeneratorInterface {

  public function __construct(
    private readonly UuidInterface $uuid,
  ) {}

  public function generate(): string {
    return $this->uuid->generate();
  }

}
