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

  const LINK_TO_NOTHING = '';
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
    $inline_image_link = $this->settings['inline_image_link'];
    $image_style_id = $this->settings['inline_image_style'];
    $image_styles = \Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple();

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//img[@data-entity-type="file" and @data-entity-uuid]');
    foreach ($nodes as $node) {
      $file_uuid = $node->getAttribute('data-entity-uuid');


      // If the image style is not a valid one, then don't transform the HTML.
      if (empty($file_uuid) || !isset($image_styles[$image_style_id])) {
        continue;
      }

      // Retrieved matching file in array for the specified uuid.
      $matching_files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uuid' => $file_uuid]);
      $file = reset($matching_files);

      // Stop further element processing, if it's not a valid file.
      if (!$file) {
        continue;
      }

      $image = \Drupal::service('image.factory')->get($file->getFileUri());

      // Stop further element processing, if it's not a valid image.
      if (!$image->isValid()) {
        continue;
      }

      $width = $image->getWidth();
      $height = $image->getHeight();

      $node->removeAttribute('width');
      $node->removeAttribute('height');
      $node->removeAttribute('src');

      // Make sure all non-regenerated attributes are retained.
      $attributes = array();
      for ($i = 0; $i < $node->attributes->length; $i++) {
        $attr = $node->attributes->item($i);
        $attributes[$attr->name] = $attr->value;
      }

      $item = (object) array(
        'entity'    => $file,
        'target_id' => $file_uuid,
        // The following attributes are imported from the $attributes parameter
        'alt'       => NULL,
        'width'     => $width,
        'height'    => $height,
        'title'     => NULL,
      );

      // Get information on the linked image
      if (static::LINK_TO_NOTHING !== $inline_image_link) {
        $image_uri = $file->getFileUri();
        if (static::LINK_TO_ORIGINAL_IMAGE !== $inline_image_link) {
          $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load($inline_image_link);
          $path = $image_style ? $image_style->buildUrl($image_uri) : '';
        } else {
          $path = \Drupal::service('file_url_generator')->generateAbsoluteString($image_uri);
        }
        $uri = Url::fromUri($path);
      }

      // Get information on the image style that should be applied on the inline image
      $cache_tags = array();
      if (static::INLINE_IMAGE_STYLE_ORIGINAL !== $image_style_id) {
        $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load($image_style_id);
        $cache_tags = $image_style->getCacheTags();
      }

      $element = array(
        '#theme' => 'image_formatter',
        '#item' => $item,
        '#item_attributes' => $attributes,
        '#image_style' => $image_style_id,
        '#url' => isset($uri) ? $uri : '',
        '#cache' => array(
          'tags' => $cache_tags,
        ),
      );

      $altered_html = \Drupal::service('renderer')->render($element);

      // Load the altered HTML into a new DOMDocument and retrieve the elements.
      $alt_nodes = Html::load(trim($altered_html))->getElementsByTagName('body')
        ->item(0)
        ->childNodes;

      foreach ($alt_nodes as $alt_node) {
        if ($alt_node->nodeName == 'img') {
          // Add a css class
          $alt_node->setAttribute('class', trim($alt_node->getAttribute('class') . ' inline-image'));
        }
        if ($alt_node->nodeName !== '#text' && $alt_node->nodeName !== '#comment') {
          // Import the updated node from the new DOMDocument into the original
          // one, importing also the child nodes of the updated node.
          $new_node = $dom->importNode($alt_node, TRUE);
          // Add the image node(s)!
          // The order of the children is reversed later on, so insert them in reversed order now.
          $node->parentNode->insertBefore($new_node, $node);
        }
      }
      // Finally, remove the original image node.
      $node->parentNode->removeChild($node);
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
}
