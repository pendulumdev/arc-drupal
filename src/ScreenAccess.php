<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Which screens need a stored connection.
 */
final class ScreenAccess {

  /**
   * Connect stays open. Every other module route needs a connection.
   */
  public static function requiresConnection(string $route): bool {
    return str_starts_with($route, 'pendulum_arc.') && $route !== 'pendulum_arc.connect';
  }

}
