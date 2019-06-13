<?php

namespace Drupal\druki_markdown\CommonMark\Inline\Element;

use League\CommonMark\Inline\Element\AbstractInlineContainer;

/**
 * Class InternalLinksElement
 *
 * @package Drupal\druki_markdown\CommonMark\Inline\Element
 *
 * @deprecated in flavor of https://gitlab.com/druki/website/issues/32
 */
class InternalLinkElement extends AbstractInlineContainer {

  /**
   * The content ID.
   *
   * @var string
   */
  protected $contentId;

  /**
   * InternalLinkElement constructor.
   *
   * @param string $content_id
   *   The internal content id.
   */
  public function __construct($content_id) {
    $this->contentId = $content_id;
  }

  /**
   * Gets content ID.
   *
   * @return string
   *   The content ID.
   */
  public function getContentId(): string {
    return $this->contentId;
  }

  /**
   * Sets content ID.
   *
   * @param string $content_id
   *   The content ID.
   */
  public function setContentId(string $content_id): void {
    $this->contentId = $content_id;
  }

}
