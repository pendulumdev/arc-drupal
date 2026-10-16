<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\pendulum_arc\Rules\DraftException;
use Drupal\pendulum_arc\Rules\Pairing;
use Drupal\pendulum_arc\Rules\Pkce;
use Drupal\pendulum_arc\Rules\SiteDraft;
use Drupal\pendulum_arc\Rules\SiteOrigin;

/**
 * Turns a credential into a stored site connection.
 */
final class ConnectService {

  public function __construct(
    private readonly ConnectionStoreInterface $store,
    private readonly ArcGatewayInterface $client,
    private readonly ArcConfig $config,
    private readonly SiteProfileInterface $profile,
    private readonly RefreshLockInterface $lock,
    private readonly SleeperInterface $sleeper,
    private readonly IdGeneratorInterface $ids,
    private readonly ContentLinkerInterface $links,
  ) {}

  /**
   * Starts an API-key connect. Pairs one match, or asks which site when several match.
   *
   * @return array<string, mixed>
   */
  public function beginApiKey(string $apiKey, int $userId): array {
    $apiKey = trim($apiKey);
    if (!Pairing::is_api_key($apiKey)) {
      throw new ArcApiException('Enter an Arc API key. It starts with arc_live_ or arc_test_.', 400);
    }

    $env = Pairing::env_for_key($apiKey);
    $account = Pairing::account_label($this->client->me($env, $apiKey));

    return $this->offerSites($userId, [
      'method' => 'api_key',
      'env' => $env,
      'accessToken' => $apiKey,
      'refreshToken' => '',
      'expiresAt' => 0,
      'accountLabel' => $account,
    ]);
  }

  /**
   * Uses the saved API key or Arc account to attach this address again.
   *
   * @return array<string, mixed>
   */
  public function reconnect(int $userId): array {
    $creds = $this->store->credentials();
    if ($creds === NULL || (string) ($creds['accessToken'] ?? '') === '') {
      throw new ArcApiException('Arc is not connected on this site.', 400);
    }
    $view = $this->store->publicView();
    $credential = [
      'method' => (string) ($creds['method'] ?? 'api_key'),
      'env' => (string) ($creds['env'] ?? 'production'),
      'accessToken' => (string) $creds['accessToken'],
      'refreshToken' => (string) ($creds['refreshToken'] ?? ''),
      'expiresAt' => (int) ($creds['expiresAt'] ?? 0),
      'accountLabel' => (string) ($view['accountLabel'] ?? ''),
    ];
    try {
      return $this->offerSites($userId, $credential);
    }
    catch (ArcApiException $error) {
      if ($error->statusCode() !== 401 || $credential['method'] !== 'oauth' || $credential['refreshToken'] === '') {
        throw $error;
      }
    }
    $fresh = $this->refreshedCredentials($creds);
    $again = $this->store->credentials();
    $credential['accessToken'] = (string) $fresh['accessToken'];
    $credential['refreshToken'] = (string) ($fresh['refreshToken'] ?? '');
    if (is_array($again)) {
      $credential['expiresAt'] = (int) ($again['expiresAt'] ?? 0);
    }
    return $this->offerSites($userId, $credential);
  }

  /**
   * Pairs one of the sites already matched for this attempt.
   *
   * @return array<string, mixed>
   */
  public function pairMatch(int $userId, string $siteId): array {
    $pending = $this->requirePending($userId);
    $chosen = NULL;
    foreach ($pending['matches'] as $match) {
      if (is_array($match) && (string) ($match['id'] ?? '') === $siteId) {
        $chosen = $match;
        break;
      }
    }
    if (!is_array($chosen)) {
      throw new ArcApiException('Choose one of the matching sites.', 400);
    }
    $this->saveFromPending($pending, $chosen);
    $this->store->clearPending($userId);
    return [
      'status' => 'connected',
      'connection' => $this->store->publicView(),
    ];
  }

