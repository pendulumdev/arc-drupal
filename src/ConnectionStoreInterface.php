<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Stored connection and short-lived connect attempts.
 */
interface ConnectionStoreInterface {

  public function isConnected(): bool;

  /**
   * @return array<string, mixed>
   */
  public function publicView(): array;

  /**
   * @return array<string, mixed>|null
   */
  public function credentials(): ?array;

  /**
   * @param array<string, mixed> $record
   */
  public function save(array $record): void;

  public function setProjectSlug(string $slug): void;

  public function updateTokens(string $access, string $refresh, int $expiresAt): void;

  public function clear(): void;

  /**
   * @param array<string, mixed> $pending
   */
  public function putPending(int $userId, array $pending): void;

  /**
   * @return array<string, mixed>|null
   */
  public function getPending(int $userId): ?array;

  /**
   * @return array<string, mixed>|null
   */
  public function pendingPublic(int $userId): ?array;

  public function clearPending(int $userId): void;

  /**
   * @param array<string, mixed> $oauth
   */
  public function putOauth(int $userId, array $oauth): void;

  /**
   * @return array<string, mixed>|null
   */
  public function takeOauth(int $userId, string $state): ?array;

  public function putNotice(int $userId, string $status, string $message): void;

  /**
   * @return array{status: string, message: string}|null
   */
  public function takeNotice(int $userId): ?array;

}
