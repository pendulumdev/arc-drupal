<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\State\StateInterface;

/**
 * Whether Arc has a Google account. Remembered when scores are read.
 *
 * A missing value means this install has not read scores yet.
 */
final class GoogleConnection implements GoogleFlagInterface {

  public const STATE_KEY = 'pendulum_arc.google_missing';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  public function remember(?string $setup): void {
    $this->state->set(self::STATE_KEY, $setup === 'connect_google' ? '1' : '0');
  }

  public function missing(): bool {
    return $this->state->get(self::STATE_KEY) === '1';
  }

  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
