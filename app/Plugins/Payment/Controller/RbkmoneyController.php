<?php

use src\Api\Customers\CustomerResponse\Status;
use src\Api\Exceptions\WrongDataException;
use src\Api\Exceptions\WrongRequestException;
use src\Api\Invoices\CreateInvoice\Cart;
use src\Api\Invoices\CreateInvoice\Request\CreateInvoiceRequest;
use src\Api\Invoices\CreateInvoice\Response\CreateInvoiceResponse;
use src\Api\Invoices\CreateInvoice\TaxMode;
use src\Api\Metadata;
use src\Api\Payments\CreatePayment\HoldType;
use src\Api\Payments\CreatePayment\Request\CreatePaymentRequest;
use src\Api\Payments\CreatePayment\Request\CustomerPayerRequest;
use src\Api\Payments\CreatePayment\Request\PaymentFlowHoldRequest;
use src\Api\Payments\CreatePayment\Request\PaymentFlowInstantRequest;
use src\Api\Webhooks\CreateWebhook\Request\CreateWebhookRequest;
use src\Api\Webhooks\CustomersTopicScope;
use src\Api\Webhooks\GetWebhooks\Request\GetWebhooksRequest;
use src\Api\Webhooks\InvoicesTopicScope;
use src\Api\Webhooks\WebhookResponse\WebhookResponse;
use src\Client\Client;
use src\Client\Sender;
use src\Exceptions\RBKmoneyException;
use src\Exceptions\RequestException;
use src\Helpers\Log;
use src\Helpers\Logger;

App::uses('PaymentAppController', 'Payment.Controller');

class RBKmoneyController extends PaymentAppController
{
    /**
     * @var array
     */
    public $uses = [
        'PaymentMethod',
        'Module',
        'Order',
        'OrderProduct',
        'OrderStatusDescription',
        'ModuleRbkmoneySetting',
        'ModuleRbkmoneyRecurrent',
        'ModuleRbkmoneyInvoice',
        'ModuleRbkmoneyRecurrentCustomer',
        'ModuleRbkmoneyRecurrentItem',
        'Tax',
    ];

    /**
     * @var string
     */
    public $module_name = 'Rbkmoney';

    /**
     * @var string
     */
    public $alias = 'rbkmoney';

    /**
     * @var array
     */
    private $settings;

    /**
     * @var Sender
     */
    private $sender;

    /**
     * @param $request
     * @param $response
     */
    public function __construct($request = null, $response = null)
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/app/Plugin/ModuleRbkmoney/src/settings.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/app/Plugin/ModuleRbkmoney/src/autoload.php';

        try {
            foreach ($this->ModuleRbkmoneySetting->find('all') as $moduleRbkmoneySetting) {
                $setting = $moduleRbkmoneySetting['ModuleRbkmoneySetting'];
                $this->settings[$setting['code']] = $setting['value'];
            }

            $currentSchema = ((isset($_SERVER['HTTPS']) && preg_match("/^on$/i", $_SERVER['HTTPS'])) ? 'https' : 'http');
            $this->settings['callbackUrl'] = "$currentSchema://{$_SERVER['HTTP_HOST']}/payment/rbkmoney/result";

            $this->sender = new Sender(new Client(
                $this->settings['apiKey'],
                $this->settings['shopId'],
                RBK_MONEY_API_URL_SETTING
            ));
        } catch (MissingTableException $exception) {
            // Пропуск загрузки настроек модуля при установке
        }

