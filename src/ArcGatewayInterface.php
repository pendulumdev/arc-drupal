<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Arc HTTP calls used by the connect flow.
 */
interface ArcGatewayInterface {

  /**
   * @return array<string, mixed>
   */
  public function me(string $env, string $token): array;

  /**
   * @return array<int, array{id: string, name: string, slug: string}>
   */
  public function projects(string $env, string $token): array;

  /**
   * @return array{id: string, name: string, slug: string}
   */
  public function project(string $env, string $token, string $projectId): array;

  /**
   * @return array<int, array{id: string, projectId: string, name: string, baseUrl: string}>
   */
  public function sites(string $env, string $token, string $projectId): array;

  /**
   * @param array<string, mixed> $body
   *
   * @return array<string, mixed>
   */
  public function createSite(string $env, string $token, string $projectId, array $body, string $idempotencyKey): array;

  /**
   * @return array<string, mixed>
   */
  public function scores(string $env, string $token, string $siteId): array;

  /**
   * @return array<string, mixed>
   */
  public function analytics(string $env, string $token, string $siteId): array;

  /**
   * @return array<string, mixed>
   */
  public function report(string $env, string $token, string $siteId, string $service): array;

  /**
   * @return array{bytes: string, type: string}
   */
  public function reportMedia(string $env, string $token, string $runId, string $file): array;

  /**
   * @param array<int, string> $files
   *
   * @return array<string, mixed>
   */
  public function reportMediaBatch(string $env, string $token, string $runId, array $files): array;

  /**
   * @return array<string, mixed>
   */
  public function runs(string $env, string $token, string $siteId): array;

  /**
   * @return array<string, mixed>
   */
  public function enqueueRun(string $env, string $token, string $siteId, string $service, string $key): array;

  /**
   * @return array<string, mixed>
   */
  public function googleConnectHandoff(string $env, string $token, string $siteId, string $returnUri, string $state): array;

  /**
   * @param array<int, string> $emails
   *
   * @return array<string, mixed>
   */
  public function inviteStatus(string $env, string $token, string $projectId, array $emails): array;

  /**
   * @param array<int, string> $emails
   *
   * @return array<string, mixed>
   */
  public function createInvites(string $env, string $token, string $projectId, array $emails, string $key): array;

  /**
   * @return array<string, mixed>
   */
  public function createInvite(string $env, string $token, string $projectId, string $email, string $continuePath, string $key): array;

  /**
   * @return array<string, mixed>
   */
  public function seoTargets(string $env, string $token, string $siteId): array;

  /**
   * @return array<string, mixed>
   */
  public function startSeoTargetSync(string $env, string $token, string $siteId, string $key): array;

  /**
   * @param array{upserts: array<int, array<string, string>>, deletes: array<int, string>} $batch
   *
   * @return array<string, mixed>
   */
  public function appendSeoTargetBatch(string $env, string $token, string $siteId, string $syncId, array $batch, string $key): array;

  /**
   * @param array<string, mixed> $body
   *
   * @return array<string, mixed>
   */
  public function commitSeoTargetSync(string $env, string $token, string $siteId, string $syncId, array $body, string $key): array;

  /**
   * @return array<string, mixed>
   */
  public function abandonSeoTargetSync(string $env, string $token, string $siteId, string $syncId, string $key): array;

  /**
   * @return array<string, mixed>
   */
  public function exchangeCode(string $env, string $code, string $verifier, string $redirectUri): array;

  /**
   * @return array<string, mixed>
   */
  public function refresh(string $env, string $refresh): array;

}
