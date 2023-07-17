<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\user\UserInterface;
use Drupal\user_api\ErrorCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Wunderwerk\HttpApiUtils\HttpApiValidationTrait;
use Wunderwerk\JsonApiError\JsonApiErrorResponse;

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

  use HttpApiValidationTrait;

  /**
   * Request payload schema.
   */
  protected array $schema = [
    'type' => 'object',
    'properties' => [
      'email' => [
        'type' => 'string',
        'format' => 'email',
      ],
      'operation' => [
        'type' => 'string',
      ],
    ],
    'required' => ['email', 'operation'],
  ];

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

    $result = $this->validateArray($data, $this->schema);
    if (!$result->isValid()) {
      return $result->getResponse();
    }

    $operation = $data['operation'];

    $result = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'mail' => $data['email'],
    ]);
    if (empty($result)) {
      return JsonApiErrorResponse::fromError(
        status: 400,
        code: ErrorCode::INVALID_EMAIL->getCode(),
        title: 'Invalid email address.'
      );
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = reset($result);

    switch ($operation) {
      case 'register':
        return $this->handleRegister($user);

      default:
        return JsonApiErrorResponse::fromError(
          status: 400,
          code: ErrorCode::INVALID_OPERATION->getCode(),
          title: 'Invalid operation.',
          detail: sprintf('The operation "%s" is not supported!', $operation),
        );
    }

  }

  /**
   * Handles registration verification email resend.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   */
  protected function handleRegister(UserInterface $user) {
    if ($this->userSettings->get('register') !== UserInterface::REGISTER_VISITORS) {
      return JsonApiErrorResponse::fromError(
        status: 500,
        code: ErrorCode::RESEND_MAIL_VISITOR_ACCOUNT_CREATION_DISABLED->getCode(),
        title: 'Registration is disabled.'
      );
    }

    if (!$this->userSettings->get('verify_mail')) {
      return JsonApiErrorResponse::fromError(
        status: 500,
        code: ErrorCode::RESEND_MAIL_REGISTER_VERIFY_MAIL_DISABLED->getCode(),
        title: 'Email verification is disabled.'
      );
    }

    // Make sure not to resend verification email if user is already verified.
    if ($user->getLastAccessedTime() !== "0") {
      return JsonApiErrorResponse::fromError(
        status: 400,
        code: ErrorCode::ALREADY_VERIFIED->getCode(),
        title: 'Account is already verified!'
      );
    }

    _user_mail_notify('register_no_approval_required', $user);

    return $this->createSuccessResponse();
  }

  /**
   * Create a success response.
   */
  protected function createSuccessResponse() {
    return new JsonResponse([
      'status' => 'success',
    ]);
  }

}
