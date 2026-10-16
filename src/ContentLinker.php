<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Adds view and edit URLs for paths on this site.
 */
interface ContentLinker {

  /**
   * @param array<int, array<string, mixed>> $blocks
   *
   * @return array<int, array<string, mixed>>
   */
  public function linkReportBlocks(array $blocks): array;

  /**
   * @param array<int, mixed> $pages
   *
   * @return array<int, array<string, mixed>>
   */
  public function landingPages(array $pages): array;

}
