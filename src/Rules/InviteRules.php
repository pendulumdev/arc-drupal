<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Checkbox and page rules for inviting administrators. Arc is not called here.
 */
final class InviteRules {

  /**
   * Administrators on one modal page. Matches the invite-status email cap.
   */
  public const PAGE_SIZE = 100;

  /**
   * Longest name or email fragment the list will match.
   */
  public const SEARCH_MAX = 80;

  /**
   * Name or email fragment. Asterisks are removed so they are not wildcards.
   */
  public static function search(string $raw): string {
    $term = trim(str_replace('*', '', $raw));
    $term = preg_replace('/\s+/', ' ', $term);
    if (!is_string($term)) {
      return '';
    }
    $term = trim($term);
    if (function_exists('mb_substr')) {
      return mb_substr($term, 0, self::SEARCH_MAX);
    }
    return substr($term, 0, self::SEARCH_MAX);
  }

  /**
   * Email Arc will accept, or an empty string.
   */
  public static function email(string $raw): string {
    $email = strtolower(trim($raw));
    if (strlen($email) < 3 || strlen($email) > 254) {
      return '';
    }
    if (1 !== preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
      return '';
    }
    return $email;
  }

  /**
   * Checkbox for one administrator.
   *
   * A missing status is disabled. Someone already on the project, or with
   * an invite already sent, cannot be selected. Anyone not on this project
   * starts checked, whether or not they already have an Arc account.
   *
   * @return array{checked: bool, disabled: bool, label: string}
   */
  public static function checkbox(string $email, string $status, string $account): array {
    if (self::email($email) === '') {
      return [
        'checked' => FALSE,
        'disabled' => TRUE,
        'label' => 'No email address',
      ];
    }
    if ($status === 'member') {
      return [
        'checked' => FALSE,
        'disabled' => TRUE,
        'label' => 'On this project',
      ];
    }
    if ($status === 'pending') {
      return [
        'checked' => FALSE,
        'disabled' => TRUE,
        'label' => 'Invite already sent',
      ];
    }
    if ($status === 'none' && $account === 'absent') {
      return [
        'checked' => TRUE,
        'disabled' => FALSE,
        'label' => 'No Arc account',
      ];
    }
    if ($status === 'none' && $account === 'active') {
      return [
        'checked' => TRUE,
        'disabled' => FALSE,
        'label' => 'Has an Arc account',
      ];
    }
    return [
      'checked' => FALSE,
      'disabled' => TRUE,
      'label' => 'Arc did not return a status for this user.',
    ];
  }

  /**
   * Invite-status rows keyed by email. A row with an unknown status is dropped.
   *
   * @param array<string, mixed> $payload
   *   Arc body.
   *
   * @return array<string, array{status: string, account: string}>
   */
  public static function statusIndex(array $payload): array {
    $results = $payload['results'] ?? NULL;
    $out = [];
    if (!is_array($results)) {
      return $out;
    }
    foreach ($results as $row) {
      if (!is_array($row)) {
        continue;
      }
      $email = self::email((string) ($row['email'] ?? ''));
      $status = (string) ($row['status'] ?? '');
      $account = (string) ($row['account'] ?? '');
      if ($email === '') {
        continue;
      }
      if (!in_array($status, ['member', 'pending', 'none'], TRUE)) {
        continue;
      }
      if (!in_array($account, ['active', 'absent'], TRUE)) {
        continue;
      }
      $out[$email] = [
        'status' => $status,
        'account' => $account,
      ];
    }
    return $out;
  }

  /**
   * Positive user ids, in order, without duplicates.
   *
   * @param array<int, mixed> $raw
   *   Request ids.
   *
   * @return array{ok: true, ids: array<int, int>}|array{ok: false, message: string}
   */
  public static function userIds(array $raw): array {
    $ids = [];
    $seen = [];
    foreach ($raw as $item) {
      if (is_bool($item) || is_array($item) || is_object($item) || !is_scalar($item)) {
        continue;
      }
      if (1 !== preg_match('/^[0-9]+$/', (string) $item)) {
        continue;
      }
      $id = (int) $item;
      if ($id <= 0 || isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $ids[] = $id;
    }
    if ($ids === []) {
      return [
        'ok' => FALSE,
        'message' => 'Choose at least one administrator.',
      ];
    }
    if (count($ids) > self::PAGE_SIZE) {
      return [
        'ok' => FALSE,
        'message' => 'Invite one page of administrators at a time.',
      ];
    }
    return [
      'ok' => TRUE,
      'ids' => $ids,
    ];
  }

  /**
   * Outcome for one address in a list invite response.
   *
   * @param array<string, mixed> $row
   *   Arc result row.
   *
   * @return array{outcome: string, message: string}
   */
  public static function listResult(array $row): array {
    if (!empty($row['invited'])) {
      return [
        'outcome' => 'sent',
        'message' => 'Invite sent.',
      ];
    }
    $code = isset($row['code']) && is_string($row['code']) ? $row['code'] : '';
    $message = self::refusalMessage($code);
    if ($message === '') {
      $message = 'Arc could not invite this person.';
    }
    return [
      'outcome' => 'refused',
      'message' => $message,
    ];
  }

  /**
   * Sentence for a known Arc refusal. Empty when the code is not one of these.
   */
  public static function refusalMessage(string $code): string {
    if ($code === 'SEAT_EXHAUSTED') {
      return 'No user seats remaining in this studio. Upgrade the plan or add a seat pack.';
    }
    if ($code === 'PENDING_INVITE_CAP') {
      return 'Too many open invites for this project. Remove one in Arc first.';
    }
    if ($code === 'ALREADY_MEMBER') {
      return 'Already on this project.';
    }
    if ($code === 'OPEN_INVITE') {
      return 'An invite is already pending for that email.';
    }
    if ($code === 'INSUFFICIENT_SCOPE') {
      return self::reconnectMessage();
    }
    if ($code === 'PREVIEW_EMAIL_DENIED') {
      return 'Preview access is limited to approved email domains.';
    }
    if ($code === 'INVITE_DELIVERY_FAILED') {
      return 'The invite could not be sent. Try again.';
    }
    return '';
  }

  /**
   * Shown when the saved grant cannot invite. Same sentence as Connect Google.
   */
  public static function reconnectMessage(): string {
    return 'Disconnect and Connect with Arc again before inviting someone who has no Arc account. If you already have an Arc account, continue.';
  }

}
