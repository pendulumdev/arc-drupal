<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\ArcApiException;
use Drupal\pendulum_arc\ArcConfig;
use Drupal\pendulum_arc\ConnectService;
use Drupal\pendulum_arc\Rules\Pkce;
use Drupal\pendulum_arc\Rules\SecretBox;
use Drupal\pendulum_arc\Rules\SiteDraft;
use Drupal\pendulum_arc\ScreenAccess;
use PHPUnit\Framework\TestCase;

/**
 * PKCE, sealing, draft checks, and screen rules.
 *
 * @covers \Drupal\pendulum_arc\ConnectService
 * @covers \Drupal\pendulum_arc\Rules\Pkce
 * @covers \Drupal\pendulum_arc\Rules\SecretBox
 * @covers \Drupal\pendulum_arc\Rules\SiteDraft
 * @covers \Drupal\pendulum_arc\ScreenAccess
 * @covers \Drupal\pendulum_arc\ArcConfig
 */
final class ConnectRulesTest extends TestCase {

  public function testChallengeIsS256WithoutPadding(): void {
    $verifier = Pkce::verifier();
    $challenge = Pkce::challenge($verifier);
    self::assertGreaterThanOrEqual(43, strlen($verifier));
    self::assertStringNotContainsString('=', $challenge);
    self::assertStringNotContainsString('+', $challenge);
    self::assertStringNotContainsString('/', $challenge);
    $again = rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
    self::assertSame($again, $challenge);
  }

  public function testMissingSiteAsksTheAdminToReconnect(): void {
    $missing = new ArcApiException('Not found.', 404);
    self::assertStringContainsString(
      'Reconnect to attach the site',
      ConnectService::adminMessage($missing),
    );
    $other = new ArcApiException('Arc did not accept those credentials.', 401);
    self::assertSame(
      'Arc did not accept those credentials.',
      ConnectService::adminMessage($other),
    );
  }

  public function testConnectScreenStaysOpen(): void {
    self::assertFalse(ScreenAccess::requiresConnection('pendulum_arc.connect'));
    self::assertTrue(ScreenAccess::requiresConnection('pendulum_arc.dashboard'));
    self::assertTrue(ScreenAccess::requiresConnection('pendulum_arc.scores'));
    self::assertFalse(ScreenAccess::requiresConnection('system.admin'));
  }

  public function testSecretBoxRoundTrip(): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $sealed = SecretBox::seal('arc_live_public_secret', $key);
    self::assertStringNotContainsString('arc_live_public_secret', $sealed);
    self::assertSame('arc_live_public_secret', SecretBox::open($sealed, $key));
  }

  public function testDraftRequiresProject(): void {
    $projects = [
      ['id' => 'client_a', 'name' => 'A'],
      ['id' => 'client_b', 'name' => 'B'],
    ];
    $errors = SiteDraft::validate('Example', 'https://example.com', '', $projects);
    self::assertSame(SiteDraft::PROJECT, $errors['projectId']);
    self::assertSame('client_b', SiteDraft::resolve_project('client_b', $projects));
  }

  public function testHttpLocalhostIsRefusedUnlessTheLocalFlagIsOn(): void {
    $closed = new ArcConfig('production', ArcConfig::CLIENT_ID, 'http://localhost:3001', 'http://localhost:8090', FALSE, FALSE);
    self::assertSame('https://arc.pendulumdev.co.uk', $closed->portal('production'));
    self::assertSame('https://api.arc.pendulumdev.co.uk', $closed->resource('production'));

    $open = new ArcConfig('production', ArcConfig::CLIENT_ID, 'http://localhost:3001', 'http://localhost:8090', TRUE, TRUE);
    self::assertSame('http://localhost:3001', $open->portal('production'));
    self::assertSame('https://api.arc.pendulumdev.co.uk', $open->resource('production'));
    self::assertSame('http://localhost:8090', $open->api('production'));
  }

}
