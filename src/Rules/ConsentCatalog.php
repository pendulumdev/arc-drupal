<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Known cookies. A row is added only when the site already sets that cookie
 * or attaches a known library.
 */
final class ConsentCatalog {

  /**
   * Catalog ids matched by the request cookies or library ids.
   *
   * @param array<int, string> $cookie_names
   *   Cookie names on the request.
   * @param array<int, string> $handles
   *   Drupal library ids.
   *
   * @return list<string>
   */
  public static function matching_ids(array $cookie_names, array $handles): array {
    $ids = [];
    foreach (self::entries() as $entry) {
      if (self::matches_handle($entry, $handles) || self::matches_any_cookie($entry, $cookie_names)) {
        $ids[] = $entry['id'];
      }
    }
    return $ids;
  }

  /**
   * Appends catalog rows that are newly detected. Existing rows are not edited.
   * Dismissed ids are skipped.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Current rows.
   * @param array<int, string> $dismissed
   *   Dismissed catalog ids.
   * @param array<int, string> $cookie_names
   *   Cookie names.
   * @param array<int, string> $handles
   *   Library ids.
   *
   * @return array{rows: array<int, array<string, mixed>>, changed: bool}
   */
  public static function merge(array $rows, array $dismissed, array $cookie_names, array $handles): array {
    $present = [];
    foreach ($rows as $row) {
      $id = isset($row['catalog_id']) ? (string) $row['catalog_id'] : '';
      if ('' !== $id) {
        $present[$id] = TRUE;
      }
    }
    $skip = array_fill_keys($dismissed, TRUE);
    $changed = FALSE;
    foreach (self::matching_ids($cookie_names, $handles) as $id) {
      if (isset($present[$id]) || isset($skip[$id])) {
        continue;
      }
      $rows[] = self::row($id);
      $present[$id] = TRUE;
      $changed = TRUE;
    }
    return [
      'rows' => $rows,
      'changed' => $changed,
    ];
  }

  /**
   * @param array<string, mixed> $entry
   *   Catalog entry.
   * @param array<int, string> $handles
   *   Library ids.
   */
  private static function matches_handle(array $entry, array $handles): bool {
    foreach ($entry['handles'] as $handle) {
      if (in_array($handle, $handles, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $entry
   *   Catalog entry.
   * @param array<int, string> $names
   *   Cookie names.
   */
  private static function matches_any_cookie(array $entry, array $names): bool {
    foreach ($names as $name) {
      if (self::matches_cookie($entry, (string) $name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $entry
   *   Catalog entry.
   * @param string $name
   *   Cookie name.
   */
  private static function matches_cookie(array $entry, string $name): bool {
    foreach ($entry['exclude_prefixes'] as $prefix) {
      if (str_starts_with($name, $prefix)) {
        return FALSE;
      }
    }
    foreach ($entry['prefixes'] as $prefix) {
      if (str_starts_with($name, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Row for a catalog id. Unknown ids return an empty manual-shaped row
   * that sanitize will drop because the name is empty.
   *
   * @return array<string, string>
   */
  private static function row(string $id): array {
    foreach (self::entries() as $entry) {
      if ($entry['id'] !== $id) {
        continue;
      }
      return [
        'id' => 'cat_' . $entry['id'],
        'catalog_id' => $entry['id'],
        'name' => $entry['name'],
        'label' => $entry['label'],
        'description' => $entry['description'],
        'requirement' => $entry['requirement'],
        'type' => $entry['type'],
        'scripts' => '',
        'match' => $entry['match'],
      ];
    }
    return [
      'id' => '',
      'catalog_id' => '',
      'name' => '',
      'label' => '',
      'description' => '',
      'requirement' => 'optional',
      'type' => '',
      'scripts' => '',
      'match' => 'exact',
    ];
  }

  /**
   * Known cookies. Prefix order uses exclusions so SESS does not swallow SSESS,
   * and _ga does not swallow _ga_ or _gat.
   *
   * @return list<array<string, mixed>>
   */
  private static function entries(): array {
    $analytics = ['google_tag/gtag', 'google_tag/gtm'];
    return [
      self::entry(
        'drupal_session_secure',
        'SSESS',
        'prefix',
        'Drupal secure session',
        'Keeps an editor signed in over HTTPS. Required, and the browser will not let this banner delete it.',
        'required',
        'Necessary',
        ['SSESS'],
        [],
        []
      ),
      self::entry(
        'drupal_session',
        'SESS',
        'prefix',
        'Drupal session',
        'Keeps an editor signed in. Required, and the browser will not let this banner delete it.',
        'required',
        'Necessary',
        ['SESS'],
        ['SSESS'],
        []
      ),
      self::entry(
        'ga',
        '_ga',
        'exact',
        'Google Analytics',
        'Distinguishes visitors. The cookie name is _ga.',
        'optional',
        'Analytics',
        ['_ga'],
        ['_ga_', '_gat'],
        $analytics
      ),
      self::entry(
        'ga_container',
        '_ga_',
        'prefix',
        'Google Analytics property',
        'Distinguishes visitors for one Analytics property. The cookie name starts with _ga_.',
        'optional',
        'Analytics',
        ['_ga_'],
        [],
        $analytics
      ),
      self::entry(
        'gid',
        '_gid',
        'prefix',
        'Google Analytics session',
        'Distinguishes visitors for about a day. The cookie name starts with _gid.',
        'optional',
        'Analytics',
        ['_gid'],
        [],
        $analytics
      ),
      self::entry(
        'gat',
        '_gat',
        'prefix',
        'Google Analytics throttle',
        'Throttles Analytics requests. The cookie name starts with _gat.',
        'optional',
        'Analytics',
        ['_gat'],
        [],
        $analytics
      ),
    ];
  }

  /**
   * @param array<int, string> $prefixes
   *   Names that count as this cookie.
   * @param array<int, string> $exclude
   *   Prefixes that belong to another entry.
   * @param array<int, string> $handles
   *   Library ids that count as this cookie.
   *
   * @return array<string, mixed>
   */
  private static function entry(
    string $id,
    string $name,
    string $name_match,
    string $label,
    string $description,
    string $requirement,
    string $type,
    array $prefixes,
    array $exclude,
    array $handles,
  ): array {
    return [
      'id' => $id,
      'name' => $name,
      'match' => $name_match,
      'label' => $label,
      'description' => $description,
      'requirement' => $requirement,
      'type' => $type,
      'prefixes' => $prefixes,
      'exclude_prefixes' => $exclude,
      'handles' => $handles,
    ];
  }

}
