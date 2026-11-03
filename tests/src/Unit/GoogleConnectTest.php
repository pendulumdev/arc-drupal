<?php

declare(strict_types=1);

namespace Drupal\Tests\pendulum_arc\Unit;

use Drupal\pendulum_arc\Rules\GoogleConnect;
use PHPUnit\Framework\TestCase;

/**
 * Google handoff ticket path and where the current user goes next.
 */
final class GoogleConnectTest extends TestCase {

  public function testTicketPath(): void {
    self::assertSame(
      '/connect/google?ticket=abcdefghijklmnop',
      GoogleConnect::ticketPath('https://arc.example/connect/google?ticket=abcdefghijklmnop'),
    );
    self::assertSame('', GoogleConnect::ticketPath('https://arc.example/connect/google?ticket=short'));
  }

  public function testDestination(): void {
    self::assertSame('email', GoogleConnect::destination('pending', 'absent'));
    self::assertSame('url', GoogleConnect::destination('none', 'active'));
    self::assertSame('url', GoogleConnect::destination('member', 'active'));
    self::assertSame('invite', GoogleConnect::destination('none', 'absent'));
    self::assertSame('invalid', GoogleConnect::destination('other', 'other'));
  }

  public function testInviteRow(): void {
    $row = GoogleConnect::inviteRow([
      'results' => [
        ['email' => 'Ada@Example.com', 'status' => 'none', 'account' => 'absent'],
      ],
    ], 'ada@example.com');
    self::assertSame('none', $row['status']);
    self::assertNull(GoogleConnect::inviteRow(['results' => []], 'ada@example.com'));
  }

}