  /**
   * Creates a site with the API key from the pending attempt.
   *
   * @return array<string, mixed>
   */
  public function createSite(int $userId, string $name, string $baseUrl, string $projectId): array {
    $pending = $this->requirePending($userId);
    $projects = is_array($pending['projects'] ?? NULL) ? $pending['projects'] : [];
    if ($projects === []) {
      throw new ArcApiException('Add a project in Arc, then connect again.', 400);
    }
    $errors = SiteDraft::validate($name, $baseUrl, $projectId, $projects);
    if ($errors !== []) {
      throw new DraftException($errors);
    }
    $projectId = SiteDraft::resolve_project($projectId, $projects);
    $normalized = SiteOrigin::normalize($baseUrl);
    $created = $this->client->createSite(
      (string) $pending['env'],
      (string) $pending['accessToken'],
      $projectId,
      [
        'name' => trim($name),
        'baseUrl' => (string) $normalized,
        'auto_run' => TRUE,
        'auto_schedule' => TRUE,
        'auto_sitemap' => TRUE,
      ],
      $this->ids->generate(),
    );
    $site = Pairing::site($created);
    if ($site === NULL) {
      throw new ArcApiException('Arc did not return the site.', 502);
    }
    $this->saveFromPending($pending, $site);
    $this->store->clearPending($userId);
    return [
      'status' => 'connected',
      'connection' => $this->store->publicView(),
    ];
  }

  /**
   * Builds the authorize URL and stores PKCE material.
   *
   * @return array{authorizeUrl: string}
   */
  public function oauthStart(int $userId, string $redirectUri): array {
    $env = $this->config->env();
    $profile = $this->profile->get();
    $state = Pkce::state();
    $verifier = Pkce::verifier();
    $portal = $this->config->portal($env);
    $this->store->putOauth($userId, [
      'state' => $state,
      'verifier' => $verifier,
      'redirectUri' => $redirectUri,
      'env' => $env,
      'portal' => $portal,
    ]);
    $query = [
      'response_type' => 'code',
      'client_id' => $this->config->clientId(),
      'redirect_uri' => $redirectUri,
      'scope' => 'arc:read arc:enqueue',
      'state' => $state,
      'code_challenge' => Pkce::challenge($verifier),
      'code_challenge_method' => 'S256',
      'resource' => $this->config->resource($env),
      'site_origin' => $profile['origin'],
      'site_name' => $profile['name'],
    ];
    return [
      'authorizeUrl' => $portal . '/oauth/authorize?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
    ];
  }

  /**
   * Exchanges the code and stores the site Arc paired.
   *
   * @return array<string, mixed>
   */
  public function completeOauth(int $userId, string $code, string $state, string $issuer): array {
    $oauth = $this->store->takeOauth($userId, $state);
    if ($oauth === NULL) {
      throw new ArcApiException('The sign-in attempt expired or did not match this browser. Start again.', 400);
    }
    if ($issuer === '' || !hash_equals((string) $oauth['portal'], rtrim($issuer, '/'))) {
      throw new ArcApiException('Arc did not confirm the sign-in issuer. Start again.', 400);
    }
    $parsed = Pairing::token($this->client->exchangeCode(
      (string) $oauth['env'],
      $code,
      (string) $oauth['verifier'],
      (string) $oauth['redirectUri'],
    ));
    if ($parsed['accessToken'] === '' || $parsed['site'] === NULL) {
      throw new ArcApiException('Arc did not return a connected site. Approve the connection again.', 502);
    }
    $this->saveConnection(
      'oauth',
      (string) $oauth['env'],
      $parsed['accessToken'],
      $parsed['refreshToken'],
      $this->expiresAt($parsed['expiresIn']),
      '',
      $parsed['site'],
    );
    $this->store->clearPending($userId);
    return [
      'status' => 'connected',
      'connection' => $this->store->publicView(),
    ];
  }

  /**
   * Loads the project slug once so Open in Arc can open this site.
   */
  public function ensureProjectSlug(): void {
    $view = $this->store->publicView();
    if (empty($view['connected']) || (string) ($view['projectSlug'] ?? '') !== '') {
      return;
    }
    $projectId = (string) ($view['projectId'] ?? '');
    $creds = $this->store->credentials();
    if ($projectId === '' || $creds === NULL) {
      return;
    }
    try {
      $project = $this->projectRow($creds, $projectId);
    }
    catch (ArcApiException) {
      return;
    }
    if ($project['slug'] !== '') {
      $this->store->setProjectSlug($project['slug']);
    }
  }

