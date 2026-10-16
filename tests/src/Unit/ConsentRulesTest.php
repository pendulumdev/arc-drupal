<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\Rules\ConsentCatalog;
use Drupal\pendulum_arc\Rules\ConsentList;
use Drupal\pendulum_arc\Rules\ConsentLogPayload;
use Drupal\pendulum_arc\Rules\ConsentScripts;
use Drupal\pendulum_arc\Rules\ConsentSettings;
use PHPUnit\Framework\TestCase;

/**
 * Cookie consent rules: settings, catalog, scripts, list filter, and log payload.
 *
 * @covers \Drupal\pendulum_arc\Rules\ConsentSettings
 * @covers \Drupal\pendulum_arc\Rules\ConsentCatalog
 * @covers \Drupal\pendulum_arc\Rules\ConsentScripts
 * @covers \Drupal\pendulum_arc\Rules\ConsentList
 * @covers \Drupal\pendulum_arc\Rules\ConsentLogPayload
 */
final class ConsentRulesTest extends TestCase {

  public function testDefaultsMatchTheBannerFactory(): void {
    $settings = ConsentSettings::defaults('body{}');
    self::assertSame(1, $settings['consent_version']);
    self::assertSame('Cookies on this site', $settings['title']);
    self::assertSame('modal', $settings['read_more']);
    self::assertTrue($settings['show_cookie_table']);
    self::assertFalse($settings['reload_accept']);
    self::assertFalse($settings['reload_decline']);
    self::assertSame('collapse', $settings['after_choice']);
    self::assertFalse($settings['logging_enabled']);
    self::assertFalse($settings['disable_identifiable']);
    self::assertFalse($settings['disabled']);
    self::assertSame('fixed', $settings['layout']);
    self::assertSame('footer', $settings['placement']);
    self::assertSame('bottom-left', $settings['fixed_position']);
    self::assertFalse($settings['disable_styles']);
    self::assertSame('Accept', $settings['accept_label']);
    self::assertSame('#1a9bb8', $settings['accept_colour']);
    self::assertSame('Decline', $settings['decline_label']);
    self::assertSame('#6b7280', $settings['decline_colour']);
    self::assertSame('Read more', $settings['read_more_label']);
    self::assertSame('#6b7280', $settings['read_more_colour']);
    self::assertSame('', $settings['reopen_icon_url']);
    self::assertSame('body{}', $settings['custom_css']);
    self::assertSame([], $settings['cookies']);
  }

  public function testSanitizeKeepsACookieAndDropsBadValues(): void {
    $clean = ConsentSettings::sanitize(
      [
        'title' => '  Hello <b>there</b>  ',
        'read_more' => 'popup',
        'disabled' => TRUE,
        'placement' => 'fixed',
        'fixed_position' => 'nope',
        'accept_colour' => '#ABC',
        'decline_colour' => 'red',
        'read_more_url' => 'javascript:alert(1)',
        'reopen_icon_url' => 'https://cdn.example/icon.png',
        'custom_css' => "a{}\0b{}",
        'cookies' => [
          [
            'id' => 'cat_ga',
            'catalog_id' => 'ga',
            'name' => ' _ga ',
            'label' => '',
            'description' => 'Visitor',
            'requirement' => 'optional',
            'type' => 'Analytics',
            'scripts' => " google_tag/gtag \n\n https://example.com/gtag.js ",
            'match' => 'prefix',
          ],
          [
            'name' => '',
          ],
          'not-a-row',
        ],
      ],
      'default'
    );

    self::assertSame('Hello there', $clean['title']);
    self::assertSame('modal', $clean['read_more']);
    self::assertTrue($clean['disabled']);
    self::assertSame('footer', $clean['placement']);
    self::assertSame('bottom-left', $clean['fixed_position']);
    self::assertSame('#abc', $clean['accept_colour']);
    self::assertSame('#6b7280', $clean['decline_colour']);
    self::assertSame('', $clean['read_more_url']);
    self::assertSame('https://cdn.example/icon.png', $clean['reopen_icon_url']);
    self::assertSame('a{}b{}', $clean['custom_css']);
    self::assertCount(1, $clean['cookies']);
    self::assertSame('cat_ga', $clean['cookies'][0]['id']);
    self::assertSame('_ga', $clean['cookies'][0]['name']);
    self::assertSame('_ga', $clean['cookies'][0]['label']);
    self::assertSame("google_tag/gtag\nhttps://example.com/gtag.js", $clean['cookies'][0]['scripts']);
    self::assertSame('prefix', $clean['cookies'][0]['match']);
  }

