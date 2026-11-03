<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\pendulum_arc\Rules\InviteRules;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Lists administrators and invites them onto the paired project.
 *
 * The browser sends user ids. Emails stay on the server.
 */
final class InviteService {

  public function __construct(
    private readonly ConnectionStoreInterface $store,
    private readonly ConnectService $connect,
    private readonly ArcGatewayInterface $client,
    private readonly EntityTypeManagerInterface $entities,
    private readonly IdGeneratorInterface $ids,
  ) {}

  /**
   * One page of administrators and whether each is on the Arc project.
   *
   * @return array<string, mixed>
   */
  public function page(int $page, string $search = ''): array {
    $search = InviteRules::search($search);
    $projectId = $this->projectId();
    $listed = $this->administrators($page, $search);
    $emails = [];
    foreach ($listed['users'] as $user) {
      $email = InviteRules::email($user->getEmail() ?? '');
      if ($email !== '') {
        $emails[$email] = $email;
      }
    }
    $membership = $this->membership($projectId, array_values($emails));
    if ($membership['reconnect']) {
      return [
        'reconnect' => TRUE,
        'message' => InviteRules::reconnectMessage(),
        'page' => $listed['page'],
        'pageSize' => InviteRules::PAGE_SIZE,
        'total' => $listed['total'],
        'search' => $search,
        'users' => [],
      ];
    }
    $users = [];
    foreach ($listed['users'] as $user) {
      $users[] = self::listedUser($user, $membership['rows']);
    }
    return [
      'reconnect' => FALSE,
      'page' => $listed['page'],
      'pageSize' => InviteRules::PAGE_SIZE,
      'total' => $listed['total'],
      'search' => $search,
      'users' => $users,
    ];
  }

