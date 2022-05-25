# Send Emails

**Requires developing experience**

Provides a UI to create & update emails. Provides a service to send the emails:

```php
\Drupal::service('send_emails.mail')->notifyUsersByRole( $notificationRole, $emailTemplate);
```

You can also include custom TWIG variables using:

```php
$autoLoginLinkUrl = ''; // If an empty string, will use the "Url for Auto Login Link" in the User Interface
$replyToEmail = ''; // If an empty string, will use the "Reply To Email" in the User Interface
$twigVariables = []; // Additional Twig variables that can be accessed in the email template
\Drupal::service('send_emails.mail')->notifyUser($user, $emailTemplate, $autoLoginLinkUrl, $replyToEmail, $twigVariables);
```

See `./src/EmailApi.php` for more functions and documentation

## Example

Here's how I use it in a custom module:

1. Install the module using the normal process (see [documentation](https://www.drupal.org/docs/extending-drupal/installing-modules#s-step-2-enable-the-module))
2. Create a new email 
  1. Go to `Configuation > Send Emails Configuation`: `/admin/config/send_emails/emails#edit-emails-definitions`
  2. Define a new email using: <br>
     `director_private_notes | Send an email when a node "director_agendas" is created or updated and the "field_send_emails_notify" checkbox is checked`
  3. Click "Save"
  4. The page should now have a new email that you can edit
3. Add the below code to a custom module
4. Flush the caches

```php
function YOURMODULE_node_presave(Drupal\Core\Entity\EntityInterface $entity) {
  switch ($entity->bundle()) {
    case 'director_agendas':
      $notificationRole = 'testing';
      $emailTemplate = 'director_private_notes';
      
      if ($entity->hasField('field_send_emails_notify') && $entity->field_send_emails_notify->value == TRUE) {

          $result = \Drupal::service('send_emails.mail')->notifyUsersByRole( $notificationRole, $emailTemplate);

          $entity->set('field_send_emails_notify', FALSE);

          $lastNotificationTime =  \Drupal::service('date.formatter')->format($entity->changed->value, 'last_notified');
        
          $entity->set('field_send_emails_last_sent', 'Sent ' . $lastNotificationTime);

          \Drupal::messenger()->addMessage(
              t('%role have been notified', 
              ['%role' => ucfirst($notificationRole)])
          );

      }
      break;
  }
}
```

## Documentation

### Email Definitions

**Email Fields**:
 - **Subject**: Supports TWIG variables (see below). The subject of the email.
 - **Reply To Email**: the email used when replying to the email (Drupal forces using the site's email as the from address)
 - **Body**: Supports TWIG variables (see below). The HTML body of the email.
 - **Url for Auto Login Link**: the url used in to the `{{ auto_login_link }}` TWIG variable. Allows the user to access the url wile being automatically logged in.

**TWIG Variables**:
 - `{{ name }}` (e.g. username): the username of the user the email is being sent to or the name provided
 - `{{ auto_login_link }}`: a URL link string that automatically logs in the user
 - `{{ site_name }}`: the name of the site (defined in Basic Settings)
 - `{{ site_front }}`: url to the site's front page
 - `{{ misc.time-raw }}`: the UNIX timestamp
 - `{{ misc.userEntity }}`: the Drupal user entity (if it exists)

### Other Links
 - You can edit email definitions with only the subject and body by going to: `/admin/config/send_emails/emails/minimal/[email_template_machine_name]`
 - You can manually send an email to users with a role by:
  1. Enabling the "Send Emails - Manual" submodule
  2. Going to: `/admin/config/send_emails/manual/[email_template_machine_name]`

