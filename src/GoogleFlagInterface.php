<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Remembers whether Analytics is waiting on a Google account.
 */
interface GoogleFlagInterface {

  /**
   * Stores the Analytics setup from a scores response.
   *
   * @param string|null $setup
   *   connect_google, assign_site, not_enabled, or NULL when ready.
   */
  public function remember(?string $setup): void;

  /**
   * True only after scores said there is no Google account.
   */
  public function missing(): bool;

  /**
   * Forgets the flag when this install disconnects.
   */
  public function clear(): void;

}
