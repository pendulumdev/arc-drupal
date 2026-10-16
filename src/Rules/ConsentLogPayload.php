<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Log payload. Logging off, a bad action, or an unknown cookie id rejects
 * the row. Decline always refuses every optional cookie.
 */
final class ConsentLogPayload {

  /**
   * @param array<int, string> $allowed_ids
   *   Row ids the visitor allowed.
   * @param array<int, array<string, mixed>> $rows
   *   Cookie rows.
   *
   * @return array{action: string, allowed: list<string>, refused: list<string>, ip: string, user_agent: string}|null
   */
  public static function build(
    bool $logging_enabled,
    bool $disable_identifiable,
    string $action,
    array $allowed_ids,
    array $rows,
    string $ip,
    string $user_agent,
  ): ?array {
    if (!$logging_enabled || ('accept' !== $action && 'decline' !== $action)) {
      return NULL;
    }

    $known = [];
    foreach ($rows as $row) {
      if (!is_array($row) || !isset($row['id'])) {
        continue;
      }
      $known[(string) $row['id']] = $row;
    }

    $requested = [];
    foreach ($allowed_ids as $id) {
      $id = (string) $id;
      if (!isset($known[$id])) {
        return NULL;
      }
      $requested[$id] = TRUE;
    }

    $allowed = [];
    $refused = [];
    foreach ($known as $id => $row) {
      $name = (string) ($row['name'] ?? '');
      $required = 'required' === ($row['requirement'] ?? '');
      $keep = $required || ('accept' === $action && isset($requested[$id]));
      if ($keep) {
        $allowed[] = $name;
      }
      elseif (!$required) {
        $refused[] = $name;
      }
    }

    return [
      'action' => $action,
      'allowed' => $allowed,
      'refused' => $refused,
      'ip' => $disable_identifiable ? '' : self::clip($ip, 45),
      'user_agent' => $disable_identifiable ? '' : self::clip($user_agent, 500),
    ];
  }

  /**
   * @param string $value
   *   Text.
   * @param int $limit
   *   Maximum length.
   */
  private static function clip(string $value, int $limit): string {
    $value = trim(str_replace("\0", '', $value));
    if (strlen($value) > $limit) {
      return substr($value, 0, $limit);
    }
    return $value;
  }

}
