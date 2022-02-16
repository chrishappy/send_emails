<?php
/**
 * @file
 * Contains \Drupal\send_emails\Form\MinimalEmailSettings. 
 */

namespace Drupal\send_emails_manual\Form;

use Drupal\send_emails\Form\EmailSettings;  
use Drupal\Core\Form\FormStateInterface; 

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Dependency Injection
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\send_emails\EmailApi;


class EmailManualSendByRole extends EmailSettings  {
  /**
   * Cached user roles
   *
   * @var array
   */
  protected $userRolesList = NULL;

  /**
   * An instance of the entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
    * @var \Drupal\Core\Datetime\DateFormatter $dateFormatter
    */
  protected $dateFormatter;

  /**
    * @var \Drupal\Core\Cache\CacheBackendInterface $cacheRender
    */
  protected $cacheRender;

  /**
    * @var \Drupal\send_emails\EmailApi $emailApi
    */
  protected $emailApi;

  /**
   * An array of configuration names that should be editable.
   *
   * @var array
   */
  protected $editableConfig = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entityTypeManager, DateFormatter $dateFormatter, CacheBackendInterface $cacheRender, EmailApi $emailApi) {
    $this->emailApi = $emailApi;
    parent::__construct($config_factory, $entityTypeManager, $dateFormatter, $cacheRender);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('cache.render'),
      $container->get('send_emails.mail'),
    );
  }

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
      throw new NotFoundHttpException();
    }

    // Save the parameter
    $this->email_to_send = $email_to_send;

    // Adjust current form
    $form['#title'] = $this->t('Send %type Email to a User Role', ['%type' => ucwords(str_replace('_', ' ', $email_to_send))]);
    unset($form['emails_definitions']);
    
    // Remove all emails except the specified one
    unset($form['emails']);
    $form['emails'][$email_to_send] = $specifiedEmail;

    // Choose the role
    $form['role_to_send_email'] = [
      '#title' => 'Which Roles to Send Email to',
      '#type' => 'checkboxes',
      '#options' => $this->getUserRoles(),
      '#required' => TRUE,
    ];

    $form['actions']['submit']['#value'] = $this->t('Send Emails Now');
    $form['actions']['submit']['#name'] = 'submit__send_now'; // TODO: Make constant
    
    // Create Draft Button
    $form['#prefix'] = '<div id="send-emails-manual-send-by-role-wrapper">';
    $form['#suffix'] = '</div>';
    $form['actions']['save_draft'] = [
      '#type' => 'button',
      '#value' => $this->t('Save Draft'),
      '#name' => 'submit__save_draft', // TODO: Make constant
      '#limit_validation_errors' => [],
      '#button_type' => 'primary',
      '#weight' => -10, // Move before submit button
      '#ajax' => [
        'callback' => [$this, 'ajaxSubmitForm'],
        'wrapper' => 'send-emails-manual-send-by-role-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];
    
    return $form;  
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the email settings (must before sending to allow processing)
    parent::submitForm($form, $form_state);

    $buttonClicked = $form_state->getTriggeringElement()['#name'] ?? '';
    switch ($buttonClicked) {
      case 'submit__send_now':

        $notificationRoles = array_filter($form_state->getValue('role_to_send_email'));
        $emailTemplate = $this->email_to_send;

        // Get the form field values for sending emails
        $destination = $form_state->getValue([$this->email_to_send, 'destination']);
        $replyTo = $form_state->getValue([$this->email_to_send, 'replyTo']);

        if (!empty($notificationRoles)) {
          $userRolesList = $this->getUserRoles();
          $userRolesSuccess = [];
          $userRolesFailed = [];

          try {
            foreach ($notificationRoles as $notificationRole) {
              $result = $this->emailApi->notifyUsersByRole( $notificationRole, $emailTemplate, $destination, $replyTo);

              if ($result) {
                $userRolesSuccess[$notificationRole] = $userRolesList[$notificationRole];
              }
              else {
                $userRolesFailed[$notificationRole] = $userRolesList[$notificationRole];
              }
            }
          }
          catch (\Exception $e) {
            $this->messenger()->addError($this->t(
              "The emails failed to send. Please contact the administrator. \n Debugging info: %json", 
              ['%json' => htmlspecialchars(json_encode($e->getMessage()))],
            ));
          }
  
          if (!empty($userRolesSuccess)) {
            $this->messenger()->addMessage($this->formatPlural(
              count($userRolesSuccess),
              'Users with the role %roles have been notified.', 
              'Users with any of the roles %roles have been notified.', 
              ['%roles' => implode(', ', $userRolesSuccess)]
            ));
          }
  
          if (!empty($userRolesFailed)) {
            $this->messenger()->addError($this->formatPlural(
              count($userRolesFailed),
              'At least one user with the role %roles could not be notified. Please contact the administrator.', 
              'At least one user with the roles %roles could not be notified. Please contact the administrator.', 
              ['%roles' => implode(', ', $userRolesFailed)]
            ));
          }
        }
        else {
          $this->messenger()->addError($this->t(
            'No roles are selected'
          ));
        }
        break;

      case 'submit__save_draft':
        // Parent submission already handles this case
        break;

      default:
        $this->messenger()->addWarning($this->t(
          'There was an problem handling processing the form.'
        ));
        break;
    }
  }

  /**
   * Submit the form using ajax
   */
  public function ajaxSubmitForm(array &$form, FormStateInterface $form_state) {
    $this->submitForm($form, $form_state);
    return $form;
  }
  
  /**
   * Return an array of user roles keyed by machine name
   */
  public function getUserRoles() {
    if (is_null($this->userRolesList)) {
      $userRoleStorage = $this->entityTypeManager->getStorage('user_role');

      $userList = array_map(function ($userRole) {
        return ucfirst($userRole->label());
      }, $userRoleStorage->loadMultiple());

      // Remove administrator & anonymous users
      // unset($userList['administrator']);
      unset($userList['anonymous']);

      // Remove the default role
      $userList['authenticated'] = $this->t('@defaultText (i.e. all users)', [
        '@defaultText' => $userList['authenticated'],
      ]);
      
      $this->userRolesList = $userList;
    }

    return $this->userRolesList;
  }
  
}

