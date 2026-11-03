<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Pure decisions for the Connect Google handoff. Arc is not called here.
 */
final class GoogleConnect {

  /**
   * Ticket path Arc will accept as an invite continue value, or empty.
   */
  public static function ticketPath(string $url): string {
    $parts = parse_url($url);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
    $continue = $path . '?' . $query;
    if (1 !== preg_match('/^\/connect\/google\?ticket=[A-Za-z0-9_-]{16,128}$/', $continue)) {
      return '';
    }
    return $continue;
  }

  /**
   * One invite-status row for this email, or NULL when the body is unusable.
   *
   * @param array<string, mixed> $payload
   *   Invite-status body.
   *
   * @return array{status: string, account: string}|null
   */
  public static function inviteRow(array $payload, string $email): ?array {
    $results = $payload['results'] ?? NULL;
    if (!is_array($results)) {
      return NULL;
    }
    foreach ($results as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (strtolower((string) ($row['email'] ?? '')) !== $email) {
        continue;
      }
      $status = (string) ($row['status'] ?? '');
      $account = (string) ($row['account'] ?? '');
      if (!in_array($status, ['member', 'pending', 'none'], TRUE)) {
        return NULL;
      }
      if (!in_array($account, ['active', 'absent'], TRUE)) {
        return NULL;
      }
      return [
        'status' => $status,
        'account' => $account,
      ];
    }
    return NULL;
  }

  /**
   * Where this person goes next: ticket, inbox, a new invite, or an unusable status.
   *
   * @return 'url'|'email'|'invite'|'invalid'
   */
  public static function destination(string $status, string $account): string {
    if ($status === 'pending') {
      return 'email';
    }
    if ($account === 'active' || $status === 'member') {
      return 'url';
    }
    if ($account === 'absent' && $status === 'none') {
      return 'invite';
    }
    return 'invalid';
  }

}
