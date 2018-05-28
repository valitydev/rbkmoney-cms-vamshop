<?php

use src\Api\Exceptions\WrongDataException;
use src\Api\Exceptions\WrongRequestException;
use src\Api\Invoices\CreateInvoice\TaxMode;
use src\Api\Invoices\GetInvoiceById\Request\GetInvoiceByIdRequest;
use src\Api\Payments\CancelPayment\Request\CancelPaymentRequest;
use src\Api\Payments\CapturePayment\Request\CapturePaymentRequest;
use src\Api\Payments\CreateRefund\Request\CreateRefundRequest;
use src\Api\Search\SearchPayments\Request\SearchPaymentsRequest;
use src\Api\Search\SearchPayments\Response\Payment;
use src\Client\Client;
use src\Client\Sender;
use src\Exceptions\RBKmoneyException;
use src\Exceptions\RequestException;
use src\Helpers\Logger;
use src\Helpers\Paginator;

class AdminController extends ModuleRbkmoneyAppController
{

    /**
     * @var array
     */
    private $settings;

    /**
     * @param CakeRequest         $request
     * @param CakeResponse | null $response
     */
    public function __construct($request, $response = null)
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/app/Plugin/ModuleRbkmoney/src/settings.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/app/Plugin/ModuleRbkmoney/src/autoload.php';

        foreach ($this->ModuleRbkmoneySetting->find('all') as $moduleRbkmoneySetting) {
            $setting = $moduleRbkmoneySetting['ModuleRbkmoneySetting'];
            $this->settings[$setting['code']] = $setting['value'];
        }

