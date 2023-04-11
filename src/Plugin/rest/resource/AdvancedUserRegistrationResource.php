<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\user\Plugin\rest\resource\UserRegistrationResource;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides a resource to handle user account creation.
 *
 * The fields that are validated for registration can be configured by changing
 * the user_api.register.fields_to_validate service parameter.
 *
 * This enables having different fields be required for initial
 * account creation.
 *
 * @RestResource(
 *   id = "user_api_user_registration",
 *   label = @Translation("User registration"),
 *   serialization_class = "Drupal\user\Entity\User",
 *   uri_paths = {
 *     "create" = "/user-api/register"
 *   }
 * )
 */
class AdvancedUserRegistrationResource extends UserRegistrationResource {

  /**
   * Constructs a new UserRegistrationResource instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ImmutableConfig $user_settings
   *   A user settings config instance.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param array $fieldsToValidate
   *   An array of fields to validate.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    ImmutableConfig $user_settings,
    AccountInterface $current_user,
    protected array $fieldsToValidate,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger, $user_settings, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('config.factory')->get('user.settings'),
      $container->get('current_user'),
      $container->getParameter('user_api.register.fields_to_validate'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function post(UserInterface $account = NULL) {
    // Validate user entity.
    // This is to create a better error response than the core
    // response which just returns a string with the errors.
    $violations = $this->validateUser($account);
    if (!empty($violations)) {
      foreach ($violations as $field => $violation) {
        if (str_contains($violation['constraint'], 'Unique')) {
          /** @var \Drupal\user\UserInterface $user */
          $user = $this->loadUserByField($field, $account);

          if ($user && $user->getLastAccessedTime() === "0") {
            $user->delete();
            return $this->post($account);
          }
        }
      }

      return new JsonResponse([
        'message' => 'Validation failed',
        'errors' => $violations,
      ], 422);
    }

    // The core registration resource (the parent class) does not activate
    // the user account when email verification is enabled,
    // but admin approval is not.
    // This is not the same behaviour as the core registration form!
    // So we need to re-implement this behaviour here.
    // The following code is copied and altered from the
    // parent implementation.
    // Once the core class fixes it's implementation,
    // the below code can be removed.
    $this->ensureAccountCanRegister($account);

    // Only activate new users if visitors are allowed to register and no email
    // verification required.
    if ($this->userSettings->get('register') == UserInterface::REGISTER_VISITORS) {
      $account->activate();
    }
    else {
      $account->block();
    }

    $this->checkEditFieldAccess($account);

    // Make sure that the user entity is valid (email and name are valid).
    // $this->validate($account);
    // Create the account.
    $account->save();

    $this->sendEmailNotifications($account);

    return new ModifiedResourceResponse($account, 200);
  }

  /**
   * Load user by given field.
   *
   * @param string $fieldType
   *   The field type.
   * @param \Drupal\user\UserInterface $account
   *   The user account entity.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity or null if none found.
   */
  protected function loadUserByField(string $fieldType, UserInterface $account) {
    $results = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties([$fieldType => $account->get($fieldType)->value]);

    return reset($results);
  }

  /**
   * Validate user entity.
   *
   * This method provides more detailed validation errors than the core
   * UnprocessableEntityHttpException.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account entity.
   *
   * @return array
   *   An array of validation errors.
   */
  protected function validateUser(UserInterface $account) {
    $violations = $account->validate();
    $errors = [];

    if ($violations->count() > 0) {
      /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
      foreach ($violations as $violation) {
        $errors[$violation->getPropertyPath()] = [
          'message' => $violation->getMessage(),
          'constraint' => get_class($violation->getConstraint()),
        ];
      }
    }

    return array_intersect_key($errors, array_flip($this->fieldsToValidate));
  }

}
