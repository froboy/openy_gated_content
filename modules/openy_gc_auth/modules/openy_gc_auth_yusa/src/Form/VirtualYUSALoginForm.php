<?php

namespace Drupal\openy_gc_auth_yusa\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\openy_gc_auth\GCUserAuthorizer;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class VirtualYUSALoginForm.
 *
 * @package Drupal\openy_gc_auth_yusa\Form
 */
class VirtualYUSALoginForm extends FormBase {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $currentRequest;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type targeted by this resource.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityTypeManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * Private storage.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $privateTempStore;

  /**
   * Http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * The Gated Content User Authorizer.
   *
   * @var \Drupal\openy_gc_auth\GCUserAuthorizer
   */
  protected $gcUserAuthorizer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    RequestStack $requestStack,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    FloodInterface $flood,
    PrivateTempStoreFactory $private_temp_store,
    Client $client,
    GCUserAuthorizer $gcUserAuthorizer
  ) {
    $this->currentRequest = $requestStack->getCurrentRequest();
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->flood = $flood;
    $this->privateTempStore = $private_temp_store->get('openy_gc_auth.provider.yusa');
    $this->client = $client;
    $this->gcUserAuthorizer = $gcUserAuthorizer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('flood'),
      $container->get('tempstore.private'),
      $container->get('http_client'),
      $container->get('openy_gc_auth.user_authorizer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'openy_gc_auth_yusa_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $provider_config = $this->configFactory->get('openy_gc_auth.provider.yusa');

    if ($form_state->getValue('verified', FALSE)) {
      $link = Url::fromRoute(
        '<current>',
        [],
        ['absolute' => TRUE])->toString();
      $form['message'] = [
        '#markup' => $provider_config->get('verification_message') . "<br> <a href='$link'>Back to Login form</a>",
        '#prefix' => '<div class="alert alert-info">',
        '#suffix' => '</div>',
      ];
      return $form;
    }

    $form['verification_id'] = [
      '#type' => 'textfield',
      '#title' => $provider_config->get('id_field_text'),
      '#size' => 35,
      '#required' => TRUE,
    ];

    if ($provider_config->get('enable_recaptcha')) {
      $form['captcha'] = [
        '#type' => 'captcha',
        '#captcha_type' => 'recaptcha/reCAPTCHA',
        '#captcha_validate' => 'recaptcha_captcha_validation',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enter Virtual Y'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $provider_config = $this->configFactory->get('openy_gc_auth.provider.yusa');
    $id = $form_state->getValue('verification_id');
    $result = $this->getMemberInformation($id);

    if (
      isset($result['Status']) &&
      $result['Status'] == 'Active' &&
      !empty($result['FirstName']) &&
      !empty($result['LastName']) &&
      !empty($result['Email'])
    ) {
      $name = $result['FirstName'] . ' ' . $result['LastName'];
      $email = $result['Email'];

      // Check if user already created.
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['mail' => $email]);
      $user = reset($users);

      // Create a new inactive user in DB.
      if (!$user) {
        $active = $provider_config->get('enable_email_verification') ? FALSE : TRUE;
        $user = $this->gcUserAuthorizer->createUser($name, $email, $active);
      }

      if ($user instanceof User) {
        if (!$user->isActive() && $provider_config->get('enable_email_verification')) {
          $this->sendEmailVerification($user, $provider_config, $email);
          $form_state->setValue('verified', TRUE);
          $form_state->setRebuild(TRUE);
          return;
        }
        else {
          // Authorize user (register, login, log, etc).
          $this->gcUserAuthorizer->authorizeUser($name, $email);
        }
      }
    }
    else {
      $this->messenger()->addError($this->t('Something went wrong. Please try again.'));
    }

  }

  /**
   * Helper function for verification email sending.
   */
  protected function sendEmailVerification($user, $provider_config, $mail) {
    // Due to form rebuild we have double submit, to avoid double email send
    // we use tempstore to determinate that email already sent.
    if ($this->privateTempStore->get($mail)) {
      // Email already sent, clear tempstore.
      $this->privateTempStore->delete($mail);
      return;
    }

    $path = str_replace('user/reset', 'vy-user/verification', user_pass_reset_url($user));
    $params = [
      'message' => $provider_config->get('email_verification_text') . '<br>',
    ];
    $params['message'] .= 'Click to verify your email: ' . $path;
    $this->mailManager->mail('openy_gc_auth_yusa', 'email_verification', $mail, 'en', $params, NULL, TRUE);
    $this->privateTempStore->set($mail, TRUE);
  }

  /**
   * Provides a method for API call.
   *
   * @param string $type
   *   Request's type.
   * @param array $body
   *   Request's body.
   * @param string $body_format
   *   Format of request's body.
   *
   * @return array|mixed
   *   Return content response or empty.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function doApiCall($type = 'GET', array $body = [], $body_format = 'json') {
    $provider_config = $this->configFactory->get('openy_gc_auth.provider.yusa');

    $body_key = 'body';
    if ($body_format == 'json') {
      $body_key = 'json';
    }

    $options = [
      $body_key => $body,
      'headers' => [
        'Content-Type' => 'application/' . $body_format . ';charset=utf-8',
      ],
      'auth' => [
        $provider_config->get('auth_login'),
        $provider_config->get('auth_pass'),
      ],
    ];

    try {
      $response = $this->client->request($type, $provider_config->get('verification_url'), $options);

      if ($response->getStatusCode() == '200') {
        $content = $response->getBody()->getContents();
        return json_decode($content, TRUE);
      }
    }
    catch (\Exception $e) {
      watchdog_exception('virtual_y', $e);
    }
    return [];
  }

  /**
   * Get information about Y member.
   *
   * @param int $id
   *   Local Member ID.
   *
   * @return array
   *   Information about Member.
   */
  public function getMemberInformation($id) {
    $provider_config = $this->configFactory->get('openy_gc_auth.provider.yusa');
    if ($provider_config->get('verification_type') == 'membership_id') {
      $json = [
        'AssociationNumber' => $provider_config->get('association_number'),
        'LocalMemberId' => $id,
      ];
    }
    if ($provider_config->get('verification_type') == 'email') {
      $json = [
        'AssociationNumber' => $provider_config->get('association_number'),
        'Email' => $id,
      ];
    }
    return $this->doApiCall('POST', $json);
  }

}
