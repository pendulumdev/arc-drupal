<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\pendulum_arc\Rules\Pairing;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Bearer calls and the OAuth token exchange.
 */
final class ArcClient implements ArcGateway {

  public function __construct(
    private readonly ClientInterface $http,
    private readonly ArcConfig $config,
    private readonly string $version,
    private readonly string $origin,
  ) {}

  public function me(string $env, string $token): array {
    return $this->json('GET', $this->config->api($env) . '/v1/me', $token);
  }

  public function projects(string $env, string $token): array {
    $projects = [];
    foreach ($this->pages($this->config->api($env) . '/v1/projects', $token) as $page) {
      $projects = array_merge($projects, Pairing::projects($page));
    }
    return $projects;
  }

  public function project(string $env, string $token, string $projectId): array {
    $this->assertId($projectId);
    $url = $this->config->api($env) . '/v1/projects/' . rawurlencode($projectId);
    return Pairing::project($this->json('GET', $url, $token));
  }

  public function sites(string $env, string $token, string $projectId): array {
    $this->assertId($projectId);
    $url = $this->config->api($env) . '/v1/projects/' . rawurlencode($projectId) . '/sites';
    $sites = [];
    foreach ($this->pages($url, $token) as $page) {
      $sites = array_merge($sites, Pairing::sites($page, $projectId));
    }
    return $sites;
  }

  public function createSite(string $env, string $token, string $projectId, array $body, string $idempotencyKey): array {
    $this->assertId($projectId);
    $url = $this->config->api($env) . '/v1/projects/' . rawurlencode($projectId) . '/sites';
    return $this->json('POST', $url, $token, $body, ['Idempotency-Key' => $idempotencyKey]);
  }

  public function scores(string $env, string $token, string $siteId): array {
    $this->assertId($siteId);
    $url = $this->config->api($env) . '/v1/sites/' . rawurlencode($siteId) . '/scores';
    return $this->json('GET', $url, $token);
  }

  public function analytics(string $env, string $token, string $siteId): array {
    $this->assertId($siteId);
    $url = $this->config->api($env) . '/v1/sites/' . rawurlencode($siteId) . '/analytics';
    return $this->json('GET', $url, $token);
  }

  public function report(string $env, string $token, string $siteId, string $service): array {
    $this->assertId($siteId);
    if (!in_array($service, ['seo', 'a11y', 'ai', 'security', 'brand'], TRUE)) {
      throw new ArcApiException('That report is not available.', 400);
    }
    $url = $this->config->api($env) . '/v1/sites/' . rawurlencode($siteId) . '/reports/' . rawurlencode($service);
    return $this->json('GET', $url, $token);
  }

  public function runs(string $env, string $token, string $siteId): array {
    $this->assertId($siteId);
    $url = $this->config->api($env) . '/v1/sites/' . rawurlencode($siteId) . '/runs';
    return $this->json('GET', $url, $token);
  }

  public function enqueueRun(string $env, string $token, string $siteId, string $service, string $key): array {
    $this->assertId($siteId);
    if (!in_array($service, Pairing::run_services(), TRUE)) {
      throw new ArcApiException('That report is not available.', 400);
    }
    if (1 !== preg_match('/^[A-Za-z0-9_-]{8,200}$/', $key)) {
      throw new ArcApiException('Arc refused the request. Try again in a moment.', 400);
    }
    $url = $this->config->api($env) . '/v1/sites/' . rawurlencode($siteId) . '/runs';
    return $this->json('POST', $url, $token, ['service' => $service], ['Idempotency-Key' => $key]);
  }

  public function exchangeCode(string $env, string $code, string $verifier, string $redirectUri): array {
    return $this->form($this->config->portal($env) . '/oauth/token', [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $redirectUri,
      'client_id' => $this->config->clientId(),
      'code_verifier' => $verifier,
      'resource' => $this->config->resource($env),
    ]);
  }

