<?php

/**
 * @file
 * Contains Drupal\inline_image_styles\Plugin\Filter.
 */

namespace Drupal\inline_image_styles\Plugin\Filter;

use Drupal;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use DOMDocument;
use DOMNode;
use DOMElement;

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

    $form['inline_image_style'] = array(
      '#type' => 'select',
      '#title' => $this->t('Inline image style'),
      '#default_value' => $this->settings['inline_image_style'],
      '#options' => $this->getInlineImageStyleOptions(),
    );
    $form['inline_image_link'] = array(
      '#type' => 'select',
      '#title' => $this->t('Link image to'),
      '#default_value' => $this->settings['inline_image_link'],
      '#options' => $this->getLinkImageToOptions(),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $images = $xpath->query('//img[@data-entity-type="file" and @data-entity-uuid]');
    foreach ($images as $image) {
      $uuid = $image->getAttribute('data-entity-uuid');
      if ($uuid) {
        try {
          $updated = $this->createInlineImageNode(
            $uuid,
            $this->getElementAttributes($image, array('src')),
            $dom
          );
        } catch (\Exception $exception) {
          watchdog_exception('warning', $exception);
          return new FilterProcessResult(Html::serialize($dom));
        }
        $image->parentNode->replaceChild($updated, $image);
      }
    }

    return new FilterProcessResult(Html::serialize($dom));
  }

  /**
   * {@inheritdoc}
   *
   * This filter has no tips.
   */
  public function tips($long = FALSE) {
    return NULL;
  }

  /**
   * Returns options available for the "Inline image style" filter setting.
   *
   * @return array
   */
  protected function getInlineImageStyleOptions() {
    return array_merge(
      array(
        static::INLINE_IMAGE_STYLE_ORIGINAL => t('Show the original image')
      ),
      image_style_options(FALSE)
    );
  }

  /**
   * Returns options available for the "Link image to" filter setting.
   *
   * @return array
   */
  protected function getLinkImageToOptions() {
    return array_merge(
      array(
        static::LINK_TO_NOTHING        => t('Nothing'),
        static::LINK_TO_ORIGINAL_IMAGE => t('The original image'),
      ),
      image_style_options(FALSE)
    );
  }

  /**
   * Returns attributes for an HTML element.
   *
   * @param DOMNode $element Element that we need attributes for.
   * @param array   $exclude List of attributes which should be excluded from the result.
   *
   * @return array
   */
  protected function getElementAttributes(DOMNode $element, array $exclude = array()) {

    $attributes = [];
    $length = $element->attributes->length;

    for ($i = 0; $i < $length; ++$i) {
      $item = $element->attributes->item($i);

      if (!in_array($item->name, $exclude)) {
        if ($item->name == 'class') {
          $attributes['class'] = explode(' ', $item->value);
        }
        else {
          $attributes[$item->name] = $item->value;
        }
      }
    }

    return $attributes;
  }

  /**
   * Creates an HTML element for the inline-image file having the specified uuid.
   *
   * @param string      $uuid       UUID of the inline-image file.
   * @param array       $attributes Element's HTML attributes.
   * @param DOMDocument $dom        Document that we a creating the element for.
   *
   * @return DOMElement
   */
  protected function createInlineImageNode($uuid, $attributes, $dom) {

    // Add an extra class to the image tag to identify it among other images on the page
    if (!isset($attributes['class']) || !in_array('inline-image',  $attributes['class'])) {
      $attributes['class'][] = 'inline-image';
    }

    $image_html = $this->renderInlineImage($uuid, $attributes);
    // Load the altered HTML into a new DOMDocument and retrieve the element.
    $updated_nodes = HTML::load($image_html)->getElementsByTagName('body')
      ->item(0)
      ->childNodes;

    $div = $dom->createElement('div');

    foreach($updated_nodes as $updated_node) {
      // Ignore empty text nodes around the element (if any)
      if ($updated_node->nodeName !== '#text' && $updated_node->nodeName !== '#comment') {
        $updated_node = $dom->importNode($updated_node, TRUE);
        $div->appendChild($updated_node);
      }
    };

    // Set the "align" CSS class on the wrapping node to get it styled properly
    $div->setAttribute('class', trim($div->getAttribute('class') . ' field-type-image inline-image'));
    return $div;
  }

  /**
   * Renders HTML for the inline-image file having the specified UUID.
   *
   * @param string $uuid       UUID of the inline-image file.
   * @param array  $attributes Attributes for the inline-image HTML tag.
   *
   * @return string
   *
   * @throws \Exception
   */
  protected function renderInlineImage($uuid, array $attributes) {

    $inline_image_style = $this->settings['inline_image_style'];
    $inline_image_link = $this->settings['inline_image_link'];

    // Get information on the inline-image file being rendered
    $file = Drupal::entityManager()->loadEntityByUuid('file', $uuid);
    if (!$file) {
      throw new \Exception(sprintf('Could not load file by uuid (%s).', $uuid));
    }

    $item = (object) array(
      'entity'    => $file,
      'target_id' => $uuid,
      // The following attributes are imported from the $attributes parameter
      'alt'       => NULL,
      'width'     => NULL,
      'height'    => NULL,
      'title'     => NULL,
    );

    // Get information on the linked image
    if (static::LINK_TO_NOTHING !== $inline_image_link) {
      $image_uri = $file->getFileUri();
      if (static::LINK_TO_ORIGINAL_IMAGE !== $inline_image_link) {
        $image_style = entity_load('image_style', $inline_image_link);
        $path = $image_style ? $image_style->buildUrl($image_uri) : '';
      } else {
        $path = file_create_url($image_uri);
      }
      $uri = Url::fromUri($path);
    }

    // Get information on the image style that should be applied on the inline image
    $cache_tags = array();
    if (static::INLINE_IMAGE_STYLE_ORIGINAL !== $inline_image_style) {
      $image_style = entity_load('image_style', $inline_image_style);
      $cache_tags = $image_style->getCacheTags();
    }

    // Render the element
    $element = array(
      '#theme' => 'image_formatter',
      '#item' => $item,
      '#item_attributes' => $attributes,
      '#image_style' => $inline_image_style,
      '#url' => isset($uri) ? $uri : '',
      '#cache' => array(
        'tags' => $cache_tags,
      ),
    );
    return drupal_render($element);
  }

}