  public function testSavedRemFontSizesBecomePixels(): void {
    $clean = ConsentSettings::sanitize(
      [
        'custom_css' => ".arc-cookie-root {\n\tfont-size: 0.875rem;\n}\n.arc-cookie__title {\n\tfont-size: 1rem;\n}\n.arc-cookie__table {\n\tfont-size: 0.8125rem;\n}\n.arc-cookie__cookie {\n\tfont-size: 0.75rem;\n}\n",
      ],
      ''
    );

    self::assertStringContainsString('font-size: 16px;', $clean['custom_css']);
    self::assertStringContainsString('font-size: 18px;', $clean['custom_css']);
    self::assertStringContainsString('font-size: 14px;', $clean['custom_css']);
    self::assertStringContainsString('font-size: 13px;', $clean['custom_css']);
    self::assertStringNotContainsString('rem;', $clean['custom_css']);
    self::assertStringNotContainsString('arc-cookie-layout-lock', $clean['custom_css']);
  }

  public function testSavedRemLayoutGainsPixelLock(): void {
    $clean = ConsentSettings::sanitize(
      [
        'custom_css' => ".arc-cookie { max-width: 32rem; }\n.arc-cookie__dialog { max-width: 40rem; }\n",
      ],
      ''
    );

    self::assertStringContainsString('arc-cookie-layout-lock', $clean['custom_css']);
    self::assertStringContainsString('max-width: 512px', $clean['custom_css']);
    self::assertStringContainsString('max-width: 640px', $clean['custom_css']);
    self::assertStringContainsString('table-layout: auto', $clean['custom_css']);

    $again = ConsentSettings::sanitize(
      [
        'custom_css' => $clean['custom_css'],
      ],
      ''
    );
    self::assertSame(1, substr_count($again['custom_css'], 'arc-cookie-layout-lock'));
  }

  public function testSavedChoiceControlGainsPixelSize(): void {
    $clean = ConsentSettings::sanitize(
      [
        'custom_css' => ".arc-cookie__choice {\n\twidth: 1rem;\n\theight: 1rem;\n}\n/* arc-cookie-layout-lock */\n",
      ],
      ''
    );

    self::assertStringContainsString('arc-cookie-choice-size', $clean['custom_css']);
    self::assertStringContainsString('width: 20px', $clean['custom_css']);
    self::assertStringContainsString('min-width: 20px', $clean['custom_css']);

    $again = ConsentSettings::sanitize(
      [
        'custom_css' => $clean['custom_css'],
      ],
      ''
    );
    self::assertSame(1, substr_count($again['custom_css'], 'arc-cookie-choice-size'));
  }

  public function testBannerClassFollowsLayout(): void {
    $fixed = ConsentSettings::defaults('');
    self::assertSame(
      'arc-cookie arc-cookie--fixed arc-cookie--pos-bottom-left',
      ConsentSettings::banner_class($fixed)
    );
    self::assertSame(
      'arc-cookie__reopen arc-cookie--pos-bottom-left',
      ConsentSettings::reopen_class($fixed)
    );

    $static = $fixed;
    $static['layout'] = 'static';
    $static['placement'] = 'header';
    self::assertSame(
      'arc-cookie arc-cookie--header arc-cookie--static',
      ConsentSettings::banner_class($static)
    );
    self::assertSame(
      'arc-cookie__reopen arc-cookie--header',
      ConsentSettings::reopen_class($static)
    );

    $footer = $static;
    $footer['placement'] = 'fixed';
    self::assertSame(
      'arc-cookie arc-cookie--footer arc-cookie--static',
      ConsentSettings::banner_class($footer)
    );
  }

  public function testPrintSlotFollowsLayout(): void {
    $settings = ConsentSettings::defaults('');
    self::assertSame('fixed', ConsentSettings::print_slot($settings));
    self::assertSame([], ConsentSettings::region_candidates($settings));

    $settings['layout'] = 'static';
    $settings['placement'] = 'header';
    self::assertSame('header', ConsentSettings::print_slot($settings));
    self::assertSame(['header', 'header_top', 'navbar'], ConsentSettings::region_candidates($settings));

    $settings['placement'] = 'footer';
    self::assertSame('footer', ConsentSettings::print_slot($settings));
    self::assertSame(['footer', 'footer_bottom', 'footer_top'], ConsentSettings::region_candidates($settings));

    $settings['placement'] = 'fixed';
    self::assertSame('footer', ConsentSettings::print_slot($settings));
  }

  public function testASitePathIsKeptAsTheReadMoreLink(): void {
    $clean = ConsentSettings::sanitize(
      ['read_more_url' => '/privacy'],
      ''
    );
    self::assertSame('/privacy', $clean['read_more_url']);
  }

