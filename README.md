Amazon SQS Entity
=================

Dependencies
------------
- [AWS Simple Queue Service](https://www.drupal.org/project/aws_sqs)
- [Composer Manager](https://www.drupal.org/project/composer_manager)
- [composer (drush extension)](https://www.drupal.org/project/composer)

Installation & Setup
--------------------
1. Follow setup instructions for AWS Simple Queue Service module.
2. Set the AWS SQS Entity queue name. This can be done either:
    - With drush: `drush vset aws_sqs_entity_queue_name YOUR_QUEUE_NAME`
    - In your settings file: `$conf['aws_sqs_entity_queue_name'] = 'YOUR_QUEUE_NAME';`
    - Using the settings form at admin/config/system/aws-sqs-entity.
3. Set rules for your allowed list of Entity types, bundles, and CRUD operations.
    - Similar options as above. See `\Drupal\aws_sqs_entity\Entity\CrudQueue::setRules()` for documentation.
