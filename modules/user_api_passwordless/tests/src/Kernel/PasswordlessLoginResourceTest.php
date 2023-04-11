<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_passwordless\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * PasswordlessLoginResource test.
 *
 * @group user_api
 */
class PasswordlessLoginResourceTest extends EntityKernelTestBase {

  use UserApiTestTrait;
  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'rest',
    'serialization',
    'user_api',
    'user_api_passwordless',
  ];

  /**
   * The URL to the resource.
   *
   * @var \Drupal\Core\Url
   */
  protected $url;

  /**
   * The kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);
    $this->installConfig(['user_api_passwordless']);

    RestResourceConfig::create([
      'id' => 'user_api_passwordless_passwordless_login',
      'plugin_id' => 'user_api_passwordless_passwordless_login',
      'granularity' => RestResourceConfig::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['POST'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])->save();

    $this->drupalSetUpCurrentUser();
    $this->setCurrentUser(User::getAnonymousUser());
    $this->grantPermissions(Role::load(Role::ANONYMOUS_ID), ['restful post user_api_passwordless_passwordless_login']);

    $this->url = Url::fromRoute('rest.user_api_passwordless_passwordless_login.POST');
    $this->httpKernel = $this->container->get('http_kernel');
  }

  /**
   * Test passwordless login initiation.
   */
  public function testPasswordlessLogin() {
    $user = $this->drupalCreateUser();
    $payload = [
      'email' => $user->getEmail(),
    ];

    // FAILURE - already authenticted.
    $this->setCurrentUser($user);
    $request = $this->createJsonRequest('POST', $this->url->toString(), $payload);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(403, $response->getStatusCode());

    $mails = $this->getMails();
    $this->assertEquals(0, count($mails));

    // FAILURE - Invalid email address.
    $this->setCurrentUser(User::getAnonymousUser());
    $request = $this->createJsonRequest('POST', $this->url->toString(), ['email' => 'wrong@example.com']);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(400, $response->getStatusCode());

    $mails = $this->getMails();
    $this->assertEquals(0, count($mails));

    // SUCCESS.
    $this->setCurrentUser(User::getAnonymousUser());
    $request = $this->createJsonRequest('POST', $this->url->toString(), $payload);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode());

    $mails = $this->getMails();
    $this->assertEquals(1, count($mails));
  }

}
