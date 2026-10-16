<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * BaseUrl comparison for pairing a WordPress home URL to an Arc site.
 */
final class SiteOrigin {

  public const MAX_LENGTH = 2048;

  /**
   * Returns a normalized http(s) URL, or null when it cannot be a baseUrl.
   *
   * @param string $raw
   *   User or WordPress URL.
   */
  public static function normalize(string $raw): ?string {
    $value = trim($raw);
    if ('' === $value || strlen($value) > self::MAX_LENGTH) {
      return NULL;
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) && !preg_match('/^https?:\/\//i', $value)) {
      return NULL;
    }
    if (!preg_match('/^https?:\/\//i', $value)) {
      $value = 'https://' . $value;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests load this file without WordPress.
    $parts = parse_url($value);
    if (!is_array($parts)) {
      return NULL;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = strtolower((string) ($parts['host'] ?? ''));
    if (('http' !== $scheme && 'https' !== $scheme) || '' === $host) {
      return NULL;
    }

    $path = (string) ($parts['path'] ?? '/');
    if ('' === $path) {
      $path = '/';
    }
    if (!str_ends_with($path, '/')) {
      $path .= '/';
    }

    $port = '';
    if (isset($parts['port'])) {
      $number = (int) $parts['port'];
      $omit   = ('http' === $scheme && 80 === $number) || ('https' === $scheme && 443 === $number);
      if (!$omit) {
        $port = ':' . $number;
      }
    }

    $out = $scheme . '://' . $host . $port . $path;
    if (isset($parts['query']) && is_string($parts['query']) && '' !== $parts['query']) {
      $out .= '?' . $parts['query'];
    }
    if (strlen($out) > self::MAX_LENGTH) {
      return NULL;
    }
    return $out;
  }

  /**
   * Host, port, and path used to pair a site. http and https are one site.
   *
   * @param string $normalized
   *   Output of normalize().
   */
  public static function pairing_key(string $normalized): string {
    return (string) preg_replace('#^https?://#i', '', $normalized);
  }

  /**
   * Sites whose baseUrl is the same host, port, and path.
   *
   * The scheme is ignored, so a Drupal address stored as http matches an
   * Arc site stored as https. www and a subdirectory stay different sites.
   *
   * @param array<int, array<string, mixed>> $sites
   *   Arc site rows.
   * @param string $origin
   *   Drupal site address.
   *
   * @return array<int, array<string, string>>
   */
  public static function matching(array $sites, string $origin): array {
    $want = self::normalize($origin);
    if (NULL === $want) {
      return [];
    }
    $want_key = self::pairing_key($want);

    $out = [];
    foreach ($sites as $site) {
      if (!is_array($site)) {
        continue;
      }
      $row = self::site_row($site);
      if (NULL === $row) {
        continue;
      }
      $base = self::normalize($row['baseUrl']);
      if (NULL !== $base && self::pairing_key($base) === $want_key) {
        $out[] = $row;
      }
    }
    return $out;
  }

  /**
   * Reads the site fields the plugin stores.
   *
   * @param array<string, mixed> $site
   *   Raw site object.
   *
   * @return array{id: string, projectId: string, name: string, baseUrl: string}|null
   */
  public static function site_row(array $site): ?array {
    $id       = self::field($site, ['id', 'siteId', 'site_id']);
    $project  = self::field($site, ['projectId', 'project_id']);
    $name     = self::field($site, ['name']);
    $base_url = self::field($site, ['baseUrl', 'base_url']);
    if ('' === $id || '' === $base_url) {
      return NULL;
    }
    return [
      'id'        => $id,
      'projectId' => $project,
      'name'      => $name,
      'baseUrl'   => $base_url,
    ];
  }

  /**
   * First non-empty string among the keys.
   *
   * @param array<string, mixed> $row
   *   Source object.
   * @param array<int, string> $keys
   *   Candidate keys.
   */
  public static function field(array $row, array $keys): string {
    foreach ($keys as $key) {
      if (!isset($row[$key]) || is_array($row[$key])) {
        continue;
      }
      $value = trim((string) $row[$key]);
      if ('' !== $value) {
        return $value;
      }
    }
    return '';
  }

}
