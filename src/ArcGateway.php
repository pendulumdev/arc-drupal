<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

/**
 * Arc HTTP calls used by the connect flow.
 */
interface ArcGateway {

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
  public function exchangeCode(string $env, string $code, string $verifier, string $redirectUri): array;

  /**
   * @return array<string, mixed>
   */
  public function refresh(string $env, string $refresh): array;

}
