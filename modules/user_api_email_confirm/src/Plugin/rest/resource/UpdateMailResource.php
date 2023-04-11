<?php

declare(strict_types=1);

namespace Drupal\user_api_email_confirm\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 * Provides a resource to change user email with confirmation.
 *
 * @RestResource(
 *   id = "user_api_email_confirm_update_mail",
 *   label = @Translation("Update user email with confirmation"),
 *   uri_paths = {
 *     "create" = "/user-api/update-email"
 *   }
 * )
 */
class UpdateMailResource extends ResourceBase {

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
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The config factory.
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
    protected ConfigFactory $configFactory,
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
      $container->get('config.factory'),
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
    if (!$user) {
      return new JsonResponse(['error' => 'User not logged in.'], Response::HTTP_UNAUTHORIZED);
    }
    $this->user = $user;

    // Resource does not support handling of email change without verification.
    if (!$this->isVerificationEnabled()) {
      return new JsonResponse([
        'error' => 'Verification is disabled. Please change email via JSON:API.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // Validate payload.
    $payload = $request->getContent();
    $data = Json::decode($payload);

    if (!array_key_exists('email', $data)) {
      return new JsonResponse([
        'error' => 'Invalid payload: Missing field "email".',
      ], Response::HTTP_BAD_REQUEST);
    }

    $mail = $data['email'];

    $result = $this->verifier->verifyOperation($request, 'set-email', $user, $mail);
    if ($response = $result->toErrorResponse()) {
      return $response;
    }

    return $this->setMail($mail);
  }

  /**
   * Sets the email address on the user entity.
   *
   * @param string $mail
   *   The new email address.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response indicating success.
   */
  protected function setMail(string $mail) {
    $this->user->setEmail($mail);
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

  /**
   * Checks if verification email is enabled.
   */
  protected function isVerificationEnabled(): bool {
    return $this->getConfig()->get('notify.mail_change_verification');
  }

  /**
   * Get module config.
   */
  protected function getConfig() {
    return $this->configFactory->get('user_api_email_confirm.settings');
  }

}
