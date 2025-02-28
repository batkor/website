<?php

declare(strict_types=1);

namespace Druki\Tests\Unit\EventSubscriber;

use Drupal\druki_content\Event\RequestSourceContentSyncEvent;
use Drupal\druki_content\Repository\ContentSourceSettingsInterface;
use Drupal\druki_redirect\EventSubscriber\SourceContentEventSubscriber;
use Drupal\druki_redirect\Queue\RedirectSyncQueueManagerInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Provides test for source content events subscriber.
 *
 * @coversDefaultClass \Drupal\druki_redirect\EventSubscriber\SourceContentEventSubscriber
 */
final class RedirectSourceContentEventSubscriberTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Store dirs requested to sync.
   */
  protected array $requestedDirs = [];

  /**
   * Tests that sync request works as expected.
   */
  public function testOnSyncRequest(): void {
    $event_subscriber = $this->buildEventSubscriber();

    $this->assertArrayHasKey(RequestSourceContentSyncEvent::class, SourceContentEventSubscriber::getSubscribedEvents());
    $event = new RequestSourceContentSyncEvent();
    $this->assertEmpty($this->requestedDirs);
    $event_subscriber->onSyncRequest($event);
    $expected = [
      '/foo/bar/docs',
    ];
    $this->assertEquals($expected, $this->requestedDirs);
  }

  /**
   * Builds event subscriber with mocked dependencies.
   *
   * @return \Drupal\druki_redirect\EventSubscriber\SourceContentEventSubscriber
   *   The subscriber instance.
   */
  protected function buildEventSubscriber(): SourceContentEventSubscriber {
    return new SourceContentEventSubscriber(
      $this->buildContentSourceSettings(),
      $this->buildRedirectSyncQueueManager(),
    );
  }

  /**
   * Builds content source settings mock.
   *
   * @return \Drupal\druki_content\Repository\ContentSourceSettingsInterface
   *   The mock instance.
   */
  protected function buildContentSourceSettings(): ContentSourceSettingsInterface {
    $source_settings = $this->prophesize(ContentSourceSettingsInterface::class);
    $source_settings->getRepositoryUri()->willReturn('/foo/bar');
    return $source_settings->reveal();
  }

  /**
   * Builds redirect sync queue manager mock.
   *
   * @return \Drupal\druki_redirect\Queue\RedirectSyncQueueManagerInterface
   *   The mock instance.
   */
  protected function buildRedirectSyncQueueManager(): RedirectSyncQueueManagerInterface {
    $this->requestedDirs = [];
    $self = $this;
    $queue_manager = $this->prophesize(RedirectSyncQueueManagerInterface::class);
    $queue_manager->buildFromDirectories(Argument::any())->will(function ($args) use ($self) {
      $self->requestedDirs = $args[0];
    });
    return $queue_manager->reveal();
  }

}
