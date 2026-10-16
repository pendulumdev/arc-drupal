<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\pendulum_arc\Rules\Pairing;
use Drupal\pendulum_arc\Rules\SecretBox;

/**
 * State and expirable key-value. Tokens are sealed before they are written.
 */
final class ConnectionStore implements ConnectionStoreInterface {

  private const STATE_KEY = 'pendulum_arc.connection';

  public function __construct(
    private readonly StateInterface $state,
    private readonly KeyValueExpirableFactoryInterface $expirableFactory,
    private readonly string $hashSalt,
  ) {}

  public function isConnected(): bool {
    $view = $this->publicView();
    return !empty($view['connected']);
  }

  public function publicView(): array {
    $stored = $this->state->get(self::STATE_KEY);
    if (!is_array($stored) || empty($stored['site_id'])) {
      return ['connected' => FALSE];
    }
    $env = (string) ($stored['env'] ?? 'production');
    $config = ArcConfig::fromSettings();
    return [
      'connected' => TRUE,
      'method' => (string) ($stored['method'] ?? ''),
      'env' => $env,
      'accountLabel' => (string) ($stored['account_label'] ?? ''),
      'siteId' => (string) $stored['site_id'],
      'projectId' => (string) ($stored['project_id'] ?? ''),
      'projectSlug' => (string) ($stored['project_slug'] ?? ''),
      'siteName' => (string) ($stored['site_name'] ?? ''),
      'siteUrl' => (string) ($stored['site_url'] ?? ''),
      'connectedAt' => (string) ($stored['connected_at'] ?? ''),
      'portalUrl' => Pairing::site_portal_url(
        $config->portal($env),
        (string) ($stored['project_slug'] ?? ''),
        (string) $stored['site_id'],
      ),
    ];
  }

  public function credentials(): ?array {
    $stored = $this->state->get(self::STATE_KEY);
    if (!is_array($stored) || empty($stored['site_id'])) {
      return NULL;
    }
    try {
      $access = SecretBox::open((string) $stored['access_token'], $this->key());
    }
    catch (\RuntimeException) {
      return NULL;
    }
    $refresh = '';
    if (!empty($stored['refresh_token'])) {
      try {
        $refresh = SecretBox::open((string) $stored['refresh_token'], $this->key());
      }
      catch (\RuntimeException) {
        $refresh = '';
      }
    }
    return [
      'method' => (string) ($stored['method'] ?? ''),
      'env' => (string) ($stored['env'] ?? 'production'),
      'accessToken' => $access,
      'refreshToken' => $refresh,
      'expiresAt' => (int) ($stored['expires_at'] ?? 0),
      'siteId' => (string) $stored['site_id'],
    ];
  }

  public function save(array $record): void {
    $refresh = (string) ($record['refreshToken'] ?? '');
    $this->state->set(self::STATE_KEY, [
      'method' => (string) $record['method'],
      'env' => (string) $record['env'],
      'access_token' => SecretBox::seal((string) $record['accessToken'], $this->key()),
      'refresh_token' => $refresh === '' ? '' : SecretBox::seal($refresh, $this->key()),
      'expires_at' => (int) ($record['expiresAt'] ?? 0),
      'account_label' => (string) ($record['accountLabel'] ?? ''),
      'site_id' => (string) $record['siteId'],
      'project_id' => (string) ($record['projectId'] ?? ''),
      'project_slug' => (string) ($record['projectSlug'] ?? ''),
      'site_name' => (string) ($record['siteName'] ?? ''),
      'site_url' => (string) ($record['siteUrl'] ?? ''),
      'connected_at' => (string) ($record['connectedAt'] ?? gmdate('c')),
    ]);
  }

  public function setProjectSlug(string $slug): void {
    $stored = $this->state->get(self::STATE_KEY);
    if (!is_array($stored)) {
      return;
    }
    $stored['project_slug'] = $slug;
    $this->state->set(self::STATE_KEY, $stored);
  }

