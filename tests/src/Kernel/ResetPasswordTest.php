<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\UserInterface;

/**
 * ResetPassword test.
 *
 * @group user_api
 */
class ResetPasswordTest extends EntityKernelTestBase {

  use UserApiTestTrait;
  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'rest',
    'serialization',
    'user_api',
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
   * User settings config instance.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $userSettings;

  /**
   * The user.
   */
  protected UserInterface $user;

  /**
   * The user password.
   */
  protected string $password = 'password';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);

    $this->setUpCurrentUser();

    RestResourceConfig::create([
      'id' => 'user_api_reset_password',
      'plugin_id' => 'user_api_reset_password',
      'granularity' => RestResourceConfig::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['POST'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])->save();

    $this->userSettings = $this->config('user.settings');

    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', TRUE)
      ->save();

    $this->url = Url::fromRoute('rest.user_api_reset_password.POST');
    $this->httpKernel = $this->container->get('http_kernel');

    $this->user = $this->drupalCreateUser([
      'restful post user_api_reset_password',
    ]);
    $this->user->setPassword($this->password)->save();
    $this->setCurrentUser($this->user);
  }

  /**
   * Test reset password.
   */
  public function testResetPassword() {
    $secondUser = $this->drupalCreateUser();

    $payload = [
      'email' => $this->user->getEmail(),
    ];

    // FAILURE - Invalid email.
    $request = $this->createJsonRequest('POST', $this->url->toString(), ['email' => 'wrong@example.com']);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(400, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);

    // FAILURE - Valid email, but from other user.
    $request = $this->createJsonRequest('POST', $this->url->toString(), ['email' => $secondUser->getEmail()]);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(403, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);

    // SUCCESS.
    $request = $this->createJsonRequest('POST', $this->url->toString(), $payload);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(1, $count);
  }

}