        parent::__construct($request, $response);
    }

    /**
     * @return void
     */
    public function settings()
    {
        $this->redirect('/module_rbkmoney/admin/admin_index');
    }

    /**
     * @return void
     */
    public function install()
    {
        $oldDefault = $this->PaymentMethod->find('first', ['conditions' => ['default' => '1']]);
        $oldDefault['PaymentMethod']['default'] = '0';
        $this->PaymentMethod->save($oldDefault);

        $newModule = $this->PaymentMethod->create([
            'active' => '1',
            'default' => '1',
            'name' => Inflector::humanize($this->module_name),
            'icon' => 'rbkmoney.png',
            'alias' => $this->module_name,
        ]);
        $this->PaymentMethod->save($newModule);

        $newPlagin = $this->Module->create([
            'name' => __d(RBK_MONEY_MODULE, 'RBK_MONEY'),
            'alias' => $this->alias,
            'version' => MODULE_VERSION_SETTING,
            'nav_level' => '5'
        ]);

        $this->Module->save($newPlagin);

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_invoices`');
        $this->PaymentMethod->query('CREATE TABLE `module_rbkmoney_invoices` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `invoice_id` VARCHAR(100) NOT NULL,
          `payload` TEXT NOT NULL,
          `end_date` DATETIME NOT NULL,
          `order_id` INT(11) NOT NULL,
          PRIMARY KEY (`id`))'
        );

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrents`');
        $this->PaymentMethod->query('CREATE TABLE `module_rbkmoney_recurrents` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `recurrent_customer_id` INT(10) UNSIGNED NOT NULL,
          `amount` FLOAT NOT NULL,
          `name` VARCHAR(250) NOT NULL,
          `model` VARCHAR(250) NOT NULL,
          `vat_rate` VARCHAR(20) NULL,
          `currency` VARCHAR(5) NOT NULL,
          `date` DATETIME NOT NULL,
          `status` VARCHAR(20) NOT NULL,
          `order_id` INT(11) NOT NULL,
          PRIMARY KEY (`id`))'
        );

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrent_customers`');
        $this->PaymentMethod->query('CREATE TABLE `module_rbkmoney_recurrent_customers` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `customer_id` VARCHAR(20) NOT NULL,
          `status` VARCHAR(20) NOT NULL,
          PRIMARY KEY (`id`))'
        );

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrent_items`');
        $this->PaymentMethod->query('CREATE TABLE `module_rbkmoney_recurrent_items` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `article` VARCHAR(250) NOT NULL,
          PRIMARY KEY (`id`))'
        );

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_settings`');
        $this->PaymentMethod->query('CREATE TABLE `module_rbkmoney_settings` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(100) NOT NULL,
          `code` VARCHAR(20) NOT NULL,
          `type` VARCHAR(20) NOT NULL,
          `value` TEXT,
          PRIMARY KEY (`id`))'
        );

        $this->PaymentMethod->query("INSERT INTO `module_rbkmoney_settings`
		  (`name`, `code`, `type`)
		  VALUES
          ('RBK_MONEY_API_KEY', 'apiKey', 'textarea'),
          ('RBK_MONEY_SHOP_ID', 'shopId', 'text'),
          ('RBK_MONEY_PAYMENT_TYPE', 'paymentType', 'select'),
          ('RBK_MONEY_HOLD_EXPIRATION', 'holdExpiration', 'select'),
          ('RBK_MONEY_CARD_HOLDER', 'cardHolder', 'select'),
          ('RBK_MONEY_SHADING_CVV', 'shadingCvv', 'select'),
          ('RBK_MONEY_FISCALIZATION', 'fiscalization', 'select'),
          ('publicKey', 'publicKey', 'text'),
          ('RBK_MONEY_SAVE_LOGS', 'saveLogs', 'select'),
          ('RBK_MONEY_SUCCESS_ORDER_STATUS', 'successStatus', 'select'),
          ('RBK_MONEY_HOLD_ORDER_STATUS', 'holdStatus', 'select'),
          ('RBK_MONEY_CANCEL_ORDER_STATUS', 'cancelStatus', 'select'),
          ('RBK_MONEY_REFUND_ORDER_STATUS', 'refundStatus', 'select'),
          ('RBK_MONEY_VAT_RATE', 'vatRate', 'select'),
          ('RBK_MONEY_DELIVERY_VAT_RATE', 'deliveryVatRate', 'select')"
        );

        Cache::clear();
        $this->Session->setFlash(__('Module Installed'));

        $this->redirect('/payment_methods/admin/');
    }

    /**
     * @return void
     */
    public function uninstall()
    {
        $paymentMethod = $this->PaymentMethod->findByAlias($this->module_name);
        $module = $this->Module->find('first', ['conditions' => ['alias' => $this->alias]]);

        $this->PaymentMethod->delete($paymentMethod['PaymentMethod']['id'], true);
        $this->Module->delete($module['Module']['id'], true);

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_invoices`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrents`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrent_customers`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrent_items`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_settings`');

        $this->Session->setFlash(__('Module Uninstalled'));

        $this->redirect('/payment_methods/admin/');
    }

    /**
     * @return string
     *
     * @throws Exception
     */
    public function before_process()
    {
        $shopId = $this->settings['shopId'];

        $order = $this->Order->read(null, $_SESSION['Customer']['order_id']);
        $orderId = $_SESSION['Customer']['order_id'];
        $product = __d(RBK_MONEY_MODULE, 'RBK_MONEY_ORDER_PAYMENT') . " №$orderId {$_SERVER['HTTP_HOST']}";

        try {
            $necessaryWebhooks = $this->getNecessaryWebhooks();
            if (!empty($necessaryWebhooks[InvoicesTopicScope::INVOICES_TOPIC])) {
                $this->createPaymentWebhook(
                    $shopId,
                    $necessaryWebhooks[InvoicesTopicScope::INVOICES_TOPIC]
                );
            }
        } catch (RBKmoneyException $exception) {
            return $exception->getMessage();
        }

        $rbkMoneyInvoices = $this->ModuleRbkmoneyInvoice->find('first', ['conditions' => ['order_id' => $orderId]]);

        // Даем пользователю 5 минут на заполнение даных карты
        $diff = new DateInterval(END_INVOICE_INTERVAL_SETTING);

        if (!empty($rbkMoneyInvoices)) {
            $rbkMoneyInvoice = $rbkMoneyInvoices['ModuleRbkmoneyInvoice'];
            $endDate = new DateTime($rbkMoneyInvoice['end_date']);

            if ($endDate->sub($diff) > new DateTime()) {
                $payload = $rbkMoneyInvoice['payload'];
                $invoiceId = $rbkMoneyInvoice['invoice_id'];
            }
        }

        if (empty($payload)) {
            try {
                $invoiceResponse = $this->createInvoice($order, $product);
            } catch (RBKmoneyException $exception) {
                return $exception->getMessage();
            }
            // Save the order
            foreach ($_POST as $key => $value) {
                $order['Order'][$key] = $value;
            }

            // Get the default order status
            $default_status = $this->Order->OrderStatus->find('first', ['conditions' => ['default' => '1']]);
            $order['Order']['order_status_id'] = $default_status['OrderStatus']['id'];

            // Save the order
            $this->Order->save($order);

            if (!empty($_SESSION['User'])) {
                if (!empty($necessaryWebhooks[CustomersTopicScope::CUSTOMERS_TOPIC])) {
                    try {
                        $this->createCustomerWebhook(
                            $shopId,
                            $necessaryWebhooks[CustomersTopicScope::CUSTOMERS_TOPIC]
                        );
                    } catch (RBKmoneyException $exception) {
                        return $exception->getMessage();
                    }
                }

                include __DIR__ . '/../../ModuleRbkmoney/src/Customers.php';

                try {
                    $customers = new Customers($this->sender);
                    $customer = $customers->createRecurrent($order, $invoiceResponse);
                } catch (RBKmoneyException $exception) {
                    return $exception->getMessage();
                }
            }

            $payload = $invoiceResponse->payload;
            $invoiceId = $invoiceResponse->id;
        }

        if (empty($customer)) {
            $out = 'data-invoice-id="' . $invoiceId . '"
            data-invoice-access-token="' . $payload . '"';
        } else {
            $out = $customer;
        }

        ob_end_clean();

        $holdExpiration = '';
        if ($holdType = (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_TYPE_HOLD') === $this->settings['paymentType'])) {
            $holdExpiration = 'data-hold-expiration="' . $this->getHoldType()->getValue() . '"';
        }

        // При echo true заменяется на 1, а checkout воспринимает только true
        $holdType = $holdType ? 'true' : 'false';
        $showParameter = __d(RBK_MONEY_MODULE, 'RBK_MONEY_SHOW_PARAMETER');

        $requireCardHolder = ($showParameter === $this->settings['cardHolder']) ? 'true' : 'false';
        $shadingCvv = ($showParameter === $this->settings['shadingCvv']) ? 'true' : 'false';

        return '
<form action="' . FULL_BASE_URL . BASE . '/orders/place_order/" name="pay_form" method="GET">
                <input type="hidden" name="orderId" value="' . $orderId . '">
                <input type="hidden" name="paySystem" value="' . get_class($this) . '">
            <script src="' . RBK_MONEY_CHECKOUT_URL_SETTING . '" class="rbkmoney-checkout"
                    data-payment-flow-hold="' . $holdType . '"
                    data-obscure-card-cvv="' . $shadingCvv . '"
                    data-popup-mode="true"
                    data-require-card-holder="' . $requireCardHolder . '"
                    ' . $holdExpiration . '
                    data-name="' . $product . '"
                    data-email="' . $_SESSION['Customer']['email'] . '"
                    data-description="' . $product . '"
                    ' . $out . '
                    data-label="' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_PAY') . '">
            </script>
        </form>
';
    }

    /**
     * @return HoldType
     *
     * @throws WrongDataException
     */
    private function getHoldType()
    {
        $holdType = (__d(RBK_MONEY_MODULE, 'RBK_MONEY_EXPIRATION_PAYER') === $this->settings['holdExpiration'])
            ? HoldType::CANCEL : HoldType::CAPTURE;

        return new HoldType($holdType);
    }

    /**
     * @param array  $order
     * @param string $product
     *
     * @return CreateInvoiceResponse
     *
     * @throws Exception
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    private function createInvoice($order, $product)
    {
        global $config;

        $fiscalization = (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PARAMETER_USE') === $this->settings['fiscalization']);
        $carts = [];
        $sum = 0;
        $failUrl = FULL_BASE_URL . BASE . '/page/checkout' . $config['URL_EXTENSION'];

        foreach ($order['OrderProduct'] as $item) {
            $quantity = $item['quantity'];
            $itemName = $item['name'];
            $price = $item['price'];

            $sum += $price;

            $cart = new Cart(
                "$itemName ($quantity)",
                $quantity,
                $this->prepareAmount($price)
            );

            if ($fiscalization) {
                $vatRate = $this->settings['vatRate'];

                if (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PARAMETER_NOT_USE') === $vatRate || empty($vatRate)) {
                    $carts[] = $cart;

                    continue;
                }

                $carts[] = $cart->setTaxMode(new TaxMode($vatRate));
            } else {
                $carts[] = $cart;
            }
        }

        if ($sum === 0) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_ERROR_AMOUNT_IS_NOT_VALID'));
            $this->redirect($failUrl);
        }

        $endDate = new DateTime();

        $createInvoice = new CreateInvoiceRequest(
            $this->settings['shopId'],
            $endDate->add(new DateInterval(INVOICE_LIFETIME_DATE_INTERVAL_SETTING)),
            $_SESSION['Customer']['currency_code'],
            $product,
            new Metadata([
                'orderId' => $order['Order']['id'],
                'cms' => 'VamShop',
                'cms_version' => file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/app/webroot/version.txt'),
                'module' => MODULE_NAME_SETTING,
                'module_version' => MODULE_VERSION_SETTING,
            ])
        );

        if (0 != $order['Order']['shipping']) {
            $deliveryCart = new Cart(
                __d(RBK_MONEY_MODULE, 'RBK_MONEY_DELIVERY'),
                1,
                $this->prepareAmount($order['Order']['shipping'])
            );
        }

        if ($fiscalization) {
            $deliveryVatRate = $this->settings['deliveryVatRate'];

            if (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PARAMETER_NOT_USE') !== $deliveryVatRate
                && !empty($deliveryVatRate && isset($deliveryCart))) {
                $carts[] = $deliveryCart->setTaxMode(new TaxMode($deliveryVatRate));
            }
            $createInvoice->addCarts($carts);
        } else {
            $createInvoice->setAmount($this->prepareAmount($order['Order']['total']));
        }

        $invoice = $this->sender->sendCreateInvoiceRequest($createInvoice);

        $this->saveInvoice($invoice, $order['Order']);

        return $invoice;
    }

    /**
     * @param CreateInvoiceResponse $invoice
     * @param array                 $order
     *
     * @return void
     */
    private function saveInvoice(CreateInvoiceResponse $invoice, $order)
    {
        $newInvoice = $this->ModuleRbkmoneyInvoice->create([
            'invoice_id' => $invoice->id,
            'payload' => $invoice->payload,
            'end_date' => $invoice->endDate->format('Y-m-d H:i:s'),
            'order_id' => $order['id'],
        ]);

        $this->ModuleRbkmoneyInvoice->save($newInvoice);
    }

    /**
     * @param float $price
     *
     * @return string
     */
    private function prepareAmount($price)
    {
        return number_format($price, 2, '', '');
    }

    public function payment_after()
    {
        // "абстрактный" метод, не используется
    }

    public function after_process()
    {
        // "абстрактный" метод, не используется
    }

    /**
     * @return void
     */
    public function result()
    {
        try {
            $signature = $this->getSignatureFromHeader(getenv('HTTP_CONTENT_SIGNATURE'));

            if (empty($signature)) {
                throw new WrongDataException(
                    __d(RBK_MONEY_MODULE, 'RBK_MONEY_WRONG_SIGNATURE'),
                    HTTP_CODE_FORBIDDEN
                );
            }

            $signDecode = base64_decode(strtr($signature, '-_,', '+/='));
            $message = file_get_contents('php://input');

            if (empty($message)) {
                throw new WrongDataException(
                    __d(RBK_MONEY_MODULE, 'RBK_MONEY_WRONG_VALUE') . ' `callback`',
                    HTTP_CODE_BAD_REQUEST
                );
            }

            if (!$this->verificationSignature($message, $signDecode)) {
                throw new WrongDataException(
                    __d(RBK_MONEY_MODULE, 'RBK_MONEY_WRONG_SIGNATURE'),
                    HTTP_CODE_FORBIDDEN
                );
            }

            $callback = json_decode($message);

            if (isset($callback->invoice)) {
                $this->paymentCallback($callback);
            } elseif (isset($callback->customer)) {
                $this->customerCallback($callback->customer);
            }
        } catch (RBKmoneyException $exception) {
            $this->callbackError($exception);
        }

        if (__d(RBK_MONEY_MODULE, 'RBK_MONEY_SHOW_PARAMETER') === $this->settings['saveLogs']) {
            if (!empty($exception)) {
                $responseMessage = $exception->getMessage();
                $responseCode = $exception->getCode();
            } else {
                $responseMessage = '';
                $responseCode = HTTP_CODE_OK;
            }

            $log = new Log(
                $this->settings['callbackUrl'],
                'POST',
                json_encode(getallheaders()),
                $responseMessage,
                'Content-Type: application/json'
            );

            $log->setRequestBody(file_get_contents('php://input'))->setResponseCode($responseCode);

            $logger = new Logger();
            $logger->saveLog($log);
        }
        exit;
    }

    /**
     * Возвращает сигнатуру из хедера для верификации
     *
     * @param string $contentSignature
     *
     * @return string
     *
     * @throws WrongDataException
     */
    private function getSignatureFromHeader($contentSignature)
    {
        $signature = preg_replace("/alg=(\S+);\sdigest=/", '', $contentSignature);

        if (empty($signature)) {
            throw new WrongDataException(
                __d(RBK_MONEY_MODULE, 'RBK_MONEY_WRONG_SIGNATURE'),
                HTTP_CODE_FORBIDDEN
            );
        }

        return $signature;
    }

    /**
     * @param string $data
     * @param string $signature
     *
     * @return bool
     */
    function verificationSignature($data, $signature)
    {
        $publicKeyId = openssl_pkey_get_public($this->settings['publicKey']);

        if (empty($publicKeyId)) {
            return false;
        }

        $verify = openssl_verify($data, $signature, $publicKeyId, OPENSSL_ALGO_SHA256);

        return ($verify == 1);
    }

    /**
     * @param stdClass $callback
     */
    private function paymentCallback(stdClass $callback)
    {
        if (isset($callback->invoice->metadata->orderId) && isset($callback->eventType)) {
            $invoice = $this->Order->read(null, $callback->invoice->metadata->orderId);
            $type = $callback->eventType;

            if (in_array($type, [
                InvoicesTopicScope::INVOICE_PAID,
                InvoicesTopicScope::PAYMENT_CAPTURED,
            ])) {
                $invoice['Order']['order_status_id'] = $this->getStatusId($this->settings['successStatus']);
                $this->Order->save($invoice);

                include __DIR__ . '/../../ModuleRbkmoney/src/Customers.php';

                $customers = new Customers($this->sender);
                $customers->setRecurrentReadyStatuses($invoice);
            } elseif (in_array($type, [
                InvoicesTopicScope::INVOICE_CANCELLED,
                InvoicesTopicScope::PAYMENT_CANCELLED,
            ])) {
                $invoice['Order']['order_status_id'] = $this->getStatusId($this->settings['cancelStatus']);
                $this->Order->save($invoice);
            } elseif (InvoicesTopicScope::PAYMENT_REFUNDED === $type) {
                $invoice['Order']['order_status_id'] = $this->getStatusId($this->settings['refundStatus']);
                $this->Order->save($invoice);
            } elseif (InvoicesTopicScope::PAYMENT_PROCESSED === $type) {
                $invoice['Order']['order_status_id'] = $this->getStatusId($this->settings['holdStatus']);
                $this->Order->save($invoice);
            }
        }
    }

    /**
     * @param string $statusName
     *
     * @return string
     */
    private function getStatusId($statusName)
    {
        $status = $this->OrderStatusDescription->find('first', [
            'conditions' => [
                'OrderStatusDescription.name' => $statusName,
            ],
        ]);

        return $status['OrderStatusDescription']['order_status_id'];
    }

    /**
     * @param stdClass $customer
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    private function customerCallback(stdClass $customer)
    {
        $this->updateCustomerStatus($customer);

        if ($holdType = (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_TYPE_HOLD') === $this->settings['paymentType'])) {
            $paymentFlow = new PaymentFlowHoldRequest($this->getHoldType());
        } else {
            $paymentFlow = new PaymentFlowInstantRequest();
        }

        $payRequest = new CreatePaymentRequest(
            $paymentFlow,
            new CustomerPayerRequest($customer->id),
            $customer->metadata->firstInvoiceId
        );

        $this->sender->sendCreatePaymentRequest($payRequest);
    }

    /**
     * @param stdClass $customer
     *
     * @return void
     *
     * @throws WrongDataException
     */
    private function updateCustomerStatus(stdClass $customer)
    {
        $status = new Status($customer->status);

        $customer = $this->ModuleRbkmoneyRecurrentCustomer->find('first', [
            'conditions' => [
                'customer_id' => $customer->id
            ]
        ]);

        if (!empty($customer)) {
            $customer['ModuleRbkmoneyRecurrentCustomer']['status'] = $status->getValue();

            $this->ModuleRbkmoneyRecurrentCustomer->save($customer);
        }
    }

    /**
     * @param RBKmoneyException $exception
     */
    private function callbackError(RBKmoneyException $exception)
    {
        header('Content-Type: application/json', true, $exception->getCode());

        echo json_encode(['message' => $exception->getMessage()], 256);
    }

    /**
     * @param string $shopId
     * @param array  $types
     *
     * @return void
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    private function createPaymentWebhook($shopId, array $types)
    {
        $invoiceScope = new InvoicesTopicScope($shopId, $types);

        $webhook = $this->sender->sendCreateWebhookRequest(
            new CreateWebhookRequest($invoiceScope, $this->settings['callbackUrl'])
        );

        $this->savePublicKey($webhook->publicKey);
    }

    /**
     * @return array
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    private function getNecessaryWebhooks()
    {
        $webhooks = $this->sender->sendGetWebhooksRequest(new GetWebhooksRequest());

        $statuses = [
            InvoicesTopicScope::INVOICES_TOPIC => [
                InvoicesTopicScope::INVOICE_PAID,
                InvoicesTopicScope::PAYMENT_PROCESSED,
                InvoicesTopicScope::PAYMENT_CAPTURED,
                InvoicesTopicScope::INVOICE_CANCELLED,
                InvoicesTopicScope::PAYMENT_REFUNDED,
                InvoicesTopicScope::PAYMENT_CANCELLED,
                InvoicesTopicScope::PAYMENT_PROCESSED,
            ],
            CustomersTopicScope::CUSTOMERS_TOPIC => [
                CustomersTopicScope::CUSTOMER_READY,
            ],
        ];

        /**
         * @var $webhook WebhookResponse
         */
        foreach ($webhooks->webhooks as $webhook) {
            if (empty($webhook) || $this->settings['callbackUrl'] !== $webhook->url) {
                continue;
            }
            if (InvoicesTopicScope::INVOICES_TOPIC === $webhook->scope->topic) {
                $statuses[InvoicesTopicScope::INVOICES_TOPIC] = array_diff(
                    $statuses[InvoicesTopicScope::INVOICES_TOPIC],
                    $webhook->scope->eventTypes
                );
            } else {
                $statuses[CustomersTopicScope::CUSTOMERS_TOPIC] = array_diff(
                    $statuses[CustomersTopicScope::CUSTOMERS_TOPIC],
                    $webhook->scope->eventTypes
                );
            }
        }

        $this->savePublicKey($webhook->publicKey);

        return $statuses;
    }

    /**
     * @param string $shopId
     * @param array  $types
     *
     * @return void
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    private function createCustomerWebhook($shopId, array $types)
    {
        $scope = new CustomersTopicScope($shopId, $types);

        $webhook = $this->sender->sendCreateWebhookRequest(
            new CreateWebhookRequest($scope, $this->settings['callbackUrl'])
        );

        $this->savePublicKey($webhook->publicKey);
    }

    /**
     * @param string $key
     *
     * @return void
     */
    private function savePublicKey($key)
    {
        $publicKey = $this->ModuleRbkmoneySetting->find('first', ['conditions' => ['code' => 'publicKey']]);

        if (empty($publicKey)) {
            $publicKey = $this->ModuleRbkmoneySetting->create([
                'name' => 'publicKey',
                'code' => 'publicKey',
                'type' => 'text',
            ]);
        }
        $publicKey['ModuleRbkmoneySetting']['value'] = $key;

        $this->ModuleRbkmoneySetting->save($publicKey);
    }

    /**
     * Запуск рекуррентов
     *
     * @return void
     */
    public function recurrent()
    {
        include $_SERVER['DOCUMENT_ROOT'] . '/app/Plugin/ModuleRbkmoney/src/recurrentCron.php';
        exit;
    }

}