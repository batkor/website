<?php

namespace Drupal\druki_content\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for a druki content entity type.
 */
class DrukiContentSettingsForm extends FormBase {

  /**
   * The queue of processing content.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The queue worker.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorkerManager;

  /**
   * The state storage.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * DrukiContentSettingsForm constructor.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue of processing content.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_worker_manager
   *   The queue worker manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state storage.
   */
  public function __construct(QueueInterface $queue, QueueWorkerManagerInterface $queue_worker_manager, StateInterface $state) {
    $this->queue = $queue;
    $this->queueWorkerManager = $queue_worker_manager;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): object {
    return new static(
      $container->get('queue')->get('druki_content_updater'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'druki_content_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['update_queue'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Update queue'),
    ];

    $form['update_queue']['total'] = [
      '#markup' => '<p>' . $this->t('Current queue items: @count', ['@count' => $this->queue->numberOfItems()]) . '</p>',
    ];

    $form['update_queue']['force'] = [
      '#type' => 'checkbox',
      '#title' => t('Force content processing'),
      '#description' => t('Content will be processed even if it not updated from last queue.'),
      '#default_value' => $this->state->get('druki_content.settings.force_update', FALSE),
    ];

    $form['update_queue']['actions'] = ['#type' => 'actions'];
    $form['update_queue']['actions']['save'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Save settings'),
    ];

    $form['update_queue']['actions']['run'] = [
      '#type' => 'submit',
      '#button_type' => 'secondary',
      '#value' => $this->t('Run queue'),
      '#submit' => [[$this, 'runQueue']],
    ];

    $form['update_queue']['actions']['clear'] = [
      '#type' => 'submit',
      '#button_type' => 'danger',
      '#value' => $this->t('Clear queue'),
      '#submit' => [[$this, 'clearQueue']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->state->set('druki_content.settings.force_update', $form_state->getValue('force'));
  }

  /**
   * Runs queue manually.
   */
  public function runQueue(array &$form, FormStateInterface $form_state) {
    $queue_worker_definition = $this->queueWorkerManager->getDefinition('druki_content_updater');
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $queue_worker */
    $queue_worker = $this->queueWorkerManager->createInstance('druki_content_updater');

    if (isset($queue_worker_definition['cron'])) {
      // Make sure every queue exists. There is no harm in trying to recreate
      // an existing queue.
      $this->queue->createQueue();

      $end = time() + (isset($info['cron']['time']) ? $queue_worker_definition['cron']['time'] : 15);
      $lease_time = isset($info['cron']['time']) ?: NULL;
      while (time() < $end && ($item = $this->queue->claimItem($lease_time))) {
        try {
          $queue_worker->processItem($item->data);
          $this->queue->deleteItem($item);
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
        catch (Exception $e) {
          // In case of any other kind of exception, log it and leave the item
          // in the queue to be processed again later.
          watchdog_exception('druki_content', $e);
        }
      }
    }
  }

  /**
   * Clears queue manually.
   */
  public function clearQueue(array &$form, FormStateInterface $form_state) {
    $this->queue->deleteQueue();
  }

}