  /**
   * Admin sentence for a failed read of the paired site.
   *
   * A 404 means the stored site is not on this account. Other statuses keep
   * the message Arc already made safe to show.
   */
  public static function adminMessage(ArcApiException $error): string {
    if ($error->statusCode() === 404) {
      return 'Arc cannot find this site on the connected account. Reconnect to attach the site for this Drupal address.';
    }
    return $error->getMessage();
  }

  /**
   * @return array{scores: array<int, array<string, mixed>>, error: string|null}
   */
  public function scores(): array {
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      return [
        'scores' => Pairing::scores([]),
        'error' => 'Connect this site to Arc first.',
      ];
    }
    try {
      $payload = $this->authorized($creds);
    }
    catch (ArcApiException $error) {
      return [
        'scores' => Pairing::scores([]),
        'error' => self::adminMessage($error),
      ];
    }
    return [
      'scores' => Pairing::scores($payload),
      'error' => NULL,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function analytics(): array {
    $empty = Pairing::analytics([]);
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      $empty['error'] = 'Connect this site to Arc first.';
      return $empty;
    }
    try {
      $payload = $this->bearerCall($creds, function (string $token) use ($creds) {
        return $this->client->analytics((string) $creds['env'], $token, (string) $creds['siteId']);
      });
    }
    catch (ArcApiException $error) {
      $empty['error'] = self::adminMessage($error);
      return $empty;
    }
    $view = Pairing::analytics($payload);
    $view['landingPages'] = $this->links->landingPages($view['landingPages']);
    $view['error'] = NULL;
    return $view;
  }

