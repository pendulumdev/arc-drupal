<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\Rules\InviteRules;
use PHPUnit\Framework\TestCase;

/**
 * Invite checkbox, search, and the current page of ids.
 */
final class InviteRulesTest extends TestCase {

  public function testCheckbox(): void {
    self::assertTrue(InviteRules::checkbox('', '', '')['disabled']);
    self::assertSame('On this project', InviteRules::checkbox('a@example.com', 'member', 'active')['label']);
    self::assertSame('Invite already sent', InviteRules::checkbox('a@example.com', 'pending', 'absent')['label']);
    $absent = InviteRules::checkbox('a@example.com', 'none', 'absent');
    self::assertTrue($absent['checked']);
    self::assertFalse($absent['disabled']);
    $active = InviteRules::checkbox('a@example.com', 'none', 'active');
    self::assertTrue($active['checked']);
    self::assertSame('Has an Arc account', $active['label']);
    self::assertTrue(InviteRules::checkbox('a@example.com', '', '')['disabled']);
  }

  public function testPageSend(): void {
    $parsed = InviteRules::userIds(['4', 4, '0', 'nope', 9]);
    self::assertTrue($parsed['ok']);
    self::assertSame([4, 9], $parsed['ids']);
    $empty = InviteRules::userIds([]);
    self::assertFalse($empty['ok']);
    self::assertSame('Choose at least one administrator.', $empty['message']);

    $tooMany = InviteRules::userIds(range(1, InviteRules::PAGE_SIZE + 1));
    self::assertFalse($tooMany['ok']);
    self::assertSame('Invite one page of administrators at a time.', $tooMany['message']);

    self::assertSame('sent', InviteRules::listResult(['invited' => TRUE])['outcome']);
    self::assertSame('refused', InviteRules::listResult(['code' => 'PENDING_INVITE_CAP'])['outcome']);
    self::assertStringContainsString('Too many open invites', InviteRules::listResult(['code' => 'PENDING_INVITE_CAP'])['message']);
  }

  public function testSearchStripsAsterisks(): void {
    self::assertSame('ada', InviteRules::search(' *ada* '));
  }

}
