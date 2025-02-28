<?php

namespace Drupal\druki_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\druki_content\Builder\ContentTableOfContentsBuilder;
use Drupal\druki_content\Entity\DrukiContentInterface;

/**
 * Provides a druki content toc block.
 *
 * @Block(
 *   id = "druki_content_toc",
 *   admin_label = @Translation("Druki content TOC"),
 *   category = @Translation("Druki content"),
 *   context_definitions = {
 *     "druki_content" = @ContextDefinition("entity:druki_content", label = @Translation("Druki Content"), required = TRUE),
 *   }
 * )
 */
class DrukiContentTocBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'context_mapping' => [
        'druki_content' => '@druki_content.druki_content_route_context:druki_content',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [];
    $druki_content = $this->getDrukiContentFromContext();
    if (!$druki_content) {
      return $build;
    }

    $content_document = $druki_content->getContentDocument();
    if (!$content_document) {
      return $build;
    }
    $toc = ContentTableOfContentsBuilder::build($content_document->getContent());
    if ($toc->getIterator()->count()) {
      $build['toc'] = [
        '#theme' => 'druki_content_toc',
        '#toc' => $toc,
      ];
    }
    return $build;
  }

  /**
   * Gets content from context.
   *
   * @return \Drupal\druki_content\Entity\DrukiContentInterface
   *   The content entity.
   */
  protected function getDrukiContentFromContext(): DrukiContentInterface {
    return $this->getContextValue('druki_content');
  }

  /**
   * {@inheritDoc}
   */
  public function getCacheTags(): array {
    $cache_tags = [];

    if ($druki_content = $this->getDrukiContentFromContext()) {
      $cache_tags = Cache::mergeTags($cache_tags, $druki_content->getCacheTags());
    }

    return Cache::mergeTags(
      parent::getCacheTags(),
      $cache_tags,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    $cache_contexts = [
      'url.path',
    ];

    return Cache::mergeContexts(
      parent::getCacheContexts(),
      $cache_contexts,
    );
  }

}
