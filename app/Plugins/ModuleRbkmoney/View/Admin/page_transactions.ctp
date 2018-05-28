<?php

if (!empty($rbkmoneyTransactions['error'])) {
    $content = $rbkmoneyTransactions['error'];
} else {
    $today = new DateTime();
    $content = $this->Form->create('settingsForm', [
        'id' => 'settingsForm',
        'url' => '/module_rbkmoney/admin/admin_index/' . RBK_MONEY_PAGE_TRANSACTIONS,
    ]);

    $content .= '<div class="input date">
                             <label for="date_from" style="width: 4%">' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_DATE_FILTER_FROM') . '</label>
                             <input type="date" id="date_from" name="date_from" value="' . $rbkmoneyTransactions['dateFrom'] . '"
                             max="' . $today->format('Y-m-d') . '">
                             </div>';

    $content .= '<div class="input date">
                             <label for="date_to" style="width: 4%">' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_DATE_FILTER_TO') . '</label>
                             <input type="date" id="date_to" name="date_to" value="' . $rbkmoneyTransactions['dateTo'] . '"
                             max="' . $today->format('Y-m-d') . '">
                             </div>';

    $content .= '<div style="height: 40px"><button class="btn btn-primary" type="submit" name="submit">
                             </i> ' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_FILTER_SUBMIT') . '</button></div>';

    $content .= $this->Form->end();

    $content .= '<table class="contentTable">';

    $content .= $this->Html->tableHeaders([
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_TRANSACTION_ID'),
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_TRANSACTION_PRODUCT'),
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_TRANSACTION_STATUS'),
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_TRANSACTION_AMOUNT'),
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_TRANSACTION_CREATED_AT'),
            '',
        ]);

    foreach ($rbkmoneyTransactions['transactions'] as $transaction) {
        $button = '';
        $statusHold = \src\Api\Payments\PaymentResponse\Flow::HOLD;
        $statusCaptured = \src\Api\Status::CAPTURED;
        $statusProcessed = \src\Api\Status::PROCESSED;
        $action = '/module_rbkmoney/admin/';

        if ($statusProcessed === $transaction['paymentStatus'] && $statusHold === $transaction['flowStatus']) {
            $button = '<form action="' . $action . 'capturePayment" method="POST">
                                <input type="hidden" name="date_from" value="' . $rbkmoneyTransactions['dateFrom'] . '">
                                <input type="hidden" name="date_to" value="' . $rbkmoneyTransactions['dateTo'] . '">
                                <input type="hidden" name="invoiceId" value="' . $transaction['invoiceId'] . '">
                                <input type="hidden" name="paymentId" value="' . $transaction['paymentId'] . '">
                                <button type="submit" style="height: 30px">' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_CONFIRM_PAYMENT') . '</button>
                          </form><br>';
            $button .= '<form action="' . $action . 'cancelPayment" method="POST">
                                <input type="hidden" name="date_from" value="' . $rbkmoneyTransactions['dateFrom'] . '">
                                <input type="hidden" name="date_to" value="' . $rbkmoneyTransactions['dateTo'] . '">
                                <input type="hidden" name="invoiceId" value="' . $transaction['invoiceId'] . '">
                                <input type="hidden" name="paymentId" value="' . $transaction['paymentId'] . '">
                                <button type="submit" style="height: 30px">' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_CANCEL_PAYMENT') . '</button>
                          </form>';
        } elseif ($statusCaptured === $transaction['paymentStatus']) {
            $button = '<form action="' . $action . 'createRefund" method="POST">
                                <input type="hidden" name="date_from" value="' . $rbkmoneyTransactions['dateFrom'] . '">
                                <input type="hidden" name="date_to" value="' . $rbkmoneyTransactions['dateTo'] . '">
                                <input type="hidden" name="invoiceId" value="' . $transaction['invoiceId'] . '">
                                <input type="hidden" name="paymentId" value="' . $transaction['paymentId'] . '">
                                <button type="submit" style="height: 30px">' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_CREATE_PAYMENT_REFUND') . '</button>
                          </form>';
        }

        $content .= $this->Admin->TableCells([
                $transaction['orderId'],
                $transaction['product'],
                $transaction['status'],
                $transaction['amount'],
                $transaction['createdAt'],
                $button,
            ]);
    }

    $content .= '</table><table><tr>';

    if (!empty($this->previousUrl)) {
        $content .= '<td><a href="' . $rbkmoneyTransactions['previousUrl'] . '"><<' . __d(RBK_MONEY_MODULE, 'RBK_MONEY_PREVIOUS') . '</a></td>';
    }

    foreach ($rbkmoneyTransactions['pages'] as $page) {
        if ($page['isCurrent'] || '...' === $page['num']) {
            $content .= '<td>' . $page['num'] . '</td>';
        } else {
            $content .= '<td><a href="' . $page['url'] . '">' . $page['num'] . '</a></td>';
        }
    }

    if (!empty($this->nextUrl)) {
        $content .= '<td><a href="' . $rbkmoneyTransactions['nextUrl'] . '">' . __d(RBK_MONEY_MODULE, 'NEXT') . ' >></a></td>';
    }

    $content .= '</tr></table>';
}

echo $content;