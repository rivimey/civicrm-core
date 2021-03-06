<?php
namespace Civi\API\Subscriber;

use \Civi\API\Kernel;
use \Symfony\Component\EventDispatcher\EventDispatcher;

require_once 'CiviTest/CiviUnitTestCase.php';

/**
 */
class DynamicFKAuthorizationTest extends \CiviUnitTestCase {
  const FILE_WIDGET_ID = 10;

  const FILE_FORBIDDEN_ID = 11;

  const WIDGET_ID = 20;

  const FORBIDDEN_ID = 30;

  /**
   * @var EventDispatcher
   */
  var $dispatcher;

  /**
   * @var Kernel
   */
  var $kernel;

  protected function setUp() {
    parent::setUp();
    \CRM_Core_DAO_AllCoreTables::init(TRUE);

    \CRM_Core_DAO_AllCoreTables::registerEntityType('FakeFile', 'CRM_Fake_DAO_FakeFile', 'fake_file');
    $fileProvider = new \Civi\API\Provider\StaticProvider(
      3,
      'FakeFile',
      array('id', 'entity_table', 'entity_id'),
      array(),
      array(
        array('id' => self::FILE_WIDGET_ID, 'entity_table' => 'fake_widget', 'entity_id' => self::WIDGET_ID),
        array('id' => self::FILE_FORBIDDEN_ID, 'entity_table' => 'fake_forbidden', 'entity_id' => self::FORBIDDEN_ID),
      )
    );

    \CRM_Core_DAO_AllCoreTables::registerEntityType('Widget', 'CRM_Fake_DAO_Widget', 'fake_widget');
    $widgetProvider = new \Civi\API\Provider\StaticProvider(3, 'Widget',
      array('id', 'title'),
      array(),
      array(
        array('id' => self::WIDGET_ID, 'title' => 'my widget'),
      )
    );

    \CRM_Core_DAO_AllCoreTables::registerEntityType('Forbidden', 'CRM_Fake_DAO_Forbidden', 'fake_forbidden');
    $forbiddenProvider = new \Civi\API\Provider\StaticProvider(
      3,
      'Forbidden',
      array('id', 'label'),
      array(
        'create' => \CRM_Core_Permission::ALWAYS_DENY_PERMISSION,
        'get' => \CRM_Core_Permission::ALWAYS_DENY_PERMISSION,
        'delete' => \CRM_Core_Permission::ALWAYS_DENY_PERMISSION,
      ),
      array(
        array('id' => self::FORBIDDEN_ID, 'label' => 'my forbidden'),
      )
    );

    $this->dispatcher = new EventDispatcher();
    $this->kernel = new Kernel($this->dispatcher);
    $this->kernel
      ->registerApiProvider($fileProvider)
      ->registerApiProvider($widgetProvider)
      ->registerApiProvider($forbiddenProvider);
    $this->dispatcher->addSubscriber(new DynamicFKAuthorization(
      $this->kernel,
      'FakeFile',
      array('create', 'get'),
      "select
      case %1
        when " . self::FILE_WIDGET_ID . " then 1
        when " . self::FILE_FORBIDDEN_ID . " then 1
        else 0
      end as is_valid,
      case %1
        when " . self::FILE_WIDGET_ID . " then 'fake_widget'
        when " . self::FILE_FORBIDDEN_ID . " then 'fake_forbidden'
        else null
      end as entity_table,
      case %1
        when " . self::FILE_WIDGET_ID . " then " . self::WIDGET_ID . "
        when " . self::FILE_FORBIDDEN_ID . " then " . self::FORBIDDEN_ID . "
        else null
      end as entity_id
      ",
      array('fake_widget', 'fake_forbidden')
    ));
  }

  protected function tearDown() {
    parent::tearDown();
    \CRM_Core_DAO_AllCoreTables::init(TRUE);
  }

  /**
   * @return array
   */
  public function okDataProvider() {
    $cases = array();

    $cases[] = array('Widget', 'create', array('id' => self::WIDGET_ID));
    $cases[] = array('Widget', 'get', array('id' => self::WIDGET_ID));

    $cases[] = array('FakeFile', 'create', array('id' => self::FILE_WIDGET_ID));
    $cases[] = array('FakeFile', 'get', array('id' => self::FILE_WIDGET_ID));
    $cases[] = array(
      'FakeFile',
      'create',
      array('entity_table' => 'fake_widget', 'entity_id' => self::WIDGET_ID),
    );
    $cases[] = array('FakeFile', 'get', array('entity_table' => 'fake_widget'));

    return $cases;
  }

  /**
   * @return array
   */
  public function badDataProvider() {
    $cases = array();

    $cases[] = array('Forbidden', 'create', array('id' => self::FORBIDDEN_ID), '/Authorization failed/');
    $cases[] = array('Forbidden', 'get', array('id' => self::FORBIDDEN_ID), '/Authorization failed/');

    $cases[] = array('FakeFile', 'create', array('id' => self::FILE_FORBIDDEN_ID), '/Authorization failed/');
    $cases[] = array('FakeFile', 'get', array('id' => self::FILE_FORBIDDEN_ID), '/Authorization failed/');

    $cases[] = array('FakeFile', 'create', array('entity_table' => 'fake_forbidden'), '/Authorization failed/');
    $cases[] = array('FakeFile', 'get', array('entity_table' => 'fake_forbidden'), '/Authorization failed/');

    $cases[] = array(
      'FakeFile',
      'create',
      array('entity_table' => 'fake_forbidden', 'entity_id' => self::FORBIDDEN_ID),
      '/Authorization failed/',
    );
    $cases[] = array(
      'FakeFile',
      'get',
      array('entity_table' => 'fake_forbidden', 'entity_id' => self::FORBIDDEN_ID),
      '/Authorization failed/',
    );

    $cases[] = array(
      'FakeFile',
      'create',
      array(),
      "/Mandatory key\\(s\\) missing from params array: 'id' or 'entity_table/",
    );
    $cases[] = array(
      'FakeFile',
      'get',
      array(),
      "/Mandatory key\\(s\\) missing from params array: 'id' or 'entity_table/",
    );

    $cases[] = array('FakeFile', 'create', array('entity_table' => 'unknown'), '/Unrecognized target entity/');
    $cases[] = array('FakeFile', 'get', array('entity_table' => 'unknown'), '/Unrecognized target entity/');

    return $cases;
  }

  /**
   * @param $entity
   * @param $action
   * @param array $params
   * @dataProvider okDataProvider
   */
  public function testOk($entity, $action, $params) {
    $params['version'] = 3;
    $params['debug'] = 1;
    $params['check_permissions'] = 1;
    $result = $this->kernel->run($entity, $action, $params);
    $this->assertFalse((bool) $result['is_error'], print_r(array(
      '$entity' => $entity,
      '$action' => $action,
      '$params' => $params,
      '$result' => $result,
    ), TRUE));
  }

  /**
   * @param $entity
   * @param $action
   * @param array $params
   * @param $expectedError
   * @dataProvider badDataProvider
   */
  public function testBad($entity, $action, $params, $expectedError) {
    $params['version'] = 3;
    $params['debug'] = 1;
    $params['check_permissions'] = 1;
    $result = $this->kernel->run($entity, $action, $params);
    $this->assertTrue((bool) $result['is_error'], print_r(array(
      '$entity' => $entity,
      '$action' => $action,
      '$params' => $params,
      '$result' => $result,
    ), TRUE));
    $this->assertRegExp($expectedError, $result['error_message']);
  }

}
