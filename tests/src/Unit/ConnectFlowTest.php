<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\ArcApiException;
use Drupal\pendulum_arc\ArcConfig;
use Drupal\pendulum_arc\ArcGateway;
use Drupal\pendulum_arc\ConnectService;
use Drupal\pendulum_arc\ConnectionStoreInterface;
use Drupal\pendulum_arc\ContentLinker;
use Drupal\pendulum_arc\IdGenerator;
use Drupal\pendulum_arc\RefreshLock;
use Drupal\pendulum_arc\SiteProfile;
use Drupal\pendulum_arc\Sleeper;
use PHPUnit\Framework\TestCase;

/**
 * OAuth state rejection and single-flight token refresh.
 *
 * @covers \Drupal\pendulum_arc\ConnectService
 */
final class ConnectFlowTest extends TestCase {

  public function testOauthStateMismatchIsRejectedAndForgotten(): void {
    $store = new MemoryStore();
    $client = new FakeGateway();
    $service = $this->service($store, $client, new MemoryLock(TRUE));
    $store->putOauth(7, [
      'state' => 'expected',
      'verifier' => 'verifier-value-verifier-value-verifier',
      'redirectUri' => 'https://example.test/admin/pendulum-arc/connect',
      'env' => 'production',
      'portal' => 'https://arc.pendulumdev.co.uk',
    ]);

    try {
      $service->completeOauth(7, 'code', 'wrong', 'https://arc.pendulumdev.co.uk');
      self::fail('Expected the state mismatch to throw.');
    }
    catch (ArcApiException $error) {
      self::assertSame(400, $error->statusCode());
    }
    self::assertNull($store->takeOauth(7, 'expected'));
    self::assertSame([], $client->calls);
  }

  public function testRefreshWaitsWhenAnotherCallerAlreadyRotatedTheToken(): void {
    $store = new MemoryStore();
    $store->save([
      'method' => 'oauth',
      'env' => 'production',
      'accessToken' => 'old-access',
      'refreshToken' => 'refresh-1',
      'expiresAt' => 0,
      'accountLabel' => '',
      'siteId' => 'site_abc',
      'projectId' => 'client_abc',
      'siteName' => 'Example',
      'siteUrl' => 'https://example.com/',
      'connectedAt' => '2026-01-01T00:00:00Z',
    ]);
    $client = new FakeGateway();
    $client->scoreTokens = ['old-access'];
    $lock = new MemoryLock(FALSE);
    $service = $this->service($store, $client, $lock, function () use ($store): void {
      $store->updateTokens('new-access', 'refresh-2', 100);
    });

    $scores = $service->scores();
    self::assertNull($scores['error']);
    self::assertNotContains('refresh', $client->calls);
    self::assertContains('scores:new-access', $client->calls);
  }

  public function testCreateSiteAsksArcToRunAndSchedule(): void {
    $store = new MemoryStore();
    $store->putPending(7, [
      'status' => 'needs_site',
      'env' => 'production',
      'accessToken' => 'arc_live_example_secret',
      'accountLabel' => 'Studio',
      'projects' => [
        ['id' => 'client_abc', 'name' => 'Example'],
      ],
    ]);
    $client = new FakeGateway();
    $service = $this->service($store, $client, new MemoryLock(TRUE));

    $result = $service->createSite(7, 'Example', 'https://example.com', 'client_abc');

    self::assertSame('connected', $result['status']);
    self::assertSame([
      'name' => 'Example',
      'baseUrl' => 'https://example.com/',
      'auto_run' => TRUE,
      'auto_schedule' => TRUE,
      'auto_sitemap' => TRUE,
    ], $client->created);
  }

  public function testReconnectUsesTheSavedApiKey(): void {
    $store = new MemoryStore();
    $store->save([
      'method' => 'api_key',
      'env' => 'production',
      'accessToken' => 'arc_live_example_secret',
      'refreshToken' => '',
      'expiresAt' => 0,
      'siteId' => 'site_old',
      'accountLabel' => 'Studio',
    ]);
    $client = new FakeGateway();
    $client->projects = [
      ['id' => 'client_abc', 'name' => 'Example'],
    ];
    $client->sites = [
      [
        'id' => 'site_new',
        'projectId' => 'client_abc',
        'name' => 'Example',
        'baseUrl' => 'https://example.com/',
      ],
    ];
    $service = $this->service($store, $client, new MemoryLock(TRUE));

    $result = $service->reconnect(7);

    self::assertSame('connected', $result['status']);
    $creds = $store->credentials();
    self::assertIsArray($creds);
    self::assertSame('api_key', $creds['method']);
    self::assertSame('site_new', $creds['siteId']);
    self::assertSame('arc_live_example_secret', $creds['accessToken']);
  }

  private function service(ConnectionStoreInterface $store, ArcGateway $client, RefreshLock $lock, ?callable $onPause = NULL): ConnectService {
    return new ConnectService(
      $store,
      $client,
      new ArcConfig('production', ArcConfig::CLIENT_ID, NULL, NULL, FALSE, FALSE),
      new class implements SiteProfile {

        public function get(): array {
          return ['origin' => 'https://example.com/', 'name' => 'Example'];
        }

      },
      $lock,
      new class($onPause) implements Sleeper {

        public function __construct(private $onPause) {}

        public function pause(): void {
          if (is_callable($this->onPause)) {
            ($this->onPause)();
          }
        }

      },
      new class implements IdGenerator {

        public function generate(): string {
          return 'idempotency-key-1';
        }

      },
      new class implements ContentLinker {

        public function linkReportBlocks(array $blocks): array {
          return $blocks;
        }

        public function landingPages(array $pages): array {
          return $pages;
        }

      },
    );
  }

}

