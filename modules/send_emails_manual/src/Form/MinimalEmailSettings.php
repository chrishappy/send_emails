<?php
/**
 * @file
 * Contains \Drupal\send_emails\Form\MinimalEmailSettings. 
 */

namespace Drupal\send_emails\Form;

use Drupal\send_emails_manual\Form\EmailSettings;  
use Drupal\Core\Form\FormStateInterface; 

class EmailManualSendByRole extends EmailSettings  {

  /**
   * Only show emails that begin with the substring 'email_to_send'
   */
  protected $email_to_send;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'send_emails_manual_send_by_role';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $email_to_send = NULL) {

    $form = parent::buildForm($form, $form_state);

    // If the specified email does not exist, throw an error.
    $specifiedEmail = $form['emails'][$email_to_send] ?? false;
    if (!$specifiedEmail) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Adjust current form
    $form['#title'] = $this->t('Send @type Email to a User Role', ['@type' => ucwords(str_replace('_', ' ', $email_to_send))]);
    $form['emails_definitions']['#access'] = false;
    
    $this->email_to_send = $email_to_send;
    
    /**
     * User emails
     */
    unset($form['emails']);
    $form['emails'][$email_to_send] = $specifiedEmail;
    
    return $form;  
  }
  
}

