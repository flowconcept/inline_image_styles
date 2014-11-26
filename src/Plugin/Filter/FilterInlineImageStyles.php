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
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a filter to restrict images to site.
 *
 * @Filter(
 *   id = "filter_inline_image_styles",
 *   title = @Translation("Inline Image Styles"),
 *   description = @Translation("Allows you to apply image styles on inline images."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   settings = {
 *     "inline_image_style" = "",
 *     "inline_image_link" = "",
 *   },
 *   weight = 100
 * )
 */
class FilterInlineImageStyles extends FilterBase {

  const INLINE_IMAGE_STYLE_ORIGINAL = '';

  const LINK_TO_NOTHING        = '';
  const LINK_TO_ORIGINAL_IMAGE = '@';

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {

    $styles = image_style_options(FALSE);
    $image_styles = array_merge(
      array(static::INLINE_IMAGE_STYLE_ORIGINAL => t('Show the original image')),
      $styles
    );
    $link_types = array_merge(
      array(
        static::LINK_TO_NOTHING        => t('Nothing'),
        static::LINK_TO_ORIGINAL_IMAGE => t('The original image'),
      ),
      $styles
    );

    $form['inline_image_style'] = array(
      '#type' => 'select',
      '#title' => $this->t('Inline image style'),
      '#default_value' => $this->settings['inline_image_style'],
      '#options' => $image_styles,
      // '#description' => $this->t('A list of HTML tags that can be used. JavaScript event attributes, JavaScript URLs, and CSS are always stripped.'),
    );
    $form['inline_image_link'] = array(
      '#type' => 'select',
      '#title' => $this->t('Link image to'),
      '#default_value' => $this->settings['inline_image_link'],
      '#options' => $link_types,
    );

    return $form;
  }

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

  protected function renderStyledImage($uuid, $attributes) {

    $inline_image_style = $this->settings['inline_image_style'];
    $inline_image_link = $this->settings['inline_image_link'];

    $file = $uuid ? entity_load_by_uuid('file', $uuid) : NULL;
    $image_uri = $file->getFileUri();
    $item = (object) array(
      'target_id' => $uuid,
      'alt' => 'ALT',
      'uri' => $image_uri,
    );

    if (static::LINK_TO_NOTHING !== $inline_image_link) {
      $image_style = (static::LINK_TO_ORIGINAL_IMAGE !== $inline_image_link)
        ? entity_load('image_style', $inline_image_link)
        : NULL;
      $uri = array(
        'path' => isset($image_style) ? $image_style->buildUrl($image_uri) : file_create_url($image_uri),
        'options' => array(),
      );
    }

    $cache_tags = array();
    if (static::INLINE_IMAGE_STYLE_ORIGINAL !== $inline_image_style) {
      $image_style = entity_load('image_style', $inline_image_style);
      $cache_tags = $image_style->getCacheTags();
    }

    $element = array(
      '#theme' => 'image_formatter',
      '#item' => $item,
      '#item_attributes' => $attributes,
      '#image_style' => $inline_image_style,
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
    unset($attributes['src']);

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

    $attributes['class'] = trim(($class ? ($class . ' ') : '') . ' inline-image');

    $html = $this->renderStyledImage($uuid, $attributes);

    // Load the altered HTML into a new DOMDocument and retrieve the element.
    foreach(Html::load($html)->getElementsByTagName('body')->item(0)->childNodes as $node) {
      if ($node->nodeName !== '#text') {
        break;
      }
    };

    $node = $dom->importNode($node, TRUE);
    $div = $dom->createElement('div');
    $div->appendChild($node);
    $div->setAttribute('class', trim($div->getAttribute('class') . ' field-type-image inline-image' . $align_class));

    return $div;
  }

}
