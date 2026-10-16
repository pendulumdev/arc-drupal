<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\Rules\SiteOrigin;
use PHPUnit\Framework\TestCase;

/**
 * Site URL pairing.
 *
 * @covers \Drupal\pendulum_arc\Rules\SiteOrigin
 */
final class SiteOriginTest extends TestCase {

  public function testAddsTrailingSlash(): void {
    self::assertSame('https://example.com/', SiteOrigin::normalize('https://example.com'));
    self::assertSame('https://example.com/blog/', SiteOrigin::normalize('https://example.com/blog'));
  }

  public function testHttpHomeMatchesTheHttpsSite(): void {
    $sites = [
      [
        'id' => 'site_https',
        'projectId' => 'client_a',
        'name' => 'Harbour',
        'baseUrl' => 'https://www.folkestoneseafront.com/',
      ],
    ];
    $matched = SiteOrigin::matching($sites, 'http://www.folkestoneseafront.com');
    self::assertCount(1, $matched);
    self::assertSame('site_https', $matched[0]['id']);
  }

  public function testWwwAndApexAreDifferentSites(): void {
    $sites = [
      [
        'id' => 'site_apex',
        'projectId' => 'client_a',
        'name' => 'Apex',
        'baseUrl' => 'https://example.com/',
      ],
      [
        'id' => 'site_www',
        'projectId' => 'client_a',
        'name' => 'WWW',
        'baseUrl' => 'https://www.example.com/',
      ],
    ];
    $matched = SiteOrigin::matching($sites, 'https://example.com');
    self::assertCount(1, $matched);
    self::assertSame('site_apex', $matched[0]['id']);
  }

  public function testRejectsFtpScheme(): void {
    self::assertNull(SiteOrigin::normalize('ftp://example.com'));
  }

}
