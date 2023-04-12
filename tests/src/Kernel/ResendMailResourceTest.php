<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drupal\verification_hash\VerificationHashManager;

/**
 * ResendMailResource test.
 *
 * @group user_api
 */
class ResendMailResourceTest extends EntityKernelTestBase {

  use UserApiTestTrait;
  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'rest',
    'serialization',
    'user_api',
    'user_api_test',
    'verification',
    'verification_hash',
    'consumers',
    'simple_oauth',
    'image',
    'options',
    'file',
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
   * The hash manager.
   */
  protected VerificationHashManager $hashManager;

  /**
   * The user.
   */
  protected UserInterface $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['simple_oauth']);

    $this->setUpCurrentUser(['uid' => 0]);

    RestResourceConfig::create([
      'id' => 'user_api_resend_mail',
      'plugin_id' => 'user_api_resend_mail',
      'granularity' => RestResourceConfig::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['POST'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])->save();

    $client = Consumer::create([
      'client_id' => 'test_client',
      'label' => 'test',
      'grant_types' => [],
    ]);
    $client->save();

    $this->userSettings = $this->config('user.settings');

    $this->httpKernel = $this->container->get('http_kernel');

    $this->user = $this->drupalCreateUser([
      'restful post user_api_resend_mail',
    ]);
    $this->setCurrentUser($this->user);

    $anonRole = Role::load(Role::ANONYMOUS_ID);
    $this->grantPermissions($anonRole, ['restful post user_api_resend_mail']);

    $this->url = Url::fromRoute('rest.user_api_resend_mail.POST');

    $this->hashManager = $this->container->get('verification_hash.manager');
  }

  /**
   * Test resend mail.
   */
  public function testResendMail(): void {
    // Invalid payloads.
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(422, $response->getStatusCode(), $response->getContent());

    // Invalid email address.
    $request = $this->createJsonRequest('POST', $this->url->toString(), [
      'email' => 'invalid@example.com',
      'operation' => 'register',
    ]);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(400, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);
  }

  /**
   * Test resend mail register.
   */
  public function testResendMailRegister(): void {
    // Do not send if visitor registration is disabled.
    $this->userSettings
      ->set('register', UserInterface::REGISTER_ADMINISTRATORS_ONLY)
      ->save();

    $request = $this->createJsonRequest('POST', $this->url->toString(), [
      'email' => $this->user->getEmail(),
      'operation' => 'register',
    ]);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(500, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);

    $this->userSettings
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->save();

    // Do not send if email verification is disabled.
    $this->userSettings
      ->set('verify_mail', FALSE)
      ->save();

    $request = $this->createJsonRequest('POST', $this->url->toString(), [
      'email' => $this->user->getEmail(),
      'operation' => 'register',
    ]);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(500, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);

    $this->userSettings
      ->set('verify_mail', TRUE)
      ->save();

    // Do not resend for already verified account.
    $user = $this->drupalCreateUser([
      'restful post user_api_resend_mail',
    ]);
    $user->setLastAccessTime(time())->save();

    $request = $this->createJsonRequest('POST', $this->url->toString(), [
      'email' => $user->getEmail(),
      'operation' => 'register',
    ]);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(400, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);

    // Resend.
    $request = $this->createJsonRequest('POST', $this->url->toString(), [
      'email' => $this->user->getEmail(),
      'operation' => 'register',
    ]);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(1, $count);
  }

}