  public function testRenewBumpsTheVersionAndKeepsTheTitle(): void {
    $settings = ConsentSettings::defaults('');
    $settings['title'] = 'Custom';
    $renewed = ConsentSettings::renew($settings);
    self::assertSame(2, $renewed['consent_version']);
    self::assertSame('Custom', $renewed['title']);
  }

  public function testResetRestoresDefaultsAndBumpsTheVersion(): void {
    $settings = ConsentSettings::defaults('old');
    $settings['title'] = 'Custom';
    $settings['cookies'] = [
      [
        'id' => 'cat_ga',
        'catalog_id' => 'ga',
        'name' => '_ga',
      ],
    ];
    $settings['dismissed_catalog_ids'] = ['gid'];
    $settings['custom_css'] = 'changed';
    $reset = ConsentSettings::reset($settings, 'fresh');
    self::assertSame(2, $reset['consent_version']);
    self::assertSame('Cookies on this site', $reset['title']);
    self::assertSame([], $reset['cookies']);
    self::assertSame([], $reset['dismissed_catalog_ids']);
    self::assertSame('fresh', $reset['custom_css']);
  }

  public function testRemovingACatalogRowDismissesItUntilItReturns(): void {
    $previous = [
      ['catalog_id' => 'ga'],
      ['catalog_id' => 'gid'],
    ];
    $next = [
      ['catalog_id' => 'gid'],
    ];
    $dismissed = ConsentSettings::merge_dismissed($previous, $next, ['gat']);
    self::assertSame(['gat', 'ga'], $dismissed);

    $back = ConsentSettings::merge_dismissed(
      $previous,
      [
        ['catalog_id' => 'ga'],
        ['catalog_id' => 'gid'],
      ],
      $dismissed
    );
    self::assertSame(['gat'], $back);
  }

  public function testCatalogMatchesKnownCookiesWithoutSwallowingNeighbours(): void {
    self::assertSame(
      ['drupal_session_secure'],
      ConsentCatalog::matching_ids(['SSESS' . hash('sha256', 'example')], [])
    );
    self::assertSame(
      ['drupal_session'],
      ConsentCatalog::matching_ids(['SESS' . 'example'], [])
    );
    self::assertSame(
      ['ga'],
      ConsentCatalog::matching_ids(['_ga'], [])
    );
    self::assertSame(
      ['ga_container'],
      ConsentCatalog::matching_ids(['_ga_ABC123'], [])
    );
    self::assertSame(
      ['gat'],
      ConsentCatalog::matching_ids(['_gat_UA_1'], [])
    );
    self::assertSame(
      ['ga', 'ga_container', 'gid', 'gat'],
      ConsentCatalog::matching_ids([], ['google_tag/gtag'])
    );
    self::assertSame(
      [],
      ConsentCatalog::matching_ids(['unknown_cookie'], ['olivero/global'])
    );
  }

  public function testALibraryIdIsNotTreatedAsAUrl(): void {
    self::assertTrue(ConsentScripts::is_library_id('google_tag/gtag'));
    self::assertFalse(ConsentScripts::is_library_id('https://www.googletagmanager.com/gtag/js'));
    self::assertFalse(ConsentScripts::is_library_id('/core/misc/drupal.js'));
    self::assertFalse(ConsentScripts::is_library_id('gtag.js'));
  }

  public function testCatalogMergeAddsOnceSkipsDismissedAndKeepsEdits(): void {
    $existing = [
      [
        'id' => 'cat_ga',
        'catalog_id' => 'ga',
        'name' => '_ga',
        'label' => 'Custom',
        'description' => 'Edited',
        'requirement' => 'optional',
        'type' => 'Analytics',
        'scripts' => 'google_tag/gtag',
        'match' => 'prefix',
      ],
    ];
    $first = ConsentCatalog::merge($existing, [], ['_ga', '_gid'], []);
    self::assertTrue($first['changed']);
    self::assertSame('Custom', $first['rows'][0]['label']);
    self::assertSame('google_tag/gtag', $first['rows'][0]['scripts']);
    self::assertSame('gid', $first['rows'][1]['catalog_id']);

    $again = ConsentCatalog::merge($first['rows'], [], ['_ga', '_gid'], []);
    self::assertFalse($again['changed']);
    self::assertCount(2, $again['rows']);

    $dismissed = ConsentCatalog::merge([], ['ga'], ['_ga'], []);
    self::assertFalse($dismissed['changed']);
    self::assertSame([], $dismissed['rows']);
  }

