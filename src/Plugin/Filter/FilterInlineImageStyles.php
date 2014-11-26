<?php

/**
 * @file
 * Contains Drupal\inline_image_styles\Plugin\Filter.
 */

namespace Drupal\inline_image_styles\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Html;

/**
 * Provides a filter to restrict images to site.
 *
 * @Filter(
 *   id = "filter_inline_image_styles",
 *   title = @Translation("Inline Image Styles"),
 *   description = @Translation("Allows you to apply image styles on inline images."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   weight = 10
 * )
 */
class FilterInlineImageStyles extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $dom = Html::load($text);
    foreach ($dom->getElementsByTagName('img') as $image) {
      $uuid = $image->getAttribute('data-editor-file-uuid');
      if ($uuid) {
        $node = $this->createInlineImageNode($uuid, $this->readElementAttributes($image), $dom);
        $image->parentNode->replaceChild($node, $image);
      }
    }

    return new FilterProcessResult(Html::serialize($dom));
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return t('TIPSSSS.');
  }

  protected function renderStyledImage($uuid, $attributes, $link_file = true) {

    $file = $uuid ? entity_load_by_uuid('file', $uuid) : NULL;

    $item = (object) array(
      'target_id' => $uuid,
      'alt' => 'ALT',
      'uri' => $file->getFileUri(),
    );

    if ($link_file) {
      $image_uri = $file->getFileUri();
      $uri = array(
        'path' => file_create_url($image_uri),
        'options' => array(),
      );
    }

    $image_style_setting = 'thumbnail';

    // Collect cache tags to be added for each item in the field.
    $cache_tags = array();
    if (!empty($image_style_setting)) {
      $image_style = entity_load('image_style', $image_style_setting);
      $cache_tags = $image_style->getCacheTags();
    }

    $element = array(
      '#theme' => 'image_formatter',
      '#item' => $item,
      '#item_attributes' => $attributes,
      '#image_style' => $image_style_setting,
      '#path' => isset($uri) ? $uri : '',
      '#cache' => array(
        'tags' => $cache_tags,
      ),
    );

    return drupal_render($element);
  }

  protected function readElementAttributes($element) {
    $attributes = array();
    $length = $element->attributes->length;
    for ($i = 0; $i < $length; ++$i) {
      $attributes[$element->attributes->item($i)->name] = $element->attributes->item($i)->value;
    }
    return $attributes;
  }

  protected function createInlineImageNode($uuid, $attributes, $dom) {
    $class = isset($attributes['class']) ? $attributes['class'] : '';
    $align_class = '';
    $class = preg_replace_callback(
      '/ *align-(center|left|right) */i',
      function ($matches) use (&$align_class) {
        $align_class = str_replace('align-center', 'text-align-center', $matches[0]);
        return ' ';
      },
      $class
    );
    $attributes['class'] = ($class ? ($class . ' ') : '') . ' inline-image';

    $html = $this->renderStyledImage($uuid, $attributes);

    // Load the altered HTML into a new DOMDocument and retrieve the element.
    foreach(Html::load($html)->getElementsByTagName('body')->item(0)->childNodes as $node) {
      if ($node->nodeName !== '#text') {
        break;
      }
    };

    $node = $dom->importNode($node, TRUE);
    $p = $dom->createElement('div');
    $p->appendChild($node);
    $p->setAttribute('class', trim($p->getAttribute('class') . ' field-type-image ' . $align_class));

    return $p;
  }

}