  public function refresh(string $env, string $refresh): array {
    return $this->form($this->config->portal($env) . '/oauth/token', [
      'grant_type' => 'refresh_token',
      'refresh_token' => $refresh,
      'client_id' => $this->config->clientId(),
      'resource' => $this->config->resource($env),
    ]);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function pages(string $url, string $token): array {
    $pages = [];
    $cursor = '';
    for ($i = 0; $i < 20; $i++) {
      $next = $url;
      if ($cursor !== '') {
        $next .= (str_contains($url, '?') ? '&' : '?') . 'cursor=' . rawurlencode($cursor);
      }
      $page = $this->json('GET', $next, $token);
      $pages[] = $page;
      $cursor = Pairing::next_cursor($page);
      if ($cursor === '') {
        break;
      }
    }
    return $pages;
  }

  /**
   * @param array<string, mixed>|null $body
   * @param array<string, string> $extra
   *
   * @return array<string, mixed>
   */
  private function json(string $method, string $url, string $token, ?array $body = NULL, array $extra = []): array {
    $headers = array_merge([
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
      'User-Agent' => $this->userAgent(),
    ], $extra);
    $payload = NULL;
    if ($body !== NULL) {
      $headers['Content-Type'] = 'application/json';
      $payload = json_encode($body, JSON_THROW_ON_ERROR);
    }
    return $this->request($method, $url, $headers, $payload);
  }

  /**
   * @param array<string, string> $body
   *
   * @return array<string, mixed>
   */
  private function form(string $url, array $body): array {
    $headers = [
      'Accept' => 'application/json',
      'Content-Type' => 'application/x-www-form-urlencoded',
      'User-Agent' => $this->userAgent(),
    ];
    $payload = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
    return $this->request('POST', $url, $headers, $payload);
  }

  /**
   * @param array<string, string> $headers
   *
   * @return array<string, mixed>
   */
  private function request(string $method, string $url, array $headers, ?string $body): array {
    $url = $this->prepareUrl($url, $headers);
    $options = [
      'headers' => $headers,
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'timeout' => 20,
    ];
    if ($body !== NULL) {
      $options['body'] = $body;
    }
    try {
      $response = $this->http->request($method, $url, $options);
    }
    catch (GuzzleException) {
      throw new ArcApiException('Arc could not be reached. Try again in a moment.', 0);
    }
    $status = $response->getStatusCode();
    $raw = (string) $response->getBody();
    $json = json_decode($raw, TRUE);
    if ($status < 200 || $status >= 300) {
      throw new ArcApiException(self::safeMessage($json, $status), $status);
    }
    return is_array($json) ? $json : [];
  }

  /**
   * Rewrites localhost to the Docker host and keeps the Host header.
   *
   * @param array<string, string> $headers
   */
  private function prepareUrl(string $url, array &$headers): string {
    if (!$this->config->rewriteLocalhost()) {
      return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['host'] ?? '') !== 'localhost' || !isset($parts['port'])) {
      return $url;
    }
    $headers['Host'] = 'localhost:' . $parts['port'];
    $rewritten = preg_replace('#://localhost:#', '://host.docker.internal:', $url, 1);
    return is_string($rewritten) ? $rewritten : $url;
  }

  /**
   * @param mixed $json
   */
  private static function safeMessage($json, int $status): string {
    if ($status === 401) {
      return 'Arc did not accept those credentials.';
    }
    if ($status === 403) {
      return 'This Arc account cannot do that.';
    }
    if (is_array($json) && isset($json['error']) && is_array($json['error'])) {
      $message = $json['error']['message'] ?? '';
      if (is_string($message)) {
        $message = trim(strip_tags($message));
        if ($message !== '' && strlen($message) <= 180 && !preg_match('/bearer|token|api[_ ]?key/i', $message)) {
          return $message;
        }
      }
    }
    return 'Arc refused the request. Try again in a moment.';
  }

  private function assertId(string $id): void {
    if (1 !== preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id)) {
      throw new ArcApiException('That Arc id is not valid.', 400);
    }
  }

  private function userAgent(): string {
    return 'PendulumArc-Drupal/' . $this->version . '; ' . $this->origin;
  }

}
