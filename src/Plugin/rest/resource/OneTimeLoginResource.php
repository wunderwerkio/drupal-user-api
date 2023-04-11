<?php

declare(strict_types=1);

namespace Drupal\user_api\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a resource to get a one time login url.
 *
 * @RestResource(
 *   id = "user_api_one_time_login",
 *   label = @Translation("One time login resource"),
 *   uri_paths = {
 *     "create" = "/user-api/one-time-login"
 *   }
 * )
 */
class OneTimeLoginResource extends ResourceBase {

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
    $jsonBody = $request->getContent();
    $data = Json::decode($jsonBody);

    $email = $data['email'] ?? NULL;
    $context = $data['context'] ?? NULL;

    // Validate input data.
    if (!$email) {
      return new JsonResponse([
        'message' => 'The email field is required.',
      ], 400);
    }

    if (!$context) {
      return new JsonResponse([
        'message' => 'The context field is required.',
      ], 400);
    }

    if (!$this->supportedContext($context)) {
      return new JsonResponse([
        'message' => 'The context field is not supported.',
      ], 400);
    }

    // Load user by email.
    $users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    if (empty($users)) {
      return new JsonResponse([
        'message' => 'No user found with the given email.',
      ], 404);
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = reset($users);

    // Make sure the user is not disabled.
    if ($this->userIsBlocked($user->getAccountName())) {
      return new JsonResponse([
        'message' => 'The user has not been activated or is blocked',
      ], 400);
    }

    // Send mail.
    _user_mail_notify($context, $user);

    return new JsonResponse([
      'status' => 'success',
    ]);
  }

  /**
   * Check if requests for given context should be handled.
   *
   * @param string $context
   *   The context to check.
   *
   * @return bool
   *   TRUE if the context is supported, FALSE otherwise.
   */
  protected function supportedContext(string $context): bool {
    return in_array($context, [
      'register_admin_created',
      'register_no_approval_required',
      'password_reset',
    ]);
  }

  /**
   * Check if the user is blocked.
   *
   * @param string $name
   *   The user name.
   *
   * @return bool
   *   TRUE if the user is blocked, FALSE otherwise.
   */
  protected function userIsBlocked(string $name) {
    return user_is_blocked($name);
  }

}
