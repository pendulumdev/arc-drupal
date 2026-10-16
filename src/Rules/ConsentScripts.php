<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Script tag rewrite. A required match, or an allowed optional match, leaves
 * the tag alone. Any other listed library or URL is printed as text/plain.
 */
final class ConsentScripts {

  /**
   * @param string $tag
   *   Script tag HTML.
   * @param string $handle
   *   Drupal library id.
   * @param string $src
   *   Script URL.
   * @param array<int, array<string, mixed>> $cookies
   *   Cookie rows.
   * @param array<int, string> $allowed
   *   Allowed row ids.
   */
  public static function rewrite_tag(string $tag, string $handle, string $src, array $cookies, array $allowed): string {
    $blocking = NULL;
    foreach ($cookies as $cookie) {
      if (!is_array($cookie) || !self::matches($cookie, $handle, $src)) {
        continue;
      }
      $id = isset($cookie['id']) ? (string) $cookie['id'] : '';
      if ('required' === ($cookie['requirement'] ?? '') || in_array($id, $allowed, TRUE)) {
        return $tag;
      }
      if (NULL === $blocking && '' !== $id) {
        $blocking = $id;
      }
    }
    if (NULL === $blocking) {
      return $tag;
    }
    return self::inert($tag, $blocking);
  }

  /**
   * True when a scripts-field line is a Drupal library id (module/library).
   *
   * A scheme, a leading slash, or a file extension is a URL fragment instead.
   * WordPress treats every slash as a URL, which would mis-read library ids.
   */
  public static function is_library_id(string $token): bool {
    if (str_contains($token, '://') || str_starts_with($token, '/')) {
      return FALSE;
    }
    if (1 === preg_match('/\.[A-Za-z0-9]+$/', $token)) {
      return FALSE;
    }
    return 1 === preg_match('/^[A-Za-z][A-Za-z0-9_]*\/[A-Za-z][A-Za-z0-9_.-]*$/', $token);
  }

  /**
   * @param array<string, mixed> $cookie
   *   Cookie row.
   * @param string $handle
   *   Library id.
   * @param string $src
   *   Script URL.
   */
  private static function matches(array $cookie, string $handle, string $src): bool {
    $scripts = isset($cookie['scripts']) ? (string) $cookie['scripts'] : '';
    $lines = preg_split('/\R/', $scripts);
    if (!is_array($lines)) {
      return FALSE;
    }
    foreach ($lines as $token) {
      $token = trim((string) $token);
      if ('' === $token) {
        continue;
      }
      if (self::is_url($token)) {
        if ('' !== $src && str_contains($src, $token)) {
          return TRUE;
        }
        continue;
      }
      if ($token === $handle) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * A token with a scheme, a leading slash, or a file extension is a URL.
   */
  private static function is_url(string $token): bool {
    if (self::is_library_id($token)) {
      return FALSE;
    }
    return str_contains($token, '://')
      || str_starts_with($token, '/')
      || str_contains($token, '.');
  }

  /**
   * @param string $tag
   *   Script tag.
   * @param string $id
   *   Cookie row id.
   */
  private static function inert(string $tag, string $id): string {
    $id = preg_replace('/[^a-z0-9_]/', '', $id) ?? '';
    $original = 'text/javascript';
    if (1 === preg_match('/\stype=(["\'])([^"\']*)\1/i', $tag, $matches)) {
      if ('' !== $matches[2]) {
        $original = $matches[2];
      }
      $replaced = preg_replace('/\stype=(["\'])([^"\']*)\1/i', '', $tag, 1);
      if (is_string($replaced)) {
        $tag = $replaced;
      }
    }
    $original = str_replace(['"', '<', '>'], '', $original);
    $extra = ' type="text/plain" data-arc-script-type="' . $original . '" data-arc-cookie="' . $id . '"';
    $rewritten = preg_replace('/<script\b/i', '<script' . $extra, $tag, 1);
    return is_string($rewritten) ? $rewritten : $tag;
  }

}