  public function testScriptTagsBlockOnlyOptionalRowsThatAreNotAllowed(): void {
    $cookies = [
      [
        'id' => 'cat_login',
        'requirement' => 'required',
        'scripts' => "core/drupal\n",
      ],
      [
        'id' => 'cat_ga',
        'requirement' => 'optional',
        'scripts' => "google_tag/gtag\nhttps://www.googletagmanager.com/gtag/js",
      ],
    ];
    $tag = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>';
    $blocked = ConsentScripts::rewrite_tag($tag, 'other', 'https://www.googletagmanager.com/gtag/js?id=G-1', $cookies, []);
    self::assertStringContainsString('type="text/plain"', $blocked);
    self::assertStringContainsString('data-arc-cookie="cat_ga"', $blocked);
    self::assertStringContainsString('data-arc-script-type="text/javascript"', $blocked);

    $allowed = ConsentScripts::rewrite_tag($tag, 'other', 'https://www.googletagmanager.com/gtag/js?id=G-1', $cookies, ['cat_ga']);
    self::assertSame($tag, $allowed);

    $core = '<script src="/core/misc/drupal.js"></script>';
    self::assertSame(
      $core,
      ConsentScripts::rewrite_tag($core, 'core/drupal', '/core/misc/drupal.js', $cookies, [])
    );

    $theme = '<script src="/theme.js"></script>';
    self::assertSame(
      $theme,
      ConsentScripts::rewrite_tag($theme, 'olivero/global', '/theme.js', $cookies, [])
    );

    $library = '<script src="/modules/google_tag/js/gtag.js"></script>';
    self::assertStringContainsString(
      'data-arc-cookie="cat_ga"',
      ConsentScripts::rewrite_tag($library, 'google_tag/gtag', '/modules/google_tag/js/gtag.js', $cookies, [])
    );
  }

  public function testARequiredMatchWinsOverAnOptionalMatch(): void {
    $cookies = [
      [
        'id' => 'cat_ga',
        'requirement' => 'optional',
        'scripts' => 'google_tag/gtag',
      ],
      [
        'id' => 'cat_login',
        'requirement' => 'required',
        'scripts' => 'google_tag/gtag',
      ],
    ];
    $tag = '<script src="/gtag.js"></script>';
    self::assertSame(
      $tag,
      ConsentScripts::rewrite_tag($tag, 'google_tag/gtag', '/gtag.js', $cookies, [])
    );
  }

  public function testTheCookieListFiltersByOptionalAndType(): void {
    $rows = [
      [
        'requirement' => 'optional',
        'type' => 'Analytics',
        'name' => '_ga',
      ],
      [
        'requirement' => 'required',
        'type' => 'Necessary',
        'name' => 'SSESS',
      ],
      [
        'requirement' => 'optional',
        'type' => 'Comments',
        'name' => 'comment',
      ],
    ];
    self::assertCount(2, ConsentList::filter($rows, 'yes', ''));
    self::assertCount(1, ConsentList::filter($rows, 'no', ''));
    self::assertSame('_ga', ConsentList::filter($rows, '', 'analytics')[0]['name']);
    self::assertSame('comment', ConsentList::filter($rows, 'yes', 'comments')[0]['name']);
    self::assertCount(3, ConsentList::filter($rows, '', ''));
  }

  public function testTheLogDropsIdentifiableFieldsAndRejectsUnknownIds(): void {
    $rows = [
      [
        'id' => 'cat_login',
        'name' => 'SSESS',
        'requirement' => 'required',
      ],
      [
        'id' => 'cat_ga',
        'name' => '_ga',
        'requirement' => 'optional',
      ],
    ];

    $hidden = ConsentLogPayload::build(TRUE, TRUE, 'accept', ['cat_ga'], $rows, '203.0.113.5', 'Agent');
    self::assertNotNull($hidden);
    self::assertSame('', $hidden['ip']);
    self::assertSame('', $hidden['user_agent']);
    self::assertSame(['SSESS', '_ga'], $hidden['allowed']);
    self::assertSame([], $hidden['refused']);

    $decline = ConsentLogPayload::build(TRUE, FALSE, 'decline', ['cat_ga'], $rows, '203.0.113.5', 'Agent');
    self::assertNotNull($decline);
    self::assertSame('203.0.113.5', $decline['ip']);
    self::assertSame('Agent', $decline['user_agent']);
    self::assertSame(['SSESS'], $decline['allowed']);
    self::assertSame(['_ga'], $decline['refused']);

    self::assertNull(ConsentLogPayload::build(FALSE, FALSE, 'accept', [], $rows, '203.0.113.5', 'Agent'));
    self::assertNull(ConsentLogPayload::build(TRUE, FALSE, 'accept', ['missing'], $rows, '', ''));
    self::assertNull(ConsentLogPayload::build(TRUE, FALSE, 'nope', [], $rows, '', ''));
  }

}
