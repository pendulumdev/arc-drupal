<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;

/**
 * Stores consent choices and limits how often one address can write.
 */
final class ConsentLog {

  public const TABLE = 'pendulum_arc_consent_log';

  public const FLOOD = 'pendulum_arc.consent_log';

  public function __construct(
    private readonly Connection $database,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * Inserts one accepted payload.
   *
   * @param array{action: string, allowed: array<int, string>, refused: array<int, string>, ip: string, user_agent: string} $row
   *   Payload.
   * @param int $version
   *   Consent version.
   */
  public function insert(array $row, int $version): void {
    $this->database->insert(self::TABLE)->fields([
      'created_gmt' => gmdate('Y-m-d H:i:s'),
      'consent_version' => $version,
      'action' => $row['action'],
      'allowed' => (string) json_encode(array_values($row['allowed'])),
      'refused' => (string) json_encode(array_values($row['refused'])),
      'ip' => $row['ip'],
      'user_agent' => $row['user_agent'],
    ])->execute();
  }

  /**
   * Newest rows for the Advanced tab.
   *
   * @param int $limit
   *   Maximum rows.
   *
   * @return list<array<string, mixed>>
   */
  public function latest(int $limit = 100): array {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    $rows = $this->database->select(self::TABLE, 'log')
      ->fields('log', [
        'id',
        'created_gmt',
        'consent_version',
        'action',
        'allowed',
        'refused',
        'ip',
        'user_agent',
      ])
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    if ($rows === NULL) {
      return [];
    }
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'id' => (int) $row->id,
        'created_gmt' => (string) $row->created_gmt,
        'consent_version' => (int) $row->consent_version,
        'action' => (string) $row->action,
        'allowed' => self::names((string) $row->allowed),
        'refused' => self::names((string) $row->refused),
        'ip' => (string) $row->ip,
        'user_agent' => (string) $row->user_agent,
      ];
    }
    return $out;
  }

  /**
   * True when this address has already logged 30 choices in the last hour.
   *
   * @param string $ip
   *   Remote address. Empty still counts as one bucket.
   */
  public function limited(string $ip): bool {
    $identifier = $ip === '' ? 'unknown' : $ip;
    if (!$this->flood->isAllowed(self::FLOOD, 30, 3600, $identifier)) {
      return TRUE;
    }
    $this->flood->register(self::FLOOD, 3600, $identifier);
    return FALSE;
  }

  /**
   * @param string $json
   *   JSON list of cookie names.
   *
   * @return list<string>
   */
  private static function names(string $json): array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $names = [];
    foreach ($decoded as $name) {
      if (is_string($name) && $name !== '') {
        $names[] = $name;
      }
    }
    return $names;
  }

}
