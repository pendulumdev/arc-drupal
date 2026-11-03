<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Arc paths are pathnames. A query string cannot be stored.
 */
final class KeyphrasePath {

  /**
   * Pathname, or NULL when the URL is on another origin or still has a query.
   */
  public static function fromUrl(string $url, string $origin): ?string {
    $urlParts = parse_url($url);
    $originParts = parse_url($origin);
    if (!is_array($urlParts) || !is_array($originParts)) {
      return NULL;
    }
    if (!empty($urlParts['query'])) {
      return NULL;
    }
    $host = strtolower((string) ($urlParts['host'] ?? ''));
    $originHost = strtolower((string) ($originParts['host'] ?? ''));
    if ($host === '' || $host !== $originHost) {
      return NULL;
    }
    if (($urlParts['port'] ?? NULL) !== ($originParts['port'] ?? NULL)) {
      return NULL;
    }
    $path = (string) ($urlParts['path'] ?? '/');
    if ($path === '') {
      $path = '/';
    }
    if ($path !== '/') {
      $path = rtrim($path, '/');
    }
    if ($path === '') {
      $path = '/';
    }
    if (!str_starts_with($path, '/')) {
      $path = '/' . $path;
    }
    return $path;
  }

}
