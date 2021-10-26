<?php

namespace Drupal\druki_content\Queue;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\druki_content\Data\ContentSourceFileList;
use Drupal\druki_content\Data\ContentSourceFileListQueueItem;
use Drupal\druki_content\Data\ContentSyncCleanQueueItem;
use Drupal\druki_content\Data\RedirectSourceFileList;
use Drupal\druki_content\Data\RedirectSourceFileListQueueItem;
use Drupal\druki_content\Finder\ContentSourceFileFinder;
use Drupal\druki_content\Finder\RedirectSourceFileFinder;

/**
 * Provides queue manager for synchronization content.
 */
final class ContentSyncQueueManager {

  /**
   * The queue name used for synchronisation.
   */
  public const QUEUE_NAME = 'druki_content_sync';

  /**
   * The content source file finder.
   */
  protected ContentSourceFileFinder $contentSourceFileFinder;

  /**
   * The queue with synchronization items.
   */
  protected QueueInterface $queue;

  /**
   * The state storage.
   */
  protected StateInterface $state;

  /**
   * The system time.
   */
  protected TimeInterface $time;

  /**
   * The queue worker.
   */
  protected QueueWorkerManagerInterface $queueWorker;

  /**
   * The redirect source file finder.
   */
  protected RedirectSourceFileFinder $redirectSourceFileFinder;

  /**
   * Constructs a new SynchronizationQueueBuilder object.
   *
   * @param \Drupal\druki_content\Finder\ContentSourceFileFinder $content_source_file_finder
   *   The source content finder.
   * @param \Drupal\druki_content\Finder\RedirectSourceFileFinder $redirect_source_file_finder
   *   The redirect finder.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state storage.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The system time.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_worker
   *   The queue manager.
   */
  public function __construct(ContentSourceFileFinder $content_source_file_finder, RedirectSourceFileFinder $redirect_source_file_finder, QueueFactory $queue_factory, StateInterface $state, TimeInterface $time, QueueWorkerManagerInterface $queue_worker) {
    $this->contentSourceFileFinder = $content_source_file_finder;
    $this->redirectSourceFileFinder = $redirect_source_file_finder;
    $this->queue = $queue_factory->get(self::QUEUE_NAME);
    $this->state = $state;
    $this->time = $time;
    $this->queueWorker = $queue_worker;
  }

  /**
   * Builds new queue from source directory.
   *
   * The previous queue will be cleared to ensure items will not duplicate each
   * over. It can happens when multiple builds was called during short period of
   * time.
   *
   * @param string $directory
   *   The with source content. This directory will be parsed on call.
   */
  public function buildFromPath(string $directory): void {
    $this->clear();
    $content_source_file_list = $this->contentSourceFileFinder->findAll($directory);
    if ($content_source_file_list->getIterator()->count()) {
      $this->addContentSourceFileList($content_source_file_list);
    }
    $redirect_file_list = $this->redirectSourceFileFinder->findAll($directory);
    if ($redirect_file_list->getIterator()->count()) {
      $this->addRedirectSourceFileList($redirect_file_list);
    }
    $this->addCleanOperation();
  }

  /**
   * Clears queue manually.
   */
  public function clear(): void {
    $this->queue->deleteQueue();
  }

  /**
   * Builds new queue from source content list.
   *
   * @param \Drupal\druki_content\Data\ContentSourceFileList $content_source_file_list
   *   The content source file list.
   */
  protected function addContentSourceFileList(ContentSourceFileList $content_source_file_list): void {
    $items_per_queue = Settings::get('entity_update_batch_size', 50);
    $source_files_array = $content_source_file_list->getIterator()->getArrayCopy();
    $chunks = \array_chunk($source_files_array, $items_per_queue);
    foreach ($chunks as $chunk) {
      $content_source_file_list = new ContentSourceFileList();
      /** @var \Drupal\druki_content\Data\ContentSourceFile $content_source_file */
      foreach ($chunk as $content_source_file) {
        $content_source_file_list->addFile($content_source_file);
      }
      $queue_item = new ContentSourceFileListQueueItem($content_source_file_list);
      $this->queue->createItem($queue_item);
    }
  }

  /**
   * Adds redirect files into queue.
   *
   * @param \Drupal\druki_content\Data\RedirectSourceFileList $redirect_source_file_list
   *   The redirect source file list.
   */
  protected function addRedirectSourceFileList(RedirectSourceFileList $redirect_source_file_list): void {
    $items_per_queue = Settings::get('entity_update_batch_size', 50);
    $redirect_files_array = $redirect_source_file_list->getIterator()->getArrayCopy();
    $chunks = \array_chunk($redirect_files_array, $items_per_queue);
    foreach ($chunks as $chunk) {
      $redirect_source_file_list = new RedirectSourceFileList();
      foreach ($chunk as $redirect_source_file) {
        $redirect_source_file_list->addFile($redirect_source_file);
      }
      $queue_item = new RedirectSourceFileListQueueItem($redirect_source_file_list);
      $this->queue->createItem($queue_item);
    }
  }

  /**
   * Adds clean operation into queue.
   */
  protected function addCleanOperation(): void {
    $sync_timestamp = $this->time->getRequestTime();
    $this->queue->createItem(new ContentSyncCleanQueueItem($sync_timestamp));
    $this->state->set('druki_content.last_sync_timestamp', $this->time->getRequestTime());
  }

  /**
   * Runs queue manually.
   *
   * @param int $time_limit
   *   The amount of seconds for this job.
   *
   * @return int
   *   The count of processed queue items.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function run(int $time_limit = 15): int {
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $queue_worker */
    $queue_worker = $this->queueWorker->createInstance('druki_content_sync');

    // Make sure queue exists. There is no harm in trying to recreate an
    // existing queue.
    $this->queue->createQueue();
    $end = \time() + $time_limit;
    $lease_time = $time_limit;
    $count = 0;
    while (\time() < $end && ($item = $this->queue->claimItem($lease_time))) {
      try {
        $queue_worker->processItem($item->data);
        $this->queue->deleteItem($item);
        $count++;
      }
      catch (RequeueException $e) {
        // The worker requested the task be immediately requeued.
        $this->queue->releaseItem($item);
      }
      catch (SuspendQueueException $e) {
        // If the worker indicates there is a problem with the whole queue,
        // release the item and skip to the next queue.
        $this->queue->releaseItem($item);
      }
      catch (\Exception $e) {
        // In case of any other kind of exception, log this information and
        // delete that item from queue so it wont block processing other items.
        // Maybe this should somehow notify about problem.
        $this->queue->deleteItem($item);
        $count++;
        \watchdog_exception('druki_content', $e);
      }
    }

    return $count;
  }

}
