<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Scan cursor. One entity type at a time, then the next.
 *
 * A full page keeps the id cursor. A short page finishes that entity type.
 */
final class KeyphraseCursor {

  /**
   * @return array{index: int, after: string, done: bool}
   */
  public static function start(): array {
    return [
      'index' => 0,
      'after' => '0',
      'done' => FALSE,
    ];
  }

  /**
   * @return array{index: int, after: string, done: bool}
   */
  public static function advance(int $index, string $after, int $count, int $batch, string $lastId, int $sources): array {
    if ($sources < 1) {
      return [
        'index' => 0,
        'after' => '0',
        'done' => TRUE,
      ];
    }
    if ($count >= $batch) {
      $nextAfter = $lastId !== '' && $lastId !== $after ? $lastId : $after;
      return [
        'index' => $index,
        'after' => $nextAfter,
        'done' => FALSE,
      ];
    }
    $next = $index + 1;
    if ($next < $sources) {
      return [
        'index' => $next,
        'after' => '0',
        'done' => FALSE,
      ];
    }
    return [
      'index' => $next,
      'after' => '0',
      'done' => TRUE,
    ];
  }

}
