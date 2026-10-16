<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Single-flight lock around an OAuth refresh.
 */
interface RefreshLockInterface {

  public function claim(): bool;

  public function release(): void;

  public function held(): bool;

}
