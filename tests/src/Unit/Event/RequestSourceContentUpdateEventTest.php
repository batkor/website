<?php

declare(strict_types=1);

namespace Druki\Tests\Unit\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\druki_content\Event\RequestSourceContentUpdateEvent;
use Drupal\Tests\UnitTestCase;

/**
 * Provides test for request source content update event.
 *
 * @coversDefaultClass \Drupal\druki_content\Event\RequestSourceContentUpdateEvent
 */
final class RequestSourceContentUpdateEventTest extends UnitTestCase {

  /**
   * Tests that event object works as expected.
   */
  public function testObject(): void {
    $event = new RequestSourceContentUpdateEvent();
    // This is very basic event without any behaviors. We just expect that it
    // extends Drupal's Event class.
    $this->assertInstanceOf(Event::class, $event);
  }

}
