<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Pause between refresh-lock polls.
 */
interface Sleeper {

  public function pause(): void;

}
