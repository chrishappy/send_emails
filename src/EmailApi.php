<?php

/**
 * @file
 * Contains the send_emails service
 */

namespace Drupal\send_emails;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;

// For vertification
use Drupal\user\UserInterface;

/* Classes used in the __construct */
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatter;

// For Comment open/close constants
use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;

/**
 * Class EmailApi.
 *
 * @package Drupal\EmailApi
 */
class EmailApi {
  // use StringTranslationTrait;

  protected $entityTypeManager;
  protected $mailManager;
  protected $connection;
  protected $dateFormatter;

  /* Config & Loggers */
  private $emailConfig;
  private $logger;
  
  /**
   * Stored Options
   */
  private $siteEmail;

  /**
   * Class constructor.
   */
  public function __construct(EntityTypeManager $entity_type_manager, MailManager $mail_manager, ConfigFactory $config, LoggerChannelFactoryInterface $logger, Connection $connection, DateFormatter $dateFormatter) {
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->connection = $connection;
    $this->dateFormatter = $dateFormatter;

    // $this->logger = $logger;
    $this->logger = $logger->get('Send Emails');
    
    $this->siteConfig = $config->get('system.site'); // For Email and Site Name
    
    $this->emailConfig = $config->get('send_emails.settings');
    
    // The Auto Login Service
    $this->aluService = \Drupal::service('auto_login_url.create');
    
    // Miscellenous
    $this->time = \Drupal::time()->getRequestTime();
  }
  
  /**
   * Notify Users by providing an array of users
   * 
   * @param $users UserInterface[] the users to send emails to
   * @param $template string the template to send emails to
   * @param $destination the path to redirect to after logging in
   * @param $replyTo the email to send emails when replying
   */
  public function notifyUsersByRole(string $roleToNotify, string $template, string $destination = '', $replyTo = '') {
    
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties([
        'status' => 1,
        'roles' => $roleToNotify,
      ]);
    
    return $this->notifyUsers($users, $template, $destination, $replyTo);
  }
  
  /**
   * Notify Users by providing an array of users
   * 
   * @param $users UserInterface[] the users to send emails to
   * @param $template string the template to send emails to
   * @param $destination the path to redirect to after logging in
   * @param $replyTo the email to send emails when replying
   */
  public function notifyUsers(array $users, string $template, string $destination = '', $replyTo = '') {
    $result = true;
    
    foreach ($users as $user){
        $tries = 0;
        $emailResult = false;
      
        while ($tries < 5 && !$emailResult) {
            $emailResult = $this->notifyUser($user, $template, $destination, $replyTo);
            $tries++;
        }

        if (!$emailResult) {
          $result = false;
          \Drupal::messenger()->addError(
              t('Unable to notify %user &lt;%email&gt; after @num tries', 
              ['%user' => $user->getUsername(), '%email' => $user->getEmail(), '@num' => $tries])
          );      
        }
    }
    
    return $result;
  }

  /**
   * Public function to notify directors/contractors if field_notify is selected
   
   * @param $users UserInterface[] the users to send emails to
   * @param $template string the template to send emails to
   * @param $destination the path to redirect to after logging in
   * @param $replyTo the email to send emails when replying
   
   * @return boolean | array
   */
  public function notifyUser($user, string $template, string $destination = '', $replyTo = '') {

    // Load User
    if ( !($user instanceof UserInterface) ) {
      if (is_int($user)) {
        $userStorage = $this->entityTypeManager->getStorage('user');
        $user = $userStorage->load($user);
      }
      else {
        $this->logger->error('User is not loaded properly');
        return false;
      }
    }
    
    // Set default data
    if (empty($destination)) {
      $destination = $this->emailConfig->get('emails.' . $template . '.destination') ?? '/';
    }
    
    if (empty($replyTo)) {
      $replyTo = $this->emailConfig->get('emails.' . $template . '.replyTo') ?? '';
    }

    // Misc data
    $misc = [];
    $misc['time-raw'] = $this->time;
    $misc['userEntity'] = $user;

    // Auto login link to auction item
    $this->aluService = \Drupal::service('auto_login_url.create');
    $destinationTrimmed = ltrim($destination, '/'); // Remove first slash so URL is local, so trusted
    $autoLoginLink = $this->aluService->create($user->id(), $destinationTrimmed, TRUE);

    // Prepare Body Data
    $data = [
        'username' => $user->getDisplayName(),
        'auto_login_link' => $autoLoginLink,
        'misc' => $misc,
    ];

    // Prepare Email
    $to = $user->getEmail();
    $returnResult = $this->sendTemplate($to, $template, $data, $replyTo);

    return $returnResult;
  }
  
  
  /**
   * Private function that returns the headers for the emails
   *
   * @return boolean | array
   */
  
