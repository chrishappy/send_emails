<?php
/**
 * @file
 * Contains \Drupal\send_emails\Form\EmailSettings. 
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

class EmailSettings extends ConfigFormBase  {

  /**
    * @var \Drupal\Core\Datetime\DateFormatter $dateFormatter
    */
  protected $dateFormatter;

  /**
    * @var \Drupal\Core\Cache\CacheBackendInterface $cacheRender
    */
  protected $cacheRender;

  /**
   * An array of configuration names that should be editable.
   *
   * @var array
   */
  protected $editableConfig = [];
  
  /**
    * Constructs a PerformanceForm object.
    *
    * @param \Drupal\av_auction\AuctionApi $auctionApi
    *   The API for the AV Auction
    */
  public function __construct(DateFormatter $dateFormatter, CacheBackendInterface $cacheRender){
    $this->dateFormatter = $dateFormatter;
    $this->cacheRender = $cacheRender;

    $this->formConfig = 'send_emails.settings';
    $this->editableConfig[] = $this->formConfig;
  }

  /**
    * {@inheritdoc}
    */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('cache.render')
    );
  }
  
  /**  
   * {@inheritdoc}  
   */  
  protected function getEditableConfigNames() {  
    return $this->editableConfig;
  }  
  
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'send_emails_email_settings';
  }
  
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      '__emails_definitions' => [
        ['sample', 'This is sample email. Add a new one below in "Email Definitions"'],
      ],
      '__emails_definitions_raw' => 'sample|This is sample email. Add a new one below in "Email Definitions"',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $type = null) {
    
    $config = $this->config($this->formConfig);
    
    /**
     * User emails
     */
    
    $emailDefinitions = $config->get('__emails_definitions') ?? [];
        
    foreach ($emailDefinitions as $definition) {
      
      $definitionName = $definition[0];
      $definitionDescription = $definition[1];
      
      $emailConfig = $config->get('emails.' . $definitionName);
    
      
      $form['emails'][$definitionName] = [
        '#type' => 'details',
        '#title' => $this->t('@name', ['@name' => str_replace('_', ' ', $definitionName)]),
        '#description' => $this->t('@description', ['@description' => $definitionDescription]),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      
      $form['emails'][$definitionName]['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $emailConfig['subject'] ?? 'Sample Subject | {{ site_name }}',
      ];

      $form['emails'][$definitionName]['replyTo'] = [
        '#type' => 'email',
        '#title' => $this->t('Reply To Email'),
        '#default_value' => $emailConfig['replyTo'] ?? '',
      ];
      
      $twigVariables = [
        'name (username)',
        'time',
        'auto_login_link',
        'site_name',
        'site_front',
        'misc.time-raw',
        'misc.userEntity'
      ];
      
      $defaultBody = 
        "<p>Hi {{ username }},</p>".
        
        "<p>You are receiving this email because a page has been added or updated on {{ site_name }}.</p>".
        
        "<p>To view this page, please click on the following auto-login link (valid for XX hours):<br/>".
        "{{auto_login_link}}</p>".
        "<br>".
        
        "<p>Thank you,</p>".
        
        "<p>{{ site_name }}</p>";
      
      $form['emails'][$definitionName]['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#format'=> 'full_html',
        '#description' => $this->t(
          '<strong>TWIG Variables: </strong> @variables. Use as <em>{{ variableName }}</em>',
          ['@variables' => implode(', ', $twigVariables)]),
        '#default_value' => $emailConfig['body'] ?? $defaultBody,
      ];
      
      if (!\Drupal::service('module_handler')->moduleExists('smtp')) {
        $form['emails'][$definitionName]['smtpWarning'] = [
          '#type' => 'markup',
          '#markup' => 'Enable & activiate the <a href="https://www.drupal.org/project/smtp"' .
          'target="_blank">SMTP</a> module to send HTML emails.',
        ];
      }

      $form['emails'][$definitionName]['destination'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Url to Redirect to'),
        '#default_value' => $emailConfig['destination'] ?? '',
        '#field_prefix' => '/',
        '#description' => $this->t('e.g. node/23'),
      ];
    }
    
    
    /**
     * Auction Auctions
     */
    $form['emails_definitions'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Definitions'),
      '#open' => TRUE,
      '#access' => \Drupal::currentUser()->hasPermission('create send_emails emails'),
    ];
    
    $form['emails_definitions']['__emails_definitions'] = [
      '#type' => 'textarea',
      '#title' => 'Definitions',
      '#description' => $this->t(
        'The emails to be used. Enter one value per line, in the format key|description. <br>' .
        'The key is the stored value. The description is optional.'),
      '#default_value' => $config->get('__emails_definitions_raw') ?? '',
    ];

    /**
     * Actions
     */
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;  
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Check the email definitions
    $definitions = [];
    $definitionsRaw = trim($values['__emails_definitions']);
    
    if (!empty($definitionsRaw)) {
      $explodedDefinitions = explode("\n", $definitionsRaw);
      foreach ($explodedDefinitions as $key => $definition) {
        $definitions[$key] = array_map('trim', explode('|', $definition, 2));

        // Check if there's a description?
        //      if (count($definitions[$key]) !== 2) {
        //        $form_state->setError(
        //          $form['emails_definitions']['__emails_definitions'], 
        //          $this->t('The %definition is missing a "|" character', 
        //                   ['%definition' => $definition])
        //        );
        //      }

        if (preg_match('/^[-\w]+$/', $definitions[$key][0]) === FALSE) {
          $form_state->setError(
            $form['emails_definitions']['__emails_definitions'], 
            $this->t('The %key is not formatted properly in %definition', 
                     ['%key' => $definitions[$key][0], '%definition' => $definition])
          );
        }
      }
    }

    // $build = [
    //   '#type' => 'inline_template',
    //   '#template' => $template,
    //   '#context' => $context,
    // ];

    // try {
    //       \Drupal::service('renderer')->renderPlain($build);
    // }
    // catch (\Exception $exception) {
    //   if ($webform_submission->getWebform()->access('update')) {
    //     drupal_set_message(t('Failed to render computed Twig value due to error "%error"', ['%error' => $exception->getMessage()]), 'error');
    //   }
    // };
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);  

    $values = $form_state->getValues();
    $config = $this->config($this->formConfig);

    // Save old definitions (to detect which emails to delete)
    $oldDefinitionKeys = array_column($config->get('__emails_definitions'), 0);
    
    // Only update definitions if user have permission
    if (\Drupal::currentUser()->hasPermission('create send_emails emails')) {
      // Set the old configuations
      $definitionsRaw = trim($values['__emails_definitions']);
      $config->set('__emails_definitions_raw', $definitionsRaw);
      
      // Process the definitions
      $definitions = empty($definitionsRaw) ? [] : explode("\n", $definitionsRaw);
      foreach ($definitions as &$definition) {
        $definition = array_map('trim', explode('|', $definition, 2));
      }
      $config->set('__emails_definitions', $definitions);
    }
    
    // Set email config
    foreach ($oldDefinitionKeys as $key) {
      $value = $values[$key];
      
      if ( is_array($value) && isset($value['subject'], $value['body'], $value['body']['value'], $value['destination']) ) {
        $config->set('emails.' . $key . '.subject', $value['subject']);
        $config->set('emails.' . $key . '.body', $value['body']['value']);
        $config->set('emails.' . $key . '.destination', $value['destination']);
        $config->set('emails.' . $key . '.replyTo', $value['replyTo']);
      }
      else {
        $this->messenger()->addError('Email with @key is not formed properly. Value: @value', [
          '@key' => $key,
          '@value' => json_encode($value, JSON_PRETTY_PRINT|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS)
        ]);
      }
    }
    
    // Delete old email configs
//    $definitionsToDelete = array_diff($oldDefinitionKeys, array_column($definitions, 0));
//
//    foreach ($definitionsToDelete as $definitionToDelete) {
//      $config->clear('emails.' . $definitionToDelete, null);
//    }

    $config->save(); 
   }
  
}

