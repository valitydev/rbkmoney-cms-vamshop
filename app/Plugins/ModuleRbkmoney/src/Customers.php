<?php

use src\Api\ContactInfo;
use src\Api\Customers\CreateCustomer\Request\CreateCustomerRequest;
use src\Api\Exceptions\WrongDataException;
use src\Api\Exceptions\WrongRequestException;
use src\Api\Invoices\CreateInvoice\Response\CreateInvoiceResponse;
use src\Api\Metadata;
use src\Client\Sender;
use src\Exceptions\RequestException;

class Customers
{

    /**
     * @var Sender
     */
    private $sender;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var array
     */
    private $uses = [
        'PaymentMethod',
        'Order',
        'ModuleRbkmoneySetting',
        'ModuleRbkmoneyRecurrent',
        'ModuleRbkmoneyInvoice',
        'ModuleRbkmoneyRecurrentCustomer',
        'ModuleRbkmoneyRecurrentItem',
    ];

    /**
     * @var AppController
     */
    private $model;

    /**
     * @param Sender $sender
     */
    public function __construct(Sender $sender)
    {
        $this->model = new AppController();
        $this->model->uses = $this->uses;

        foreach ($this->model->ModuleRbkmoneySetting->find('all') as $moduleRbkmoneySetting) {
            $setting = $moduleRbkmoneySetting['ModuleRbkmoneySetting'];
            $this->settings[$setting['code']] = $setting['value'];
        }

        $this->sender = $sender;
    }

    /**
     * @return array
     */
    private function getRecurrentItems()
    {
        $recurrent = $this->model->ModuleRbkmoneyRecurrentItem->find('all');

        if (empty($recurrent)) {
            return [];
        }

        $result = '';

        foreach ($recurrent as $item) {
            $result .= $item['ModuleRbkmoneyRecurrentItem']['article'] . PHP_EOL;
        }

        return explode(PHP_EOL, trim($result));
    }

    /**
     * @param CreateInvoiceResponse $invoiceResponse
     *
     * @return array
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    private function createCustomer(CreateInvoiceResponse $invoiceResponse)
    {
        $contactInfo = new ContactInfo();

        if (!empty($email = $_SESSION['Customer']['email'])) {
            $contactInfo->setEmail($email);
        }

        $vamshopVersion = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/app/webroot/version.txt');

        $metadata = new Metadata([
            'shop' => $_SERVER['HTTP_HOST'],
            'userId' => $_SESSION['User']['id'],
            'firstInvoiceId' => $invoiceResponse->id,
            'cms' => 'VamShop',
            'cms_version' => $vamshopVersion,
            'module' => MODULE_NAME_SETTING,
            'module_version' => MODULE_VERSION_SETTING,
        ]);

        $createCustomer = $this->sender->sendCreateCustomerRequest(new CreateCustomerRequest(
            $this->settings['shopId'],
            $contactInfo,
            $metadata
        ));

        $customerParams = [
            'user_id' => $_SESSION['User']['id'],
            'customer_id' => $createCustomer->customer->id,
            'status' => $createCustomer->customer->status->getValue(),
        ];

        $customer = $this->model->ModuleRbkmoneyRecurrentCustomer->save(
            $this->model->ModuleRbkmoneyRecurrentCustomer->create($customerParams)
        );

        $customerParams += [
            'hash' => $createCustomer->payload,
            'id' => $customer['ModuleRbkmoneyRecurrentCustomer']['id'],
        ];

        return $customerParams;
    }

    /**
     * @param array                 $order
     * @param CreateInvoiceResponse $invoiceResponse
     *
     * @return null | string
     *
     * @throws RequestException
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    public function createRecurrent($order, CreateInvoiceResponse $invoiceResponse)
    {
        $articles = [];
        $resultCustomer = null;

        foreach ($order['OrderProduct'] as $item) {
            $articles[$item['price']] = $item['model'];

            $items[$item['model']] = [
                'amount' => $item['price'],
                'name' => $item['name'],
                'model' => $item['model'],
                'currency' => $_SESSION['Customer']['currency_code'],
                'vat_rate' => $this->settings['vatRate'],
                'date' => new DateTime(),
                'status' => RECURRENT_UNREADY_STATUS,
                'order_id' => $order['Order']['id'],
            ];
        }
        $intersections = array_intersect($articles, $this->getRecurrentItems());

        if (!empty($intersections)) {
            $customer = $this->model->ModuleRbkmoneyRecurrentCustomer->find('first', [
                'conditions' => [
                    'user_id' => $_SESSION['User']['id']
                ]
            ]);

            if (empty($customer)) {
                $customer = $this->createCustomer($invoiceResponse);
            } else {
                $customer = $customer['ModuleRbkmoneyRecurrentCustomer'];
            }

            foreach ($intersections as $article) {
                $this->saveRecurrent($customer['id'], $items[$article]);
            }
        }

        if (!empty($customer['hash'])) {
            $resultCustomer = 'data-customer-id="' . $customer['customer_id'] . '"
            data-customer-access-token="' . $customer['hash'] . '"';
        }

        return $resultCustomer;
    }

    /**
     * @param string $recurrentCustomerId
     * @param array  $item
     *
     * @return void
     */
    private function saveRecurrent($recurrentCustomerId, array $item)
    {
        $newCustomer = $this->model->ModuleRbkmoneyRecurrent->create([
                'recurrent_customer_id' => $recurrentCustomerId,
                'amount' => $item['amount'],
                'name' => $item['name'],
                'model' => $item['model'],
                'currency' => $item['currency'],
                'vat_rate' => $item['vat_rate'],
                'date' => $item['date']->format('Y.m.d H:i:s'),
                'status' => $item['status'],
                'order_id' => $item['order_id'],
            ]
        );

        $this->model->ModuleRbkmoneyRecurrent->save($newCustomer);
    }

    /**
     * @param array $order
     */
    public function setRecurrentReadyStatuses($order)
    {
        $articles = [];
        $recurrent = $this->model->ModuleRbkmoneyRecurrent->find('first', [
            'conditions' => [
                'order_id' => $order['Order']['id'],
            ]
        ]);

        if (!empty($recurrent)) {
            foreach ($order['OrderProduct'] as $item) {
                $articles[$item['price']] = $item['model'];
            }
            $intersections = array_intersect(
                $articles,
                $this->getRecurrentItems()
            );

            if (!empty($intersections)) {
                $recurrent['ModuleRbkmoneyRecurrent']['status'] = RECURRENT_READY_STATUS;
                $this->model->ModuleRbkmoneyRecurrent->save($recurrent);
            }
        }
    }

}
