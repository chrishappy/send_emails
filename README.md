# Send Emails

**Requires developing experience**

Provides a UI to create & update emails. Provides a service to send the emails.

Here's how I use it in a custom module

```
function custom_node_presave(Drupal\Core\Entity\EntityInterface $entity) {
  switch ($entity->bundle()) {
    case 'prvtpgs':
      $notificationRole = 'testing';
      $emailTemplate = 'director_private_notes';
      
      if ($entity->hasField('field_send_emails_notify') && $entity->field_send_emails_notify->value == TRUE) {

          $result = \Drupal::service('send_emails.mail')->notifyUsersByRole( $notificationRole, $emailTemplate);

          $entity->set('field_send_emails_notify', FALSE);

          $lastNotificationTime =  \Drupal::service('date.formatter')->format($entity->changed->value, 'last_notified');
        
          $entity->set('field_send_emails_last_sent', 'Sent ' . $lastNotificationTime);


          \Drupal::messenger()->addMessage(
              t('@roles have been notified', 
              ['@role' => ucfirst($notificationRole)])
          );

      }
      break;
  }
}
```

 1. Go to Configuation > Send Emails Configuation
 2. Define an email by putting in a machine safe string
 3. Click save()
 4. Edit your new email definition
 5. Send your email using `\Drupal::service('send_emails.mail')->notifyUsersByRole( $notificationRole, $emailTemplate);`
 
 See `./src/EmailApi.php` for more functions