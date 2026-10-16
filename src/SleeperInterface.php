<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Pause between refresh-lock polls.
 */
interface SleeperInterface {

  public function pause(): void;

}
