<?php

namespace Drupal\druki_content\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list controller for the druki content entity type.
 */
final class DrukiContentListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): object {
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->database = $container->get('database');
    $instance->setRedirectDestination($container->get('redirect.destination'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['table'] = parent::render();

    $total = $this
      ->database
      ->query('SELECT COUNT(*) FROM {druki_content}')
      ->fetchField();

    $build['summary']['#markup'] = $this->t('Total druki contents: @total', ['@total' => $total]);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['internal_id'] = $this->t('Internal ID');
    $header['slug'] = $this->t('Slug');
    $header['langcode'] = $this->t('Langcode');
    $header['core'] = $this->t('Core version');
    $header['title'] = $this->t('Title');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\druki_content\Entity\DrukiContentInterface $entity */
    $row['internal_id'] = $entity->id();
    $row['slug'] = $entity->getSlug();
    $row['langcode'] = $entity->get('langcode')->value;
    $row['core'] = $entity->get('core')->value;
    $row['title'] = $entity->toLink();

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $destination = $this->redirectDestination->getAsArray();
    foreach ($operations as $key => $operation) {
      $operations[$key]['query'] = $destination;
    }

    return $operations;
  }

}