/**
 * In-memory connection store. Tokens stay in the clear for the test.
 */
final class MemoryStore implements ConnectionStoreInterface {

  private ?array $connection = NULL;

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $oauth = [];

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $pending = [];

  public function isConnected(): bool {
    return $this->connection !== NULL;
  }

  public function publicView(): array {
    if ($this->connection === NULL) {
      return ['connected' => FALSE];
    }
    return ['connected' => TRUE, 'projectSlug' => ''];
  }

  public function credentials(): ?array {
    if ($this->connection === NULL) {
      return NULL;
    }
    return [
      'method' => $this->connection['method'],
      'env' => $this->connection['env'],
      'accessToken' => $this->connection['accessToken'],
      'refreshToken' => $this->connection['refreshToken'],
      'expiresAt' => $this->connection['expiresAt'],
      'siteId' => $this->connection['siteId'],
    ];
  }

  public function save(array $record): void {
    $this->connection = $record;
  }

  public function setProjectSlug(string $slug): void {}

  public function updateTokens(string $access, string $refresh, int $expiresAt): void {
    if ($this->connection === NULL) {
      return;
    }
    $this->connection['accessToken'] = $access;
    if ($refresh !== '') {
      $this->connection['refreshToken'] = $refresh;
    }
    $this->connection['expiresAt'] = $expiresAt;
  }

  public function clear(): void {
    $this->connection = NULL;
  }

  public function putPending(int $userId, array $pending): void {
    $this->pending[$userId] = $pending;
  }

  public function getPending(int $userId): ?array {
    return $this->pending[$userId] ?? NULL;
  }

  public function pendingPublic(int $userId): ?array {
    return NULL;
  }

  public function clearPending(int $userId): void {}

  public function putOauth(int $userId, array $oauth): void {
    $this->oauth[$userId] = $oauth;
  }

  public function takeOauth(int $userId, string $state): ?array {
    $stored = $this->oauth[$userId] ?? NULL;
    unset($this->oauth[$userId]);
    if (!is_array($stored) || !hash_equals((string) $stored['state'], $state)) {
      return NULL;
    }
    return $stored;
  }

  public function putNotice(int $userId, string $status, string $message): void {}

  public function takeNotice(int $userId): ?array {
    return NULL;
  }

}

/**
 * Scores fail once for the old token, then succeed.
 */
final class FakeGateway implements ArcGateway {

  /**
   * @var array<int, string> */
  public array $scoreTokens = [];

  /**
   * @var array<int, string> */
  public array $calls = [];

  public function scores(string $env, string $token, string $siteId): array {
    $this->calls[] = 'scores:' . $token;
    if (in_array($token, $this->scoreTokens, TRUE)) {
      throw new ArcApiException('Arc did not accept those credentials.', 401);
    }
    return ['scores' => []];
  }

  public function refresh(string $env, string $refresh): array {
    $this->calls[] = 'refresh';
    return ['access_token' => 'refreshed', 'refresh_token' => 'refresh-2', 'expires_in' => 3600];
  }

  public function me(string $env, string $token): array {
    return [];
  }

  public function projects(string $env, string $token): array {
    return $this->projects;
  }

  /**
   * @var array<int, array<string, mixed>>
   */
  public array $projects = [];

  public function project(string $env, string $token, string $projectId): array {
    return ['id' => $projectId, 'name' => '', 'slug' => ''];
  }

  public function sites(string $env, string $token, string $projectId): array {
    return $this->sites;
  }

  /**
   * @var array<int, array<string, mixed>>
   */
  public array $sites = [];

  /**
   * @var array<string, mixed>|null
   */
  public ?array $created = NULL;

  public function createSite(string $env, string $token, string $projectId, array $body, string $idempotencyKey): array {
    $this->created = $body;
    return [
      'site' => [
        'id' => 'site_new',
        'projectId' => $projectId,
        'name' => (string) ($body['name'] ?? ''),
        'baseUrl' => (string) ($body['baseUrl'] ?? ''),
      ],
    ];
  }

  public function analytics(string $env, string $token, string $siteId): array {
    return [];
  }

  public function report(string $env, string $token, string $siteId, string $service): array {
    return [];
  }

  public function runs(string $env, string $token, string $siteId): array {
    return [];
  }

  public function enqueueRun(string $env, string $token, string $siteId, string $service, string $key): array {
    return [];
  }

  public function exchangeCode(string $env, string $code, string $verifier, string $redirectUri): array {
    $this->calls[] = 'exchange';
    return [];
  }

}

/**
 * A lock that is either free or already held.
 */
final class MemoryLock implements RefreshLock {

  public function __construct(private readonly bool $free) {}

  public function claim(): bool {
    return $this->free;
  }

  public function release(): void {}

  public function held(): bool {
    return !$this->free;
  }

}