  public function sendSimple($to, $subject, $body) {
    $replyTo = $this->siteConfig->get('mail');
    $message['to'] = $to;
    $message['subject'] = $subject;
    $message['body'][] = $body;
    $message['headers'] = $this->getHeaders($replyTo);
    $message['params'] = [
      'html' => TRUE,
    ];
    $message['send'] = TRUE;

    $mailer = new PhpMail();
    $message = $mailer->format($message);
    
    return $mailer->mail($message);
  }

  /**
   * Function to send template email through
   */
  public function sendTemplateEmail(string $to, string $template, $data = [], $replyTo = NULL, $send = TRUE) {
    
    return $this->sendTemplate($to, $template, $data, $replyTo, $send);
  }

  /**
   * Public function to send emails based on emails
   *
   * @return boolean
   */
  private function sendTemplate(string $to, string $template, $data = [], $replyTo = '', $send = TRUE) {

    if ( empty($this->emailConfig->get('emails.' . $template)) ) {
      $this->logger->error('Email Template @template does not exist', [
        '@template' => $template,
      ]);
      return false;
    }

    // Set reply to site email by default
    if (empty($replyTo)) {
      $replyTo = $this->siteConfig->get('mail');
    }

    // Add additional data
    $data['site_name'] = $data['site_name'] ?? $this->siteConfig->get('name');
    $data['site_front'] = Url::fromRoute('<front>');

    // Render Subject
    $subjectData = [
      '#type' => 'inline_template',
      '#template' => $this->emailConfig->get('emails.' . $template . '.subject'),
      '#context' => $data,
    ];
    $subject = \Drupal::service('renderer')->renderPlain($subjectData);

    // Add body tags to template & render
    $bodyTemplate = '<body style="font-size: 14px; color: #000;">' . $this->emailConfig->get('emails.' . $template . '.body') . '</body>';
    $bodyData = [
      '#type' => 'inline_template',
      '#template' => $bodyTemplate,
      '#context' => $data,
    ];
    $body = \Drupal::service('renderer')->renderPlain($bodyData);

    // Set the message
     $message['to'] = $to;
    $message['subject'] = $subject;
    $message['body'][] = $body;
    $message['headers'] = $this->getHeaders($replyTo);
    $message['params'] = [
      'html' => TRUE,
    ];
        
    $mailManager = \Drupal::service('plugin.manager.mail');
    $module = 'send_emails';
    $key = 'send_emails.template.' . $template;
    $to = $to;
    $params['message'] = $message;
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = true;

    $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    
    return $result;

  }
  
  /**
   * Private function that returns the headers for the emails
   *
   * @return boolean | array
   */
  
  private function getHeaders($replyTo) {
    // $hostName = \Drupal::request()->getHost();
    // if (self::endsWith($replyTo, '@' . $hostName)) {
    //     $from = $replyTo;
    // }
    // else {
    //    $from = $this->siteConfig->get('mail');
    // }
    
    $from = $this->siteConfig->get('mail'); // Can not override manually
    $headers = [
      'content-type' => 'text/html',
      'MIME-Version' => '1.0',
      'reply-to' => $replyTo,
      'from' => $this->siteConfig->get('name') . ' <'. $from .'>'
    ];
    
    return $headers;
  }
  
  /**
   * Whether a string ends with a substring helper funnction
   * Source: https://stackoverflow.com/a/834355
   */
//   private function endsWith( $haystack, $needle ) {
//         $length = strlen( $needle );
//         if( !$length ) {
//             return true;
//         }
//         return substr( $haystack, -$length ) === $needle;
//     }
  
}
