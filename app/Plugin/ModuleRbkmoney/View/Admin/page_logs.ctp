<?php

$content = $this->Form->input(
    '',
    [
        'type' => 'textarea',
        'value' => $rbkmoneyLogs,
    ]
);

$content .= $this->Form->create('settingsForm', [
    'id' => 'settingsForm',
    'url' => '/module_rbkmoney/admin/delete_logs',
]);

$content .= $this->Admin->formButton(__d(RBK_MONEY_MODULE, 'RBK_MONEY_DELETE_LOGS'), null, [
    'class' => 'btn btn-primary',
    'type' => 'submit',
    'name' => 'submit',
]);

$content .= "{$this->Form->end()}<br>";

$content .= $this->Form->create('settingsForm', [
    'id' => 'settingsForm',
    'url' => '/module_rbkmoney/admin/download_logs',
]);

$content .= $this->Admin->formButton(__d(RBK_MONEY_MODULE, 'RBK_MONEY_DOWNLOAD_LOGS'), null, [
    'class' => 'btn btn-primary',
    'type' => 'submit',
    'name' => 'submit',
]);

$content .= $this->Form->end();

echo $content;