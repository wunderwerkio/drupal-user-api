<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a resource to reset a user's password.
 *
 * @RestResource(
 *   id = "user_api_reset_password",
 *   label = @Translation("Reset user password"),
 *   uri_paths = {
 *     "create" = "/user-api/reset-password"
 *   }
 * )
 */
class ResetPasswordResource extends ResourceBase {

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
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
    );
  }

  /**
   * Responds to POST requests.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response indicating success or failure.
   */
  public function post(Request $request) {
    $jsonBody = $request->getContent();
    $data = Json::decode($jsonBody);

    if (!array_key_exists('email', $data)) {
      return new JsonResponse(
        ['error' => 'Missing field "email"'],
        Response::HTTP_BAD_REQUEST
      );
    }

    $result = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'mail' => $data['email'],
    ]);
    if (empty($result)) {
      return new JsonResponse(
        ['error' => 'Invalid email address.'],
        Response::HTTP_BAD_REQUEST
      );
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = reset($result);
    $currentUser = $this->getCurrentUser();

    // If user is logged in, the reset password email can only be sent
    // if the requested email matches that of the authenticated account.
    if ($currentUser->isAuthenticated() && $currentUser->id() !== $user->id()) {
      return new JsonResponse(
        ['error' => 'E-Mail address does not match.'],
        Response::HTTP_FORBIDDEN,
      );
    }

    _user_mail_notify('password_reset', $user);

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
