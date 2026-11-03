<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\pendulum_arc\Rules\PageAlerts;

/**
 * Screen banners. The menu count is the number of alerts that count.
 */
final class Alerts {

  public function __construct(
    private readonly ConnectionStoreInterface $store,
    private readonly GoogleFlagInterface $google,
    private readonly KeyphraseList $keyphrases,
  ) {}

  /**
   * @param array{status?: string, message?: string}|null $flash
   *   One-shot notice already taken from the store.
   *
   * @return array<int, array<string, mixed>>
   */
  public function forScreen(string $screen, ?array $flash, string $connectUrl): array {
    $connected = !empty($this->store->publicView()['connected']);
    return PageAlerts::collect(
      $flash,
      $this->keyphrases->needsSync($connected),
      $connectUrl,
      $screen === 'connect',
      $connected && $this->google->missing(),
      !$connected,
    );
  }

  /**
   * Toolbar bubble. Only the keyphrase alert counts.
   */
  public function menuCount(): int {
    $connected = !empty($this->store->publicView()['connected']);
    return PageAlerts::count(PageAlerts::collect(
      NULL,
      $this->keyphrases->needsSync($connected),
      '',
      TRUE,
      FALSE,
      FALSE,
    ));
  }

}