  /**
   * Invites the checked administrators. Other pages are not included.
   *
   * @param array<int, mixed> $rawIds
   *   User ids from the modal.
   *
   * @return array<string, mixed>
   */
  public function send(array $rawIds): array {
    $parsed = InviteRules::userIds($rawIds);
    if (!$parsed['ok']) {
      throw new ArcApiException($parsed['message'], 400);
    }
    $projectId = $this->projectId();
    $results = [];
    $pending = [];
    $emails = [];
    foreach ($parsed['ids'] as $userId) {
      $user = $this->entities->getStorage('user')->load($userId);
      if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasPermission('administer pendulum arc')) {
        $results[$userId] = self::result($userId, '', '', 'skipped', 'This user cannot use Arc.');
        continue;
      }
      $name = self::displayName($user);
      $email = InviteRules::email($user->getEmail() ?? '');
      if ($email === '') {
        $results[$userId] = self::result($userId, $name, '', 'skipped', InviteRules::checkbox('', '', '')['label']);
        continue;
      }
      $pending[$userId] = [
        'name' => $name,
        'email' => $email,
      ];
      $emails[$email] = $email;
    }
    if ($pending === []) {
      return [
        'reconnect' => FALSE,
        'results' => self::ordered($parsed['ids'], $results),
      ];
    }
    $membership = $this->membership($projectId, array_values($emails));
    if ($membership['reconnect']) {
      return [
        'reconnect' => TRUE,
        'message' => InviteRules::reconnectMessage(),
        'results' => self::ordered($parsed['ids'], $results),
      ];
    }
    $toSend = [];
    foreach ($pending as $userId => $person) {
      $known = $membership['rows'][$person['email']] ?? NULL;
      $status = is_array($known) ? $known['status'] : '';
      $account = is_array($known) ? $known['account'] : '';
      $box = InviteRules::checkbox($person['email'], $status, $account);
      if ($box['disabled']) {
        $results[$userId] = self::result($userId, $person['name'], $person['email'], 'skipped', $box['label']);
        continue;
      }
      $toSend[$userId] = $person;
    }
    if ($toSend === []) {
      return [
        'reconnect' => FALSE,
        'results' => self::ordered($parsed['ids'], $results),
      ];
    }
    $addresses = [];
    foreach ($toSend as $person) {
      $addresses[$person['email']] = $person['email'];
    }
    try {
      $created = $this->connect->withArc(function (string $env, string $token, string $siteId) use ($projectId, $addresses) {
        return $this->client->createInvites($env, $token, $projectId, array_values($addresses), $this->ids->generate());
      });
    }
    catch (ArcApiException $error) {
      if ($error->errorCode() === 'INSUFFICIENT_SCOPE') {
        return [
          'reconnect' => TRUE,
          'message' => InviteRules::reconnectMessage(),
          'results' => self::ordered($parsed['ids'], $results),
        ];
      }
      throw $error;
    }
    $byEmail = [];
    $rows = isset($created['results']) && is_array($created['results']) ? $created['results'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $email = InviteRules::email((string) ($row['email'] ?? ''));
      if ($email === '') {
        continue;
      }
      $byEmail[$email] = $row;
    }
    foreach ($toSend as $userId => $person) {
      $row = $byEmail[$person['email']] ?? NULL;
      if (!is_array($row)) {
        $results[$userId] = self::result($userId, $person['name'], $person['email'], 'refused', 'Arc did not return a result for this person.');
        continue;
      }
      $outcome = InviteRules::listResult($row);
      $results[$userId] = self::result($userId, $person['name'], $person['email'], $outcome['outcome'], $outcome['message']);
    }
    return [
      'reconnect' => FALSE,
      'results' => self::ordered($parsed['ids'], $results),
    ];
  }

  /**
   * Paired project, or an error when this install cannot invite yet.
   */
  private function projectId(): string {
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      throw new ArcApiException('Connect this site to Arc first.', 401);
    }
    $projectId = (string) ($creds['projectId'] ?? '');
    if ($projectId === '') {
      throw new ArcApiException('Reconnect this site in Arc before inviting administrators.', 400);
    }
    return $projectId;
  }

  /**
   * Membership for these emails. Reconnect is true when the grant cannot invite.
   *
   * @param array<int, string> $emails
   *   Normalized emails.
   *
   * @return array{reconnect: bool, rows: array<string, array{status: string, account: string}>}
   */
  private function membership(string $projectId, array $emails): array {
    if ($emails === []) {
      return [
        'reconnect' => FALSE,
        'rows' => [],
      ];
    }
    try {
      $payload = $this->connect->withArc(function (string $env, string $token, string $siteId) use ($projectId, $emails) {
        return $this->client->inviteStatus($env, $token, $projectId, $emails);
      });
    }
    catch (ArcApiException $error) {
      if ($error->errorCode() === 'INSUFFICIENT_SCOPE') {
        return [
          'reconnect' => TRUE,
          'rows' => [],
        ];
      }
      throw $error;
    }
    return [
      'reconnect' => FALSE,
      'rows' => InviteRules::statusIndex($payload),
    ];
  }

  /**
   * One page of users who can administer Arc on this site.
   *
   * @return array{users: array<int, \Drupal\user\UserInterface>, total: int, page: int}
   */
  private function administrators(int $page, string $search): array {
    $page = max(1, $page);
    $query = $this->administratorQuery($search);
    $total = (int) $query->count()->execute();
    $pageQuery = $this->administratorQuery($search);
    $pageQuery->sort('name', 'ASC');
    $pageQuery->range(($page - 1) * InviteRules::PAGE_SIZE, InviteRules::PAGE_SIZE);
    $users = [];
    $ids = $pageQuery->execute();
    $loaded = $this->entities->getStorage('user')->loadMultiple($ids);
    foreach ($ids as $id) {
      $user = $loaded[$id] ?? NULL;
      if ($user instanceof UserInterface) {
        $users[] = $user;
      }
    }
    return [
      'users' => $users,
      'total' => $total,
      'page' => $page,
    ];
  }

  /**
   * Users who can administer Arc. The caller adds sort and range.
   */
  private function administratorQuery(string $search): QueryInterface {
    $query = $this->entities->getStorage('user')->getQuery()->accessCheck(FALSE);
    $query->condition('status', 1);
    $query->condition('uid', 0, '>');
    $allowed = $query->orConditionGroup();
    $roles = $this->arcRoles();
    if ($roles !== []) {
      $allowed->condition('roles', $roles, 'IN');
    }
    $allowed->condition('uid', 1);
    $query->condition($allowed);
    if ($search !== '') {
      $match = $query->orConditionGroup();
      $match->condition('name', $search, 'CONTAINS');
      $match->condition('mail', $search, 'CONTAINS');
      $query->condition($match);
    }
    return $query;
  }

  /**
   * Roles that include administer pendulum arc, including the admin role.
   *
   * @return array<int, string>
   */
  private function arcRoles(): array {
    $ids = [];
    foreach ($this->entities->getStorage('user_role')->loadMultiple() as $role) {
      if (!$role instanceof RoleInterface) {
        continue;
      }
      if ($role->isAdmin() || $role->hasPermission('administer pendulum arc')) {
        $ids[] = $role->id();
      }
    }
    return $ids;
  }

  /**
   * @param array<string, array{status: string, account: string}> $rows
   *   Status keyed by email.
   *
   * @return array<string, mixed>
   */
  private static function listedUser(UserInterface $user, array $rows): array {
    $email = InviteRules::email($user->getEmail() ?? '');
    $status = '';
    $account = '';
    if ($email !== '' && isset($rows[$email])) {
      $status = $rows[$email]['status'];
      $account = $rows[$email]['account'];
    }
    $box = InviteRules::checkbox($email, $status, $account);
    return [
      'id' => (int) $user->id(),
      'name' => self::displayName($user),
      'email' => $email,
      'status' => $status,
      'account' => $account,
      'checked' => $box['checked'],
      'disabled' => $box['disabled'],
      'label' => $box['label'],
    ];
  }

  private static function displayName(UserInterface $user): string {
    $name = trim(strip_tags($user->getDisplayName()));
    if ($name === '') {
      $name = $user->getAccountName();
    }
    return $name;
  }

  /**
   * @return array{id: int, name: string, email: string, outcome: string, message: string}
   */
  private static function result(int $id, string $name, string $email, string $outcome, string $message): array {
    return [
      'id' => $id,
      'name' => $name,
      'email' => $email,
      'outcome' => $outcome,
      'message' => $message,
    ];
  }

  /**
   * @param array<int, int> $ids
   *   Submitted ids.
   * @param array<int, array{id: int, name: string, email: string, outcome: string, message: string}> $results
   *   Keyed by id.
   *
   * @return array<int, array{id: int, name: string, email: string, outcome: string, message: string}>
   */
  private static function ordered(array $ids, array $results): array {
    $out = [];
    foreach ($ids as $id) {
      if (isset($results[$id])) {
        $out[] = $results[$id];
      }
    }
    return $out;
  }

}
