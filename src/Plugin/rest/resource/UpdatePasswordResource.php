<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\user\UserInterface;
use Drupal\verification\Service\RequestVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a resource to update a user's password.
 *
 * @RestResource(
 *   id = "user_api_update_password",
 *   label = @Translation("Update user password"),
 *   uri_paths = {
 *     "create" = "/user-api/update-password"
 *   }
 * )
 */
class UpdatePasswordResource extends ResourceBase {

  /**
   * The user entity.
   */
  protected UserInterface $user;

  /**
   * Constructs a new OneTimeLoginResource object.
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
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Password\PasswordInterface $passwordChecker
   *   The password service.
   * @param \Drupal\verification\Service\RequestVerifier $verifier
   *   The request verifier service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PasswordInterface $passwordChecker,
    protected RequestVerifier $verifier,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
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
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('password'),
      $container->get('verification.request_verifier'),
    );
  }

  /**
   * Responds to POST requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response indicating success or failure.
   */
  public function post(Request $request) {
    $user = $this->getCurrentUser();
    if (!$user || !$user->isAuthenticated()) {
      return new JsonResponse(['error' => 'User not logged in.'], Response::HTTP_UNAUTHORIZED);
    }
    $this->user = $user;

    // Validate payload.
    $payload = $request->getContent();
    $data = Json::decode($payload);

    if (!array_key_exists('newPassword', $data)) {
      return new JsonResponse([
        'error' => 'Invalid payload: Missing field "newPassword".',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verify the supported operations.
    $verified = array_reduce(['register', 'set-password'], function ($carry, $operation) use ($request, $user) {
      if ($carry) {
        return $carry;
      }

      $result = $this->verifier->verifyOperation($request, $operation, $user);

      return $result->ok;
    }, FALSE);

    $newPassword = $data['newPassword'];

    // Handle update if verified.
    if ($verified) {
      return $this->setPassword($newPassword);
    }
    // Update password with current password.
    elseif (array_key_exists('currentPassword', $data)) {
      return $this->handlePasswordUpdateWithCurrentPassword($newPassword, $data['currentPassword']);
    }

    return new JsonResponse([
      'error' => 'Unknown error occured!',
    ], Response::HTTP_INTERNAL_SERVER_ERROR);
  }

  /**
   * Handle password update with current password.
   *
   * @param string $newPassword
   *   The new password.
   * @param string $currentPassword
   *   The current password.
   */
  protected function handlePasswordUpdateWithCurrentPassword(string $newPassword, string $currentPassword) {
    // Check current password, if user updates an existing one.
    if ($currentPassHash = $this->user->getPassword()) {
      // Check against currently set password.
      if (!$this->passwordChecker->check($currentPassword, $currentPassHash)) {
        return new JsonResponse(['error' => 'The current password is incorrect.'], Response::HTTP_BAD_REQUEST);
      }
    }

    return $this->setPassword($newPassword);
  }

  /**
   * Notify user about password reset.
   */
  protected function handleResetPassword($reset) {
    if (!$reset) {
      return new JsonResponse([
        'error' => 'Invalid payload.',
      ], Response::HTTP_BAD_REQUEST);
    }

    _user_mail_notify('password_reset', $this->user);

    return new JsonResponse([
      'status' => 'success',
    ]);
  }

  /**
   * Sets the password on the user entity.
   *
   * @param string $newPassword
   *   The new password.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response indicating success.
   */
  protected function setPassword(string $newPassword) {
    $this->user->setPassword($newPassword);
    $this->user->save();

    return new JsonResponse([
      'status' => 'success',
    ]);
  }

  /**
   * Loads the user entity for the current user.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity.
   */
  protected function getCurrentUser(): ?UserInterface {
    return $this->entityTypeManager->getStorage('user')->load(
      $this->currentUser->id()
    );
  }

}
