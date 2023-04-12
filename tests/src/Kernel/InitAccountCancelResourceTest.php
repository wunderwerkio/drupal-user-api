<?php

declare(strict_types=1);

namespace Drupal\Tests\user_api_email_confirm\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\Tests\user_api\Kernel\UserApiTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * InitAccountCancelResource test.
 *
 * @group user_api
 */
class InitAccountCancelResourceTest extends EntityKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['user']);

    $this->setUpCurrentUser();

    RestResourceConfig::create([
      'id' => 'user_api_init_account_cancel',
      'plugin_id' => 'user_api_init_account_cancel',
      'granularity' => RestResourceConfig::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['POST'],
        'formats' => ['json'],
        'authentication' => ['cookie'],
      ],
    ])->save();

    $this->userSettings = $this->config('user.settings');

    $this->url = Url::fromRoute('rest.user_api_init_account_cancel.POST');
    $this->httpKernel = $this->container->get('http_kernel');

    $this->user = $this->drupalCreateUser([
      'restful post user_api_init_account_cancel',
    ]);
    $this->setCurrentUser($this->user);

    $anonRole = Role::load(Role::ANONYMOUS_ID);
    $this->grantPermissions($anonRole, ['restful post user_api_init_account_cancel']);
  }

  /**
   * Test reset password.
   */
  public function testInitAccountCancel() {
    // FAILURE - Anonymous account.
    $this->setCurrentUser(User::getAnonymousUser());
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(403, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(0, $count);

    // SUCCESS.
    $this->setCurrentUser($this->user);
    $request = $this->createJsonRequest('POST', $this->url->toString(), []);
    $response = $this->httpKernel->handle($request);
    $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

    $count = count($this->getMails());
    $this->assertEquals(1, $count);
  }

}