  /**
   * @return array<string, mixed>
   */
  public function report(string $service): array {
    $allowed = ['seo', 'a11y', 'ai', 'security', 'brand'];
    if (!in_array($service, $allowed, TRUE)) {
      $service = '';
    }
    $empty = Pairing::report(['service' => $service]);
    if ($service === '') {
      $empty['error'] = 'That report is not available.';
      return $empty;
    }
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      $empty['error'] = 'Connect this site to Arc first.';
      return $empty;
    }
    try {
      $payload = $this->bearerCall($creds, function (string $token) use ($creds, $service) {
        return $this->client->report((string) $creds['env'], $token, (string) $creds['siteId'], $service);
      });
    }
    catch (ArcApiException $error) {
      $empty['error'] = self::adminMessage($error);
      return $empty;
    }
    $view = Pairing::report($payload);
    $view['service'] = $service;
    $view['blocks'] = $this->links->linkReportBlocks($view['blocks']);
    $view['error'] = NULL;
    return $view;
  }

  /**
   * @return array{busy: object, error: string|null}
   */
  public function runs(): array {
    $empty = [
      'busy' => (object) [],
      'error' => NULL,
    ];
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      $empty['error'] = 'Connect this site to Arc first.';
      return $empty;
    }
    try {
      $payload = $this->bearerCall($creds, function (string $token) use ($creds) {
        return $this->client->runs((string) $creds['env'], $token, (string) $creds['siteId']);
      });
    }
    catch (ArcApiException $error) {
      $empty['error'] = self::adminMessage($error);
      return $empty;
    }
    $view = Pairing::in_flight_runs($payload);
    $view['error'] = NULL;
    return $view;
  }

  /**
   * @return array{runId: string, service: string}
   */
  public function enqueueRun(string $service): array {
    if (!in_array($service, Pairing::run_services(), TRUE)) {
      throw new ArcApiException('That report is not available.', 400);
    }
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      throw new ArcApiException('Connect this site to Arc first.', 401);
    }
    try {
      $payload = $this->bearerCall($creds, function (string $token) use ($creds, $service) {
        return $this->client->enqueueRun(
          (string) $creds['env'],
          $token,
          (string) $creds['siteId'],
          $service,
          $this->ids->generate(),
        );
      });
    }
    catch (ArcApiException $error) {
      if ($error->statusCode() === 403) {
        throw new ArcApiException('Reconnect this site in Arc to allow generating reports.', 403);
      }
      if ($error->statusCode() === 404) {
        throw new ArcApiException(self::adminMessage($error), 404);
      }
      throw $error;
    }
    return Pairing::enqueued_run($payload);
  }

  public function disconnect(int $userId): void {
    $this->store->clear();
    $this->store->clearPending($userId);
  }

  /**
   * @param array<string, mixed> $creds
   *
   * @return array{id: string, name: string, slug: string}
   */
  private function projectRow(array $creds, string $projectId): array {
    try {
      return $this->client->project((string) $creds['env'], (string) $creds['accessToken'], $projectId);
    }
    catch (ArcApiException $error) {
      if ($error->statusCode() !== 401 || $creds['method'] !== 'oauth' || (string) $creds['refreshToken'] === '') {
        throw $error;
      }
    }
    $fresh = $this->refreshedCredentials($creds);
    return $this->client->project((string) $fresh['env'], (string) $fresh['accessToken'], $projectId);
  }

  /**
   * @param array<string, mixed> $creds
   *
   * @return array<string, mixed>
   */
  private function authorized(array $creds): array {
    return $this->bearerCall($creds, function (string $token) use ($creds) {
      return $this->client->scores((string) $creds['env'], $token, (string) $creds['siteId']);
    });
  }

  /**
   * @param array<string, mixed> $creds
   * @param callable(string): array<string, mixed> $call
   *
   * @return array<string, mixed>
   */
  private function bearerCall(array $creds, callable $call): array {
    try {
      return $call((string) $creds['accessToken']);
    }
    catch (ArcApiException $error) {
      if ($error->statusCode() !== 401 || $creds['method'] !== 'oauth' || (string) $creds['refreshToken'] === '') {
        throw $error;
      }
    }
    $fresh = $this->refreshedCredentials($creds);
    return $call((string) $fresh['accessToken']);
  }

  /**
   * Rotates the OAuth refresh token once. A second caller waits for that
   * rotation. Presenting the same refresh token twice revokes the grant.
   *
   * @param array<string, mixed> $creds
   *
   * @return array<string, mixed>
   */
  private function refreshedCredentials(array $creds): array {
    $claimed = $this->lock->claim();
    if (!$claimed) {
      $waited = $this->waitForNewAccessToken($creds);
      if ($waited !== NULL) {
        return array_merge($creds, $waited);
      }
      $claimed = $this->lock->claim();
    }
    if (!$claimed) {
      throw new ArcApiException('Arc did not accept those credentials.', 401);
    }
    try {
      $current = $this->store->credentials();
      if (is_array($current) && $this->accessTokenChanged($creds, $current)) {
        return array_merge($creds, $current);
      }
      $refresh = is_array($current) && (string) $current['refreshToken'] !== ''
        ? (string) $current['refreshToken']
        : (string) $creds['refreshToken'];
      $parsed = Pairing::token($this->client->refresh((string) $creds['env'], $refresh));
      if ($parsed['accessToken'] === '') {
        throw new ArcApiException('Arc did not accept those credentials.', 401);
      }
      $this->store->updateTokens(
        $parsed['accessToken'],
        $parsed['refreshToken'],
        $this->expiresAt($parsed['expiresIn']),
      );
      $creds['accessToken'] = $parsed['accessToken'];
      if ($parsed['refreshToken'] !== '') {
        $creds['refreshToken'] = $parsed['refreshToken'];
      }
      return $creds;
    }
    finally {
      $this->lock->release();
    }
  }

  /**
   * @param array<string, mixed> $creds
   * @param array<string, mixed> $current
   */
  private function accessTokenChanged(array $creds, array $current): bool {
    return (string) $current['accessToken'] !== ''
      && (string) $current['accessToken'] !== (string) $creds['accessToken'];
  }

  /**
   * @param array<string, mixed> $creds
   *
   * @return array<string, mixed>|null
   */
  private function waitForNewAccessToken(array $creds): ?array {
    for ($attempt = 0; $attempt < 15; $attempt++) {
      $this->sleeper->pause();
      $current = $this->store->credentials();
      if (is_array($current) && $this->accessTokenChanged($creds, $current)) {
        return $current;
      }
      if (!$this->lock->held()) {
        return NULL;
      }
    }
    return NULL;
  }

  /**
   * Lists the account's sites and pairs, chooses, or asks to create one.
   *
   * @param array<string, mixed> $credential
   *   Saved or pasted bearer.
   *
   * @return array<string, mixed>
   */
  private function offerSites(int $userId, array $credential): array {
    $env = (string) $credential['env'];
    $token = (string) $credential['accessToken'];
    $profile = $this->profile->get();
    $projects = $this->client->projects($env, $token);
    $portal = $this->config->portal($env);

    if ($projects === []) {
      $this->store->clearPending($userId);
      return [
        'status' => 'needs_project',
        'portalUrl' => $portal,
      ];
    }

    $sites = [];
    foreach ($projects as $project) {
      $sites = array_merge($sites, $this->client->sites($env, $token, $project['id']));
    }
    $matches = SiteOrigin::matching($sites, $profile['origin']);

    if (count($matches) === 1) {
      $this->saveConnection(
        (string) $credential['method'],
        $env,
        $token,
        (string) ($credential['refreshToken'] ?? ''),
        (int) ($credential['expiresAt'] ?? 0),
        (string) ($credential['accountLabel'] ?? ''),
        $matches[0],
      );
      $this->store->clearPending($userId);
      return [
        'status' => 'connected',
        'connection' => $this->store->publicView(),
      ];
    }

    $draft = [
      'name' => $profile['name'],
      'baseUrl' => (string) (SiteOrigin::normalize($profile['origin']) ?? $profile['origin']),
      'projectId' => count($projects) === 1 ? $projects[0]['id'] : '',
    ];
    $status = count($matches) > 1 ? 'choose_site' : 'needs_site';
    $this->store->putPending($userId, [
      'status' => $status,
      'method' => (string) $credential['method'],
      'env' => $env,
      'accessToken' => $token,
      'refreshToken' => (string) ($credential['refreshToken'] ?? ''),
      'expiresAt' => (int) ($credential['expiresAt'] ?? 0),
      'accountLabel' => (string) ($credential['accountLabel'] ?? ''),
      'projects' => $projects,
      'matches' => $matches,
      'draft' => $draft,
      'portalUrl' => $portal,
    ]);

    return [
      'status' => $status,
      'projects' => $projects,
      'matches' => $matches,
      'draft' => $draft,
      'portalUrl' => $portal,
    ];
  }

  /**
   * Keeps the API key or Arc account that started this attempt.
   *
   * @param array<string, mixed> $pending
   *   Pending attempt.
   * @param array<string, mixed> $site
   *   Paired site.
   */
  private function saveFromPending(array $pending, array $site): void {
    $this->saveConnection(
      (string) ($pending['method'] ?? 'api_key'),
      (string) $pending['env'],
      (string) $pending['accessToken'],
      (string) ($pending['refreshToken'] ?? ''),
      (int) ($pending['expiresAt'] ?? 0),
      (string) ($pending['accountLabel'] ?? ''),
      $site,
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function requirePending(int $userId): array {
    $pending = $this->store->getPending($userId);
    if ($pending === NULL) {
      throw new ArcApiException('The connection attempt expired. Start again.', 400);
    }
    return $pending;
  }

  /**
   * @param array<string, mixed> $site
   */
  private function saveConnection(string $method, string $env, string $access, string $refresh, int $expires, string $account, array $site): void {
    $this->store->save([
      'method' => $method,
      'env' => $env,
      'accessToken' => $access,
      'refreshToken' => $refresh,
      'expiresAt' => $expires,
      'accountLabel' => $account,
      'siteId' => (string) ($site['id'] ?? ''),
      'projectId' => (string) ($site['projectId'] ?? ''),
      'siteName' => (string) ($site['name'] ?? ''),
      'siteUrl' => (string) ($site['baseUrl'] ?? ''),
      'connectedAt' => gmdate('c'),
    ]);
  }

  private function expiresAt(int $expiresIn): int {
    if ($expiresIn <= 0) {
      return 0;
    }
    return time() + max(0, $expiresIn - 30);
  }

}