  public function updateTokens(string $access, string $refresh, int $expiresAt): void {
    $stored = $this->state->get(self::STATE_KEY);
    if (!is_array($stored)) {
      return;
    }
    $stored['access_token'] = SecretBox::seal($access, $this->key());
    if ($refresh !== '') {
      $stored['refresh_token'] = SecretBox::seal($refresh, $this->key());
    }
    $stored['expires_at'] = $expiresAt;
    $this->state->set(self::STATE_KEY, $stored);
  }

  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

  public function putPending(int $userId, array $pending): void {
    $key = $this->key();
    $pending['accessToken'] = SecretBox::seal((string) $pending['accessToken'], $key);
    $refresh = (string) ($pending['refreshToken'] ?? '');
    $pending['refreshToken'] = $refresh === '' ? '' : SecretBox::seal($refresh, $key);
    $this->expirable()->setWithExpire($this->pendingKey($userId), $pending, 30 * 60);
  }

  public function getPending(int $userId): ?array {
    $stored = $this->expirable()->get($this->pendingKey($userId));
    if (!is_array($stored)) {
      return NULL;
    }
    try {
      $stored['accessToken'] = SecretBox::open((string) $stored['accessToken'], $this->key());
    }
    catch (\RuntimeException) {
      return NULL;
    }
    if (!empty($stored['refreshToken'])) {
      try {
        $stored['refreshToken'] = SecretBox::open((string) $stored['refreshToken'], $this->key());
      }
      catch (\RuntimeException) {
        $stored['refreshToken'] = '';
      }
    }
    return $stored;
  }

  public function pendingPublic(int $userId): ?array {
    $pending = $this->getPending($userId);
    if ($pending === NULL) {
      return NULL;
    }
    return [
      'status' => (string) ($pending['status'] ?? ''),
      'hasApiKey' => ($pending['method'] ?? 'api_key') === 'api_key',
      'projects' => $pending['projects'] ?? [],
      'matches' => $pending['matches'] ?? [],
      'draft' => $pending['draft'] ?? NULL,
      'portalUrl' => (string) ($pending['portalUrl'] ?? ''),
    ];
  }

  public function clearPending(int $userId): void {
    $this->expirable()->delete($this->pendingKey($userId));
  }

  public function putOauth(int $userId, array $oauth): void {
    $oauth['verifier'] = SecretBox::seal((string) $oauth['verifier'], $this->key());
    $this->expirable()->setWithExpire($this->oauthKey($userId), $oauth, 30 * 60);
  }

  public function takeOauth(int $userId, string $state): ?array {
    $key = $this->oauthKey($userId);
    $stored = $this->expirable()->get($key);
    $this->expirable()->delete($key);
    if (!is_array($stored)) {
      return NULL;
    }
    $expected = (string) ($stored['state'] ?? '');
    if ($expected === '' || !hash_equals($expected, $state)) {
      return NULL;
    }
    try {
      $stored['verifier'] = SecretBox::open((string) $stored['verifier'], $this->key());
    }
    catch (\RuntimeException) {
      return NULL;
    }
    return $stored;
  }

  public function putNotice(int $userId, string $status, string $message): void {
    $this->expirable()->setWithExpire($this->noticeKey($userId), [
      'status' => $status,
      'message' => $message,
    ], 2 * 60);
  }

  public function takeNotice(int $userId): ?array {
    $key = $this->noticeKey($userId);
    $stored = $this->expirable()->get($key);
    $this->expirable()->delete($key);
    if (!is_array($stored) || empty($stored['message'])) {
      return NULL;
    }
    return [
      'status' => (string) ($stored['status'] ?? 'error'),
      'message' => (string) $stored['message'],
    ];
  }

  /**
   * Deletes connection state. Expirable keys are removed on uninstall.
   */
  public function wipe(): void {
    $this->clear();
    $this->expirable()->deleteAll();
  }

  private function key(): string {
    return sodium_crypto_generichash(
      $this->hashSalt . '|pendulum-arc-drupal-v1',
      '',
      SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
    );
  }

  private function pendingKey(int $userId): string {
    return 'pending.' . $userId;
  }

  private function oauthKey(int $userId): string {
    return 'oauth.' . $userId;
  }

  private function noticeKey(int $userId): string {
    return 'notice.' . $userId;
  }

  private function expirable(): KeyValueStoreExpirableInterface {
    return $this->expirableFactory->get('pendulum_arc');
  }

}
