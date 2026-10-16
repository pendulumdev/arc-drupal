<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * This install's home URL and site name.
 */
interface SiteProfile {

  /**
   * @return array{origin: string, name: string}
   */
  public function get(): array;

}
