<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Site\Settings;

/**
 * Portal and API hosts from the Arc public API.
 */
final class ArcConfig {

  public const VERSION = '0.1.0';

  public const CLIENT_ID = 'arc_app_drupal';

  /**
   * Hosts by environment. No trailing slash.
   *
   * @var array<string, array{portal: string, api: string}>
   */
  private const HOSTS = [
    'production' => [
      'portal' => 'https://arc.pendulumdev.co.uk',
      'api' => 'https://api.arc.pendulumdev.co.uk',
    ],
    'preview' => [
      'portal' => 'https://arc.preview.pendulumdev.co.uk',
      'api' => 'https://api.arc.preview.pendulumdev.co.uk',
    ],
  ];

  public function __construct(
    private readonly string $envSetting,
    private readonly string $clientIdSetting,
    private readonly ?string $portalOverride,
    private readonly ?string $apiOverride,
    private readonly bool $allowHttpLocal,
    private readonly bool $rewriteLocalhost,
  ) {}

  /**
   * Reads overrides from settings.php.
   */
  public static function fromSettings(): self {
    $portal = Settings::get('pendulum_arc_portal_url');
    $api = Settings::get('pendulum_arc_api_url');
    return new self(
      (string) Settings::get('pendulum_arc_env', 'production'),
      (string) Settings::get('pendulum_arc_oauth_client_id', self::CLIENT_ID),
      is_string($portal) && $portal !== '' ? $portal : NULL,
      is_string($api) && $api !== '' ? $api : NULL,
      (bool) Settings::get('pendulum_arc_allow_http_local', FALSE),
      (bool) Settings::get('pendulum_arc_rewrite_localhost', FALSE),
    );
  }

  /**
   * Production or preview for the OAuth browser flow.
   */
  public function env(): string {
    return isset(self::HOSTS[$this->envSetting]) ? $this->envSetting : 'production';
  }

  /**
   * Portal origin for an environment.
   */
  public function portal(string $env): string {
    $fallback = self::HOSTS[$env]['portal'] ?? self::HOSTS['production']['portal'];
    if ($this->portalOverride === NULL) {
      return $fallback;
    }
    return $this->requireOrigin($this->portalOverride, $fallback);
  }

  /**
   * API origin for an environment. HTTP calls use this.
   *
   * A local http override is where the process listens. The OAuth resource
   * stays on resource() so the token audience remains the canonical host.
   */
  public function api(string $env): string {
    $fallback = self::HOSTS[$env]['api'] ?? self::HOSTS['production']['api'];
    if ($this->apiOverride === NULL) {
      return $fallback;
    }
    return $this->requireOrigin($this->apiOverride, $fallback);
  }

  /**
   * OAuth resource. An https override replaces it. An http localhost
   * override only changes where HTTP is sent.
   */
  public function resource(string $env): string {
    $http = $this->api($env);
    $parts = parse_url($http);
    if (is_array($parts) && 'https' === strtolower((string) ($parts['scheme'] ?? ''))) {
      return $http;
    }
    return self::HOSTS[$env]['api'] ?? self::HOSTS['production']['api'];
  }

  /**
   * Issued client id. Arc must store this on oauth_clients.
   */
  public function clientId(): string {
    return $this->clientIdSetting !== '' ? $this->clientIdSetting : self::CLIENT_ID;
  }

  /**
   * Whether PHP should send localhost requests to the Docker host.
   */
  public function rewriteLocalhost(): bool {
    return $this->rewriteLocalhost;
  }

  /**
   * Accepts https, or http on localhost when the local flag is on.
   */
  private function requireOrigin(string $base, string $fallback): string {
    $base = rtrim(trim($base), '/');
    $parts = parse_url($base);
    if (!is_array($parts)) {
      return $fallback;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $local = in_array($host, ['localhost', '127.0.0.1'], TRUE);
    if ($scheme === 'https' || ($local && $this->allowHttpLocal && $scheme === 'http')) {
      return $base;
    }
    return $fallback;
  }

}
