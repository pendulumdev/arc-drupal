<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Lock\LockBackendInterface;

/**
 * Drupal lock backend for the refresh rotation.
 */
final class DrupalRefreshLock implements RefreshLock {

  private const NAME = 'pendulum_arc_oauth_refresh';

  public function __construct(
    private readonly LockBackendInterface $lock,
  ) {}

  public function claim(): bool {
    return $this->lock->acquire(self::NAME, 15.0);
  }

  public function release(): void {
    $this->lock->release(self::NAME);
  }

  public function held(): bool {
    return !$this->lock->lockMayBeAvailable(self::NAME);
  }

}