        parent::__construct($request, $response);
    }

    /**
     * @param string $recurrentId
     */
    public function recurrent_delete($recurrentId)
    {
        $this->ModuleRbkmoneyRecurrent->delete($recurrentId);
        $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_RECURRENT_DELETED'));

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_RECURRENT);
    }

    public function admin_help()
    {
        $this->redirect('/module_rbkmoney/admin/admin_index/');
    }

    /**
     * @return array
     */
    public function getSettings()
    {
        $settings = $this->ModuleRbkmoneySetting->find('all');

        foreach ($settings as $key => $value) {
            if ($settings[$key]['ModuleRbkmoneySetting']['type'] !== 'select') {
                continue;
            }

            $settings[$key]['ModuleRbkmoneySetting']['options'] = $this->getSelectOptions(
                $settings[$key]['ModuleRbkmoneySetting']['code']
            );
        }

        return $settings;
    }

    /**
     * @param string | null $activePage
     */
    public function admin_index($activePage = null)
    {
        if (empty($activePage)) {
            $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_SETTINGS);
        }

        $this->set('current_crumb', __d(RBK_MONEY_MODULE, 'RBK_MONEY'));
        $this->set('title_for_layout', __d(RBK_MONEY_MODULE, 'RBK_MONEY'));
        $this->set('activePage', $activePage);
        $this->set('pages', [
            RBK_MONEY_PAGE_SETTINGS => 'RBK_MONEY_SETTINGS',
            RBK_MONEY_PAGE_TRANSACTIONS => 'RBK_MONEY_TRANSACTIONS',
            RBK_MONEY_PAGE_RECURRENT => 'RBK_MONEY_RECURRENT',
            RBK_MONEY_PAGE_RECURRENT_ITEMS => 'RBK_MONEY_RECURRENT_ITEMS',
            RBK_MONEY_PAGE_LOGS => 'RBK_MONEY_LOGS',
        ]);

        switch ($activePage) {
            case RBK_MONEY_PAGE_SETTINGS:
                $this->set('rbkmoneySettings', $this->getSettings());
                break;
            case RBK_MONEY_PAGE_RECURRENT:
                $this->set('rbkmoneyRecurrent', $this->ModuleRbkmoneyRecurrent->find('all'));
                break;
            case RBK_MONEY_PAGE_TRANSACTIONS:
                try {
                    $transactions = $this->getTransactions();
                } catch (RBKmoneyException $exception) {
                    if ($exception->getCode() === HTTP_CODE_UNAUTHORIZED) {
                        $error = __d(RBK_MONEY_MODULE, 'RBK_MONEY_FORBIDDEN_MESSAGE');
                    } else {
                        $error = $exception->getMessage();
                    }

                    $transactions = ['error' => $error];
                }

                $this->set('rbkmoneyTransactions', $transactions);
                break;
            case RBK_MONEY_PAGE_RECURRENT_ITEMS:
                $this->set('rbkmoneyRecurrentItems', $this->getRecurrentItems());
                break;
            case RBK_MONEY_PAGE_LOGS:
                $this->set('rbkmoneyLogs', $this->getLogs());
        }
    }

    /**
     * @return void
     */
    public function delete_logs()
    {
        $logger = new Logger();

        if ($logger->deleteLog()) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_LOGS_DELETED'));
        } else {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_LOGS_DELETE_ERROR'), false);
        }

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_LOGS);
    }

    /**
     * @return void
     */
    public function download_logs()
    {
        $logger = new Logger();

        $logger->downloadLog();

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_LOGS);
    }

    /**
     * @return string
     */
    private function getLogs()
    {
        $logger = new Logger();

        return $logger->getLog();
    }

    /**
     * @return string
     */
    private function getRecurrentItems()
    {
        $items = $this->ModuleRbkmoneyRecurrentItem->find('all');

        $result = '';

        if (empty($items)) {
            return $result;
        }

        foreach ($items as $item) {
            $result .= $item['ModuleRbkmoneyRecurrentItem']['article'] . PHP_EOL;
        }

        return trim($result);
    }

    /**
     * @throws WrongRequestException
     */
    public function capturePayment()
    {
        if (empty($this->data['invoiceId']) || empty($this->data['paymentId'])) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_CAPTURE_ERROR'), false);

            $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS);
        }

        $capturePayment = new CapturePaymentRequest(
            $this->data['invoiceId'],
            $this->data['paymentId'],
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_CAPTURED_BY_ADMIN')
        );

        $client = new Client($this->settings['apiKey'], $this->settings['shopId'], RBK_MONEY_API_URL_SETTING);
        $sender = new Sender($client);

        try {
            $sender->sendCapturePaymentRequest($capturePayment);
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_CONFIRMED'));
        } catch (RequestException $exception) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_CAPTURE_ERROR'), false);
        }

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS);
    }

    /**
     * @throws WrongRequestException
     */
    public function cancelPayment()
    {
        if (empty($this->data['invoiceId']) || empty($this->data['paymentId'])) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_CANCELLED_ERROR'), false);

            $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS);
        }

        $capturePayment = new CancelPaymentRequest(
            $this->data['invoiceId'],
            $this->data['paymentId'],
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_CANCELLED_BY_ADMIN')
        );

        $client = new Client($this->settings['apiKey'], $this->settings['shopId'], RBK_MONEY_API_URL_SETTING);
        $sender = new Sender($client);

        try {
            $sender->sendCancelPaymentRequest($capturePayment);
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_CANCELLED'));
        } catch (RequestException $exception) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_PAYMENT_CANCELLED_ERROR'), false);
        }

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS);
    }

    /**
     * @throws WrongDataException
     * @throws WrongRequestException
     */
    public function createRefund()
    {
        if (empty($this->data['invoiceId']) || empty($this->data['paymentId'])) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_REFUND_CREATE_ERROR'));

            $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS);
        }

        $capturePayment = new CreateRefundRequest(
            $this->data['invoiceId'],
            $this->data['paymentId'],
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_REFUNDED_BY_ADMIN')
        );

        $client = new Client($this->settings['apiKey'], $this->settings['shopId'], RBK_MONEY_API_URL_SETTING);
        $sender = new Sender($client);

        try {
            $sender->sendCreateRefundRequest($capturePayment);
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_REFUND_CREATED'));
        } catch (RequestException $exception) {
            $this->Session->setFlash(__d(RBK_MONEY_MODULE, 'RBK_MONEY_REFUND_CREATE_ERROR'), false);
        }

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS);
    }

    /**
     * @param int $limit
     *
     * @return array
     *
     * @throws WrongDataException
     * @throws WrongRequestException
     * @throws RequestException
     */
    private function getTransactions($limit = 10)
    {
        if (empty($this->settings['apiKey'])) {
            throw new WrongDataException(__d(RBK_MONEY_MODULE, 'RBK_MONEY_ERROR_API_KEY_IS_NOT_VALID', HTTP_CODE_BAD_REQUEST));
        }
        if (empty($this->settings['shopId'])) {
            throw new WrongDataException(__d(RBK_MONEY_MODULE, 'RBK_MONEY_ERROR_API_KEY_IS_NOT_VALID', HTTP_CODE_BAD_REQUEST));
        }

        $page = (empty($_GET['page']) || $_GET['page'] < 1) ? 1 : $_GET['page'];

        if (!empty($_POST['date_from'])) {
            $dateFrom = new DateTime($_POST['date_from']);
        } elseif (!empty($_GET['date_from'])) {
            $dateFrom = new DateTime($_GET['date_from']);
        } else {
            $dateFrom = new DateTime('today');
        }

        if (!empty($_POST['date_to'])) {
            $dateTo = new DateTime($_POST['date_to']);
        } elseif (!empty($_GET['date_to'])) {
            $dateTo = new DateTime($_GET['date_to']);
        } else {
            $dateTo = new DateTime();
            $dateTo->setTime(23, 59, 59);
        }

        $today = new DateTime();
        if ($dateFrom->getTimestamp() > $dateTo->getTimestamp() || $dateFrom->getTimestamp() > $today->getTimestamp()) {
            $dateFrom = new DateTime('today');
        }
        if ($dateFrom->getTimestamp() >= $dateTo->getTimestamp()) {
            $dateTo = new DateTime();
            $dateTo = $dateTo->setTime(23, 59, 59);
        }

        $shopId = $this->settings['shopId'];

        $sender = new Sender(new Client($this->settings['apiKey'], $shopId, RBK_MONEY_API_URL_SETTING));

        $paymentRequest = new SearchPaymentsRequest($shopId, $dateFrom, $dateTo, $limit);
        $paymentRequest->setOffset(($page * $limit) - $limit);

        $payments = $sender->sendSearchPaymentsRequest($paymentRequest);

        $statuses = [
            'started' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_STARTED'),
            'processed' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_PROCESSED'),
            'captured' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_CAPTURED'),
            'cancelled' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_CANCELLED'),
            'charged back' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_CHARGED_BACK'),
            'refunded' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_REFUNDED'),
            'failed' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_STATUS_FAILED'),
        ];

        $transactions = [];

        /**
         * @var $payment Payment
         */
        foreach ($payments->result as $payment) {
            $invoiceRequest = new GetInvoiceByIdRequest($payment->invoiceId);
            $invoice = $sender->sendGetInvoiceByIdRequest($invoiceRequest);
            $metadata = $invoice->metadata->metadata;
            $transactions[] = [
                'orderId' => $metadata['orderId'],
                'invoiceId' => $invoice->id,
                'paymentId' => $payment->id,
                'product' => $invoice->product,
                'flowStatus' => $payment->flow->type,
                'paymentStatus' => $payment->status->getValue(),
                'status' => $statuses[$payment->status->getValue()],
                'amount' => number_format($payment->amount / 100, 2, '.', ''),
                'createdAt' => $payment->createdAt->format(FULL_DATE_FORMAT),
            ];
        }

        $pagePath = RBK_MONEY_PAGE_TRANSACTIONS . '?page=(:num)';
        $date = "date_from={$dateFrom->format('d.m.Y')}&date_to={$dateTo->format('d.m.Y')}";

        $paginator = new Paginator($payments->totalCount, $limit, $page, "$pagePath&$date");

        return [
            'transactions' => $transactions,
            'previousUrl' => $paginator->getPrevUrl(),
            'nextUrl' => $paginator->getNextUrl(),
            'pages' => $paginator->getPages(),
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
        ];
    }

    /**
     * @param string $code
     *
     * @return array
     */
    private function getSelectOptions($code)
    {
        switch ($code) {
            case 'paymentType':
                $options = [
                    'RBK_MONEY_PAYMENT_TYPE_HOLD',
                    'RBK_MONEY_PAYMENT_TYPE_INSTANTLY',
                ];
                break;
            case 'holdExpiration':
                $options = [
                    'RBK_MONEY_EXPIRATION_PAYER',
                    'RBK_MONEY_EXPIRATION_SHOP',
                ];
                break;
            case 'shadingCvv':
            case 'cardHolder':
            case 'saveLogs':
                $options = [
                    'RBK_MONEY_SHOW_PARAMETER',
                    'RBK_MONEY_NOT_SHOW_PARAMETER',
                ];
                break;
            case 'fiscalization':
                $options = [
                    'RBK_MONEY_PARAMETER_USE',
                    'RBK_MONEY_PARAMETER_NOT_USE',
                ];
                break;
            case 'vatRate':
            case 'deliveryVatRate':
                $options = TaxMode::$validValues;
                $options[] = __d(RBK_MONEY_MODULE, 'RBK_MONEY_PARAMETER_NOT_USE');
                break;
            case 'successStatus':
            case 'holdStatus':
            case 'cancelStatus':
            case 'refundStatus':
                $language = $this->Language->find('first', ['conditions' => ['active' => 1]]);
                $statuses = $this->OrderStatusDescription->find('all', [
                    'conditions' => [
                        'language_id' => $language['Language']['id']
                    ]
                ]);
                $options = [];

                foreach ($statuses as $status) {
                    $options[] = $status['OrderStatusDescription']['name'];
                }
                break;
            default:
                $options = [];
        }

        return $this->getOptions($options);
    }

    /**
     * @param array $options
     *
     * @return array
     */
    private function getOptions($options)
    {
        $result = [];

        foreach ($options as $option) {
            $result[__d(RBK_MONEY_MODULE, $option)] = __d(RBK_MONEY_MODULE, $option);
        }

        return $result;
    }

    /**
     * @return void
     */
    public function admin_save()
    {
        if (!empty($this->data)) {
            foreach ($this->data['settingsForm'] as $key => $value) {
                $currentConfig = $this->ModuleRbkmoneySetting->find('first', ['conditions' => ['code' => $key]]);
                $currentConfig['ModuleRbkmoneySetting']['value'] = $value;
                $this->ModuleRbkmoneySetting->save($currentConfig);
            }

            $this->Session->setFlash(__('Record saved.', true));
        }

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_SETTINGS);
    }

    public function save_recurrent_items()
    {
        if (!empty($this->data)) {
            $ids = array_map(function($value) {
                return trim($value);
            }, explode(PHP_EOL, $this->data['settingsForm']['recurrentIds']));

            $this->deleteAllRecurrentItems();

            foreach ($ids as $id) {
                $newItem = $this->ModuleRbkmoneyRecurrentItem->create([
                    'article' => $id
                ]);
                $this->ModuleRbkmoneyRecurrentItem->save($newItem);
            }

            $this->Session->setFlash(__('Record saved.', true));
        }

        $this->redirect('/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_RECURRENT_ITEMS);
    }

    /**
     * @return void
     */
    private function deleteAllRecurrentItems()
    {
        $items = $this->ModuleRbkmoneyRecurrentItem->find('all');

        foreach ($items as $item) {
            $this->ModuleRbkmoneyRecurrentItem->delete(current($item)['id']);
        }
    }

}