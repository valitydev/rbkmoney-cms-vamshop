<?php

use src\Api\Exceptions\WrongDataException;
use src\Api\Exceptions\WrongRequestException;
use src\Api\Invoices\CreateInvoice\Cart;
use src\Api\Invoices\CreateInvoice\Request\CreateInvoiceRequest;
use src\Api\Invoices\CreateInvoice\Response\CreateInvoiceResponse;
use src\Api\Invoices\CreateInvoice\TaxMode;
use src\Api\Metadata;
use src\Api\Payments\CreatePayment\Request\CreatePaymentRequest;
use src\Api\Payments\CreatePayment\Request\CustomerPayerRequest;
use src\Api\Payments\CreatePayment\Request\PaymentFlowInstantRequest;
use src\Client\Client;
use src\Client\Sender;
use src\Exceptions\RequestException;

$recurrent = new RecurrentController();

foreach ($recurrent->getRecurrentPayments() as $payment) {
    $payment = $payment['ModuleRbkmoneyRecurrent'];

    $customer = $recurrent->getCustomer($payment['recurrent_customer_id']);
    $user = $recurrent->getUser($customer['user_id']);

    try {
        $invoice = $recurrent->createInvoice($payment, $user);
        $recurrent->createPayment($invoice, $customer['customer_id']);
        echo __d(RBK_MONEY_MODULE, 'RBK_MONEY_RECURRENT_SUCCESS') . $payment['id'] . PHP_EOL;
    } catch (Exception $exception) {
        echo $exception->getMessage();
    }
}

class RecurrentController
{
    /**
     * @var array
     */
    private $settings;

    /**
     * @var AppController
     */
    private $model;

    /**
     * @var Sender
     */
    private $sender;

    /**
     * @var array
     */
    private $uses = [
        'PaymentMethod',
        'ContentProduct',
        'OrderProduct',
        'Order',
        'User',
        'ModuleRbkmoneySetting',
        'ModuleRbkmoneyRecurrent',
        'ModuleRbkmoneyInvoice',
        'ModuleRbkmoneyRecurrentCustomer',
        'ModuleRbkmoneyRecurrentItem',
    ];

    public function __construct()
    {
        require_once 'settings.php';
        require_once 'autoload.php';

        $this->model = new AppController();
        $this->model->uses = $this->uses;

        foreach ($this->model->ModuleRbkmoneySetting->find('all') as $moduleRbkmoneySetting) {
            $setting = $moduleRbkmoneySetting['ModuleRbkmoneySetting'];
            $this->settings[$setting['code']] = $setting['value'];
        }

        $this->sender = new Sender(new Client(
            $this->settings['apiKey'],
            $this->settings['shopId'],
            RBK_MONEY_API_URL_SETTING
        ));
    }

    /**
     * @return array
     */
    public function getRecurrentPayments()
    {
        return $this->model->ModuleRbkmoneyRecurrent->find('all', [
            'conditions' => [
                'ModuleRbkmoneyRecurrent.status' => RECURRENT_READY_STATUS,
            ],
        ]);
    }

    /**
     * @param int $recurrentCustomerId
     *
     * @return array | null
     */
    public function getCustomer($recurrentCustomerId)
    {
        $customer =  $this->model->ModuleRbkmoneyRecurrentCustomer->find('first', [
            'conditions' => [
                'ModuleRbkmoneyRecurrentCustomer.id' => $recurrentCustomerId,
            ],
        ]);

        return $customer['ModuleRbkmoneyRecurrentCustomer'];
    }

    /**
     * @param int $userId
     *
     * @return array
     */
    public function getUser($userId)
    {
        return $this->model->User->find('first', [
            'conditions' => [
                'User.id' => $userId,
            ],
        ]);
    }

    /**
     * @param array $payment
     * @param array    $user
     *
     * @return CreateInvoiceResponse
     *
     * @throws Exception
     * @throws RequestException
     * @throws WrongDataException
     */
    public function createInvoice($payment, array $user)
    {
        $defaultStatus = $this->model->Order->OrderStatus->find('first', [
            'conditions' => [
                'default' => '1',
            ],
        ]);
        $paymentMethod = $this->model->PaymentMethod->find('first', [
            'conditions' => [
                'PaymentMethod.alias' => 'RBKmoney',
            ],
        ]);

        $newInvoice = $this->model->Order->create([
            'customer_id' => $user['User']['id'],
            'order_status_id' => $defaultStatus['OrderStatus']['id'],
            'payment_method_id' => $paymentMethod['PaymentMethod']['id'],
            'total' => $payment['amount'],
        ]);
        $invoice = $this->model->Order->save($newInvoice);

        $contentProduct = $this->model->ContentProduct->find('first', [
            'conditions' => [
                'ContentProduct.model' => $payment['model'],
            ],
        ]);

        $invoiceItem = $this->model->OrderProduct->create([
            'order_id' => $invoice['Order']['id'],
            'content_id' => $contentProduct['ContentProduct']['content_id'],
            'name' => $payment['name'],
            'model' => $payment['model'],
            'quantity' => 1,
            'price' => $payment['amount'],
            'weight' => $contentProduct['ContentProduct']['weight'],
            'length' => $contentProduct['ContentProduct']['length'],
            'width' => $contentProduct['ContentProduct']['width'],
            'height' => $contentProduct['ContentProduct']['height'],
            'volume' => $contentProduct['ContentProduct']['volume'],
        ]);
        $this->model->OrderProduct->save($invoiceItem);

        $endDate = new DateTime();
        $shopId = $this->settings['shopId'];
        $product = __d(RBK_MONEY_MODULE, 'RBK_MONEY_ORDER_PAYMENT') . "№{$invoice['Order']['id']} {$_SERVER['HTTP_HOST']}";

        $createInvoice = new CreateInvoiceRequest(
            $shopId,
            $endDate->add(new DateInterval(INVOICE_LIFETIME_DATE_INTERVAL_SETTING)),
            $payment['currency'],
            $product,
            new Metadata([
                'orderId' => $invoice['Order']['id'],
                'cms' => 'VamShop',
                'cms_version' => file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/app/webroot/version.txt'),
                'module' => MODULE_NAME_SETTING,
                'module_version' => MODULE_VERSION_SETTING,
            ])
        );

        if (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PARAMETER_USE') === $this->settings['fiscalization']) {
            $cart = new Cart(
                "{$payment['name']} (1)",
                1,
                $this->prepareAmount($payment['amount'])
            );

            if (__d(RBK_MONEY_MODULE, 'RBK_MONEY_PARAMETER_USE') === $this->settings['vatRate'] || !empty($payment['vat_rate'])) {
                $cart->setTaxMode(new TaxMode($payment['vat_rate']));
            }

            $createInvoice->addCart($cart);
        } else {
            $createInvoice->setAmount($this->prepareAmount($payment['amount']));
        }

        return $this->sender->sendCreateInvoiceRequest($createInvoice);
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

    /**
     * @param CreateInvoiceResponse $invoice
     * @param string                $customerId
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    public function createPayment(CreateInvoiceResponse $invoice, $customerId)
    {
        $payRequest = new CreatePaymentRequest(
            new PaymentFlowInstantRequest(),
            new CustomerPayerRequest($customerId),
            $invoice->id
        );

        $this->sender->sendCreatePaymentRequest($payRequest);
    }
}

