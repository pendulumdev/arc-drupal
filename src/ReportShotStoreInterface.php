<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Screenshots saved for an accessibility report.
 */
interface ReportShotStoreInterface {

  /**
   * @return array{bytes: string, type: string}|null
   */
  public function read(string $runId, string $file): ?array;

  public function write(string $runId, string $file, string $bytes, string $type): void;

  /**
   * Remembers a name Arc does not have, so a later view does not ask again.
   */
  public function markMissing(string $runId, string $file): void;

  public function has(string $runId, string $file): bool;

}
