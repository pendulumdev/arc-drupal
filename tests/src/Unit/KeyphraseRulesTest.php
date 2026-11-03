<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\Rules\KeyphraseCollect;
use Drupal\pendulum_arc\Rules\KeyphraseCursor;
use Drupal\pendulum_arc\Rules\KeyphraseHash;
use Drupal\pendulum_arc\Rules\KeyphrasePhrase;
use Drupal\pendulum_arc\Rules\KeyphrasePush;
use PHPUnit\Framework\TestCase;

/**
 * Keyphrase phrase, collect, hash, push, and scan cursor rules.
 */
final class KeyphraseRulesTest extends TestCase {

  public function testFirstNonEmptyAndCap(): void {
    self::assertSame('', KeyphrasePhrase::first(['', '   ']));
    self::assertSame('widgets', KeyphrasePhrase::first(['', 'widgets', 'ignored']));
    self::assertSame(
      'one, two, three, four, five',
      KeyphrasePhrase::list('one, two, three, four, five, six'),
    );
    self::assertSame('Widgets', KeyphrasePhrase::list('Widgets, widgets'));
    self::assertSame('', KeyphrasePhrase::list('Widgets, widgets, ---'));
    self::assertSame('well-known', KeyphrasePhrase::list("well\u{2014}known"));
    self::assertSame(
      'Saint Barth\'s "villa"',
      KeyphrasePhrase::list("Saint Barth\u{2019}s \u{201C}villa\u{201D}\u{200B}"),
    );
    self::assertSame('', KeyphrasePhrase::list(str_repeat('a', 81)));
    self::assertSame('', KeyphrasePhrase::list("hello\u{FF0C}world"));
  }

  public function testHashSkipsPhraseArcDrops(): void {
    $raw = "Saint Barth\u{2019}s \u{201C}villa\u{201D}\u{200B}";
    $hash = KeyphraseHash::of($raw, [
      ['path' => '/villa', 'phrase' => $raw],
      ['path' => '/long', 'phrase' => str_repeat('a', 81)],
    ]);
    $text = "focus\tSaint Barth's \"villa\"\n/villa\tSaint Barth's \"villa\"\n";
    self::assertSame(hash('sha256', $text), $hash);
  }

  public function testCollectFrontPageAndSkip(): void {
    $origin = 'https://example.com';
    self::assertSame('focus', KeyphraseCollect::row('https://example.com/', $origin, TRUE, ['widgets'])['role']);
    self::assertSame('/', KeyphraseCollect::row('https://example.com/node/1', $origin, TRUE, ['widgets'])['path']);
    self::assertSame('clear-focus', KeyphraseCollect::row('https://example.com/', $origin, TRUE, [''])['role']);
    $page = KeyphraseCollect::row('https://example.com/about', $origin, FALSE, ['about widgets']);
    self::assertSame('page', $page['role']);
    self::assertSame('/about', $page['path']);
    self::assertSame('clear', KeyphraseCollect::row('https://example.com/about', $origin, FALSE, [''])['role']);
    self::assertSame('skip', KeyphraseCollect::row('https://example.com/about?q=1', $origin, FALSE, ['about'])['role']);
    self::assertSame('skip', KeyphraseCollect::row('https://other.example/about', $origin, FALSE, ['about'])['role']);
  }

  public function testHashFixture(): void {
    self::assertSame(KeyphraseHash::FIXTURE, KeyphraseHash::of('widgets', [
      ['path' => '/about', 'phrase' => 'about widgets'],
    ]));
  }

  public function testPushPlan(): void {
    $rows = [
      [
        'path' => '/about',
        'phrase' => 'about widgets',
        'synced_phrase' => 'old',
        'deleted' => FALSE,
      ],
      [
        'path' => '/gone',
        'phrase' => '',
        'synced_phrase' => 'gone',
        'deleted' => TRUE,
      ],
    ];
    $patch = KeyphrasePush::plan('same', 'same', 'widgets', 'widgets', $rows);
    self::assertSame('patch', $patch['mode']);
    self::assertSame(['/gone'], $patch['deletes']);
    self::assertSame('/about', $patch['upserts'][0]['path']);
    self::assertFalse($patch['send_focus']);

    $replace = KeyphrasePush::plan('old', 'moved', 'widgets', 'widgets', $rows);
    self::assertSame('replace', $replace['mode']);
    self::assertSame([], $replace['deletes']);
    self::assertCount(1, $replace['upserts']);
    self::assertTrue($replace['send_focus']);
    self::assertSame('widgets', $replace['focus']);
    self::assertFalse(KeyphrasePush::vacant($replace));
  }

  public function testEmptyReplaceIsVacant(): void {
    $plan = KeyphrasePush::plan('old', 'moved', '', '', []);
    self::assertSame('replace', $plan['mode']);
    self::assertSame([], $plan['upserts']);
    self::assertNull($plan['focus']);
    self::assertTrue(KeyphrasePush::vacant($plan));

    $pages = KeyphrasePush::plan('old', 'moved', '', '', [
      [
        'path' => '/about',
        'phrase' => 'about',
        'synced_phrase' => '',
        'deleted' => FALSE,
      ],
    ]);
    self::assertFalse(KeyphrasePush::vacant($pages));
    self::assertCount(1, $pages['upserts']);
  }

  public function testCursorAdvancesByEntityType(): void {
    $full = KeyphraseCursor::advance(0, '0', 200, 200, '200', 2);
    self::assertFalse($full['done']);
    self::assertSame(0, $full['index']);
    self::assertSame('200', $full['after']);

    $next = KeyphraseCursor::advance(0, '200', 3, 200, '203', 2);
    self::assertFalse($next['done']);
    self::assertSame(1, $next['index']);
    self::assertSame('0', $next['after']);

    $done = KeyphraseCursor::advance(1, '0', 1, 200, '9', 2);
    self::assertTrue($done['done']);
    self::assertTrue(KeyphraseCursor::advance(0, '0', 0, 200, '0', 0)['done']);
  }

}
