<?php

declare(strict_types=1);

namespace Drupal\druki_content\Repository;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Utility\Token;
use Drupal\druki\File\FileTrackerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Provides storage for media image storage from the content.
 *
 * The images in source content can be external and local files. No matter what
 * type is used, we should store them with website, even if source files is
 * located in content source directory.
 *
 * This allows us to manipulate with image, making it responsive or multiple
 * sizes with zoom libraries. Also this allows to reduce duplicates files,
 * because it is possible that same file is used in multiple places, but we
 * don't want to store multiple copies of the same file. This repository also
 * cary about finding already existed duplicates and re-used them.
 */
final class ContentMediaImageRepository {

  /**
   * The file tracker.
   */
  protected FileTrackerInterface $fileTracker;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The token.
   */
  protected Token $token;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file system.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs a new ContentMediaImageRepository object.
   *
   * @param \Drupal\druki\File\FileTrackerInterface $file_tracker
   *   The file tracker.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\token\Token $token
   *   The token.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   */
  public function __construct(
    FileTrackerInterface $file_tracker,
    EntityFieldManagerInterface $entity_field_manager,
    Token $token,
    CacheBackendInterface $cache,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
  ) {
    $this->fileTracker = $file_tracker;
    $this->entityFieldManager = $entity_field_manager;
    $this->token = $token;
    $this->cache = $cache;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * Saves image file by URI.
   *
   * @param string $file_uri
   *   The file URI.
   * @param string $image_alt
   *   The image alt.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The media entity if created. NULL if problem happened.
   */
  public function saveByUri(string $file_uri, string $image_alt): ?MediaInterface {
    // Do not create duplicates for the same file.
    if ($media = $this->loadByUri($file_uri)) {
      return $media;
    }
    $file = $this->saveImageToFile($file_uri);
    if (!$file) {
      return NULL;
    }
    return $this->saveFileToMedia($file, $image_alt);
  }

  /**
   * Loads media file by file URI.
   *
   * @param string $file_uri
   *   The file URI.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The media entity. NULL if not found.
   */
  public function loadByUri(string $file_uri): ?MediaInterface {
    if (UrlHelper::isExternal($file_uri)) {
      $file_uri = $this->fetchRemoteImage($file_uri);
    }
    if (!$file_uri || !\file_exists($file_uri)) {
      return NULL;
    }
    $duplicate_file = $this->fileTracker->checkDuplicate($file_uri);
    if (!$duplicate_file) {
      return NULL;
    }
    return $this->fileTracker->getMediaForFile($duplicate_file);
  }

  /**
   * Fetches a remote image.
   *
   * @param string $url
   *   The remote file URL.
   *
   * @return string|null
   *   The local file URI, NULL if problem happens.
   */
  protected function fetchRemoteImage(string $url): ?string {
    $cid = self::class . ':' . __METHOD__ . ':' . $url;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }
    else {
      // Sometimes url can be broken, slow or not accessible at this time.
      // The cURL will throw exception, and we softly skip it.
      $uri = NULL;
      try {
        $destination = $this->fileSystem->tempnam('temporary://', 'druki_content');
        $uri = \system_retrieve_file($url, $destination);
        // If result was FASLE, convert it to NULL.
        if (\is_bool($uri) && !$uri) {
          $uri = NULL;
        }
        $this->cache->set($cid, $uri);
      } finally {
        return $uri;
      }
    }
  }

  /**
   * Saves file into a file and then to media.
   *
   * @param string $file_uri
   *   The file URI.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity.
   */
  protected function saveImageToFile(string $file_uri): ?FileInterface {
    $destination_uri = $this->getMediaImageFieldDestination();
    if (!$this->fileSystem->prepareDirectory($destination_uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return NULL;
    }
    $basename = \basename($file_uri);
    if (UrlHelper::isExternal($file_uri)) {
      $file_uri = $this->fetchRemoteImage($file_uri);
    }
    if (!$file_uri) {
      return NULL;
    }
    $contents = \file_get_contents($file_uri);
    $uri = $this->fileSystem->saveData($contents, $destination_uri . '/' . $basename);
    $file_storage = $this->entityTypeManager->getStorage('file');
    $file = $file_storage->create([
      'uri' => $uri,
      'uid' => 1,
      'status' => \FILE_STATUS_PERMANENT,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Gets field destination value.
   *
   * Looking for value from field settings for image filed of media image
   * bundle. We will respect this setting for using same paths for all image
   * files, not matter, uploaded them programmatically or manually.
   *
   * @return string
   *   The URI folder for saving file.
   */
  protected function getMediaImageFieldDestination(): string {
    $cid = self::class . ':' . __METHOD__;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }
    else {
      $media_image = $this->entityFieldManager->getFieldDefinitions('media', 'image');
      $file_directory = \trim($media_image['field_media_image']->getSetting('file_directory'), '/');
      $uri_scheme = $media_image['field_media_image']->getSetting('uri_scheme');
      // Since this setting can, and will be contain tokens by default. We must
      // handle it too. Also, tokens can contain html, so we strip it.
      $destination = PlainTextOutput::renderFromHtml($this->token->replace($file_directory));
      $destination_uri = $uri_scheme . '://' . $destination;
      $this->cache->set($cid, $destination_uri);
      return $destination_uri;
    }
  }

  /**
   * Creates media entity for a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   * @param string $alt
   *   The media image alt.
   *
   * @return \Drupal\media\MediaInterface
   *   The created media entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function saveFileToMedia(FileInterface $file, string $alt): MediaInterface {
    $media_storage = $this->entityTypeManager->getStorage('media');
    $media = $media_storage->create(['bundle' => 'image']);
    $source_field = $media->getSource()->getConfiguration()['source_field'];
    $media->setName($alt);
    $media->set($source_field, $file);
    $media->save();
    return $media;
  }

}
