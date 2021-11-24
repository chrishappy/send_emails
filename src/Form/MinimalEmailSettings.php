<?php
/**
 * @file
 * Contains \Drupal\send_emails\Form\MinimalEmailSettings. 
 */

namespace Drupal\send_emails\Form;

use Drupal\Core\Form\ConfigFormBase;  
use Drupal\Core\Form\FormStateInterface; 

// For default dates in form elements
use Drupal\Core\Datetime\DrupalDateTime;

// Dependency Injection
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\av_auction\AuctionApi;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Cache\CacheBackendInterface;

class MinimalEmailSettings extends EmailSettings  {

  /**
   * Only show emails that begin with the substring 'starts_with'
   */
  protected $starts_with;
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'send_emails_minimal_email_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $starts_with = NULL) {

    $form = parent::buildForm($form, $form_state);

    $form['#title'] = $this->t('Edit @type Emails', ['@type' => ucwords(str_replace('_', ' ', $starts_with))]);
    
    $this->starts_with = $starts_with;
    
    /**
     * User emails
     */
    
   foreach ($form['emails'] as $definitionName => &$definition) {
    // Hide the emails that do not start with the specified string
    if (!empty($this->starts_with) && $this->starts_with !== "all") {
      if (substr($definitionName, 0, strlen($this->starts_with)) !== $this->starts_with) {
        $definition['#access'] = false;
      }

      $definition['replyTo']['#access'] = false;
      $definition['destination']['#access'] = false;
    }
   }

   $form['emails_definitions']['#access'] = false;

    return $form;  
  }
  
}

