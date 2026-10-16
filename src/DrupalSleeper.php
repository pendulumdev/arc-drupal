<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Waits 200ms between refresh polls.
 */
final class DrupalSleeper implements Sleeper {

  public function pause(): void {
    usleep(200000);
  }

}
