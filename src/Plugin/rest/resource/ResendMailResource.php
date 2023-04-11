<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
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
 * Provides a resource to resend a verification email.
 *
 * @RestResource(
 *   id = "user_api_resend_mail",
 *   label = @Translation("Resend mail"),
 *   uri_paths = {
 *     "create" = "/user-api/resend-mail"
 *   }
 * )
 */
class ResendMailResource extends ResourceBase {

  /**
   * The user entity.
   */
  protected UserInterface $user;

  /**
   * Constructs a new object.
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
   * @param \Drupal\Core\Config\ImmutableConfig $userSettings
   *   The user settings.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ImmutableConfig $userSettings,
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
      $container->get('config.factory')->get('user.settings'),
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

    if (!array_key_exists('operation', $data)) {
      return new JsonResponse(
        ['error' => 'Missing field "operation"'],
        Response::HTTP_BAD_REQUEST
      );
    }

    $operation = $data['operation'];

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

    if (
      $operation === "register" &&
      $this->userSettings->get('register') === UserInterface::REGISTER_VISITORS &&
      $this->userSettings->get('verify_mail')
    ) {
      if ($user->getLastAccessedTime() !== "0") {
        return new JsonResponse(
          ['error' => 'Account already verified!'],
          Response::HTTP_BAD_REQUEST
        );
      }

      _user_mail_notify('register_no_approval_required', $user);
    }

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
