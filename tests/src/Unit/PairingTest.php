<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\Rules\Pairing;
use PHPUnit\Framework\TestCase;

/**
 * Arc response reading.
 *
 * @covers \Drupal\pendulum_arc\Rules\Pairing
 */
final class PairingTest extends TestCase {

  public function testApiKeyPrefixSelectsTheHost(): void {
    self::assertTrue(Pairing::is_api_key('arc_live_public_secret'));
    self::assertSame('production', Pairing::env_for_key('arc_live_public_secret'));
    self::assertSame('preview', Pairing::env_for_key('arc_test_public_secret'));
    self::assertFalse(Pairing::is_api_key('secret'));
  }

  public function testTokenSiteIsNotTheTokenId(): void {
    $parsed = Pairing::token([
      'access_token' => 'arc_at_example',
      'expires_in' => 3600,
      'site' => [
        'id' => 'site_abc',
        'projectId' => 'client_abc',
        'name' => 'Example',
        'baseUrl' => 'https://example.com/',
      ],
    ]);
    self::assertSame('arc_at_example', $parsed['accessToken']);
    self::assertSame('site_abc', $parsed['site']['id']);
  }

  public function testCreateResponseWithoutNestedSite(): void {
    $site = Pairing::site([
      'siteId' => 'site_new',
      'projectId' => 'client_abc',
      'name' => 'Example',
      'baseUrl' => 'https://example.com/',
      'created' => TRUE,
    ]);
    self::assertSame('site_new', $site['id']);
  }

  public function testScoresFillEveryService(): void {
    $scores = Pairing::scores([
      'scores' => [
        [
          'service' => 'seo',
          'score' => 82,
          'scoreBand' => 'Good',
        ],
      ],
    ]);
    self::assertCount(6, $scores);
    $seo = $this->serviceRow($scores, 'seo');
    self::assertSame(82, $seo['score']);
    self::assertSame('Good', $seo['band']);
    self::assertSame(0, $seo['issueCount']);
    $brand = $this->serviceRow($scores, 'brand');
    self::assertNull($brand['score']);
    self::assertNull($brand['setup']);
  }

  public function testScoresKeepAnAnalyticsGoogleGap(): void {
    $scores = Pairing::scores([
      'analyticsSetup' => 'connect_google',
      'scores' => [],
    ]);
    $analytics = $this->serviceRow($scores, 'analytics');
    self::assertSame('connect_google', $analytics['setup']);
    self::assertNull($scores[1]['setup']);
  }

  public function testScoresKeepAnalyticsWhenItIsNotEnabled(): void {
    $scores = Pairing::scores([
      'analyticsSetup' => 'not_enabled',
      'scores' => [],
    ]);
    $analytics = $this->serviceRow($scores, 'analytics');
    self::assertSame('not_enabled', $analytics['setup']);
  }

  public function testLandingPathKeepsThePathAndDropsTraversal(): void {
    self::assertSame('/pricing/', Pairing::landing_path('https://shop.example/pricing/?utm=1'));
    self::assertSame('/pricing/', Pairing::landing_path('/pricing/'));
    self::assertSame('/', Pairing::landing_path(''));
    self::assertSame('/', Pairing::landing_path('/../admin'));
  }

  public function testSitePortalUrlUsesTheProjectSlugAndSiteId(): void {
    self::assertSame(
      'http://localhost:3001/app/projects/shop/sites/site_abc',
      Pairing::site_portal_url('http://localhost:3001/', 'shop', 'site_abc'),
    );
    self::assertSame(
      'http://localhost:3001',
      Pairing::site_portal_url('http://localhost:3001', '../shop', 'site_abc'),
    );
  }

  public function testReportDropsUnsafeImages(): void {
    $view = Pairing::report([
      'service' => 'seo',
      'runId' => 'run-1',
      'hero' => [
        'title' => 'SEO score',
        'score' => '72',
        'mood' => 'ok',
        'band' => 'Needs work<script>',
      ],
      'blocks' => [
        [
          'kind' => 'table',
          'id' => 'pages',
          'heading' => 'Page analysis',
          'columns' => [],
          'rows' => [
            [
              'id' => 'page-1',
              'cells' => [
                [
                  'text' => 'About',
                  'href' => 'https://example.com/about',
                  'image' => [
                    'src' => 'javascript:alert(1)',
                    'alt' => 'Bad',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
    self::assertSame('seo', $view['service']);
    self::assertSame('Needs work', $view['hero']['band']);
    self::assertNull($view['blocks'][0]['rows'][0]['cells'][0]['image']);
    self::assertSame([], $view['blocks'][0]['rows'][0]['targets']);
  }

  public function testReportKeepsStructuredRowsAndDropsUnsafeUrls(): void {
    $view = Pairing::report([
      'service' => 'seo',
      'blocks' => [
        [
          'kind' => 'table',
          'id' => 'pages',
          'heading' => 'Pages',
          'rows' => [
            [
              'cells' => [['text' => 'About']],
              'page' => [
                'title' => 'About<script>',
                'path' => '/about',
                'url' => 'javascript:alert(1)',
                'viewport' => 'desktop',
                'keyphrases' => ['widgets', '<b>sale</b>'],
                'keyphraseScore' => '70',
                'score' => '88',
                'siteName' => 'Example',
                'brokenLinkCount' => 0,
                'issueCount' => 1,
                'shot' => '../secret.png',
                'issues' => [
                  [
                    'key' => 'S-title',
                    'title' => 'Short title<script>',
                    'severity' => 'nope',
                    'dimension' => 'meta',
                    'summary' => 'Too short',
                    'fix' => 'Write more',
                  ],
                ],
              ],
              'social' => [
                'url' => 'https://user:secret@example.com/acme',
                'platform' => 'x',
              ],
              'targets' => ['surfaces::finding:login', 'nope'],
            ],
          ],
        ],
        [
          'kind' => 'card',
          'id' => 'short-mark',
          'heading' => 'Short mark',
          'mark' => [
            'present' => TRUE,
            'family' => 'F2',
            'sharedFamily' => TRUE,
            'role' => 'short',
          ],
        ],
      ],
      'issues' => [
        [
          'label' => 'Pages',
          'items' => [
            [
              'title' => 'Short title',
              'pages' => [
                ['url' => 'https://example.com/about', 'label' => 'About'],
                ['url' => 'javascript:alert(1)', 'label' => 'Bad'],
              ],
            ],
          ],
        ],
      ],
    ]);
    $page = $view['blocks'][0]['rows'][0]['page'];
    self::assertSame('About', $page['title']);
    self::assertSame('', $page['url']);
    self::assertSame(['widgets', 'sale'], $page['keyphrases']);
    self::assertArrayNotHasKey('shot', $page);
    self::assertSame('', $page['issues'][0]['severity']);
    self::assertArrayNotHasKey('social', $view['blocks'][0]['rows'][0]);
    self::assertSame(['surfaces::finding:login'], $view['blocks'][0]['rows'][0]['targets']);
    self::assertTrue($view['blocks'][1]['mark']['present']);
    self::assertSame('F2', $view['blocks'][1]['mark']['family']);
    self::assertSame(
      [['url' => 'https://example.com/about', 'label' => 'About']],
      $view['issues'][0]['items'][0]['pages'],
    );
  }

  /**
   * @param array<int, array<string, mixed>> $scores
   *
   * @return array<string, mixed>
   */
  private function serviceRow(array $scores, string $service): array {
    foreach ($scores as $row) {
      if ($row['service'] === $service) {
        return $row;
      }
    }
    self::fail('Missing ' . $service);
    return [];
  }

}
