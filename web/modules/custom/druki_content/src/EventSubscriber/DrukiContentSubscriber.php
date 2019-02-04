<?php

namespace Drupal\druki_content\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\druki_git\Event\DrukiGitEvent;
use Drupal\druki_git\Event\DrukiGitEvents;
use Drupal\druki_parser\Service\DrukiFolderParserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class DrukiContentSubscriber
 *
 * @package Drupal\druki_content\EventSubscriber
 */
class DrukiContentSubscriber implements EventSubscriberInterface {

  /**
   * The queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The folder parser.
   *
   * @var \Drupal\druki_parser\Service\DrukiFolderParserInterface
   */
  protected $folderParser;

  public function __construct(QueueFactory $queue, DrukiFolderParserInterface $folder_parser) {
    $this->queue = $queue->get('druki_content_updater');
    $this->folderParser = $folder_parser;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      DrukiGitEvents::FINISH_PULL => ['onPullFinish'],
    ];
  }

  /**
   * Reacts on successful pull
   *
   * @param \Drupal\druki_git\Event\DrukiGitEvent $event
   */
  public function onPullFinish(DrukiGitEvent $event) {
    $files = $this->folderParser->parse($event->git()->getRepositoryPath());
    /** @var \Symfony\Component\Finder\SplFileInfo[] $items */
    foreach ($files as $langcode => $items) {
      foreach ($items as $item) {
        $last_commit_hash = $event->git()->getFileLastCommitId($item->getRelativePath());

        if ($last_commit_hash) {
          $this->queue->createItem([
            'langcode' => $langcode,
            'path' => $item->getPathname(),
            'relative_path' => $item->getRelativePath(),
            'filename' => $item->getFilename(),
            'last_commit_hash' => $last_commit_hash,
          ]);
        }
      }
    }
  }

}
