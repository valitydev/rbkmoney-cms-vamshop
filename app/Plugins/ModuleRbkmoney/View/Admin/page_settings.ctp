<?php

$content = $this->Form->create('settingsForm', [
    'id' => 'settingsForm',
    'url' => '/module_rbkmoney/admin/admin_save',
]);

foreach ($rbkmoneySettings as $element) {
    $setting = current($element);
    if ($setting['code'] === 'publicKey') {
        continue;
    }

    $content .= $this->Form->input($setting['code'], [
        'label' => __d(RBK_MONEY_MODULE, $setting['name']),
        'type' => $setting['type'],
        'options' => (!isset($setting['options']) ? null : $setting['options']),
        'value' => $setting['value'],
    ]);
}

$content .= $this->Admin->formButton(__d(RBK_MONEY_MODULE, 'RBK_MONEY_SAVE'), 'cus-tick', [
    'class' => 'btn btn-primary',
    'type' => 'submit',
    'name' => 'submit'
]);

echo $content;