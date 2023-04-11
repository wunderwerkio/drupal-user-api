<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\Entity\User;
use Drupal\verification_hash\VerificationHashManager;

/**
 * CancelAccountResource test.
 *
 * @group user_api
 */
class CancelAccountResourceTest extends EntityKernelTestBase {

  use UserApiTestTrait;

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

    $this->setUpCurrentUser();

    RestResourceConfig::create([
      'id' => 'user_api_cancel_account',
      'plugin_id' => 'user_api_cancel_account',
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
      'restful post user_api_cancel_account',
    ]);
    $this->setCurrentUser($this->user);

    $this->url = Url::fromRoute('rest.user_api_cancel_account.POST');

    $this->hashManager = $this->container->get('verification_hash.manager');
  }

  /**
   * Test reset password.
   */
  public function testCancelAccount() {
    // FAILURE - Anonymous account.
    $this->setCurrentUser(User::getAnonymousUser());
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(403, $response->getStatusCode(), $response->getContent());

    // FAILURE - Unverified.
    $this->setCurrentUser($this->user);
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(403, $response->getStatusCode(), $response->getContent());

    // SUCCESS.
    $timestamp = time();
    $hash = $this->hashManager->createHash($this->user, 'cancel-account', $timestamp);

    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $request->headers->set('X-Verification-Hash', sprintf('%s$$%s', $hash, $timestamp));
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

    // Reload user.
    $this->user = User::load($this->user->id());
    // User must be blocked.
    $this->assertTrue($this->user->isBlocked());
  }

  /**
   * Test reset password.
   */
  public function testCancelAccountWithDeletion() {
    // Set cancel mode to delete.
    \Drupal::configFactory()->getEditable('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();

    $timestamp = time();
    $hash = $this->hashManager->createHash($this->user, 'cancel-account', $timestamp);

    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $request->headers->set('X-Verification-Hash', sprintf('%s$$%s', $hash, $timestamp));
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

    // User must be deleted.
    $this->assertNull(User::load($this->user->id()));
  }

}
