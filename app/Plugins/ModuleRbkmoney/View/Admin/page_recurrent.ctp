<?php

$recurrentContent = '<table class="contentTable">';

$recurrentContent .= $this->Html->tableHeaders([
    __d(RBK_MONEY_MODULE, 'RBK_MONEY_USER_FIELD'),
    __d(RBK_MONEY_MODULE, 'RBK_MONEY_AMOUNT_FIELD'),
    __d(RBK_MONEY_MODULE, 'RBK_MONEY_PRODUCT_FIELD'),
    __d(RBK_MONEY_MODULE, 'RBK_MONEY_TRANSACTION_STATUS'),
    __d(RBK_MONEY_MODULE, 'RBK_MONEY_RECURRENT_CREATE_DATE'),
    '',
]);

foreach ($rbkmoneyRecurrent as $recurrentItem) {
    $recurrent = $recurrentItem['ModuleRbkmoneyRecurrent'];
    $recurrentContent .= $this->Admin->TableCells([
        $recurrent['recurrent_customer_id'],
        $recurrent['amount'],
        $recurrent['name'],
        $recurrent['status'],
        $recurrent['date'],
        $this->Admin->ActionButton(
            'recurrent_delete',
            "/module_rbkmoney/admin/recurrent_delete/{$recurrent['id']}",
            __d(RBK_MONEY_MODULE, 'RBK_MONEY_FORM_BUTTON_DELETE')
        ),
    ]);
}

$recurrentContent .= '</table>';

echo $recurrentContent;