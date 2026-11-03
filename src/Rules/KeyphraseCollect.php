<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Front page is the site focus. Other paths are page rows. Query URLs are skipped.
 */
final class KeyphraseCollect {

  /**
   * @param array<int, string> $candidates
   *   Plugin phrases in preference order.
   *
   * @return array{role: string, path: string, phrase: string}
   */
  public static function row(string $url, string $origin, bool $front, array $candidates): array {
    $phrase = KeyphrasePhrase::first($candidates);
    $path = KeyphrasePath::fromUrl($url, $origin);
    if ($path === NULL) {
      return [
        'role' => 'skip',
        'path' => '',
        'phrase' => '',
      ];
    }
    if ($front || $path === '/') {
      return [
        'role' => $phrase === '' ? 'clear-focus' : 'focus',
        'path' => '/',
        'phrase' => $phrase,
      ];
    }
    if ($phrase === '') {
      return [
        'role' => 'clear',
        'path' => $path,
        'phrase' => '',
      ];
    }
    return [
      'role' => 'page',
      'path' => $path,
      'phrase' => $phrase,
    ];
  }

}
