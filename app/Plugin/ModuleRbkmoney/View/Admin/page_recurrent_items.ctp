<?php

$content = $this->Form->create('settingsForm', [
    'id' => 'settingsForm',
    'url' => '/module_rbkmoney/admin/save_recurrent_items'
]);

$content .= $this->Form->input(
    'recurrentIds',
    [
        'label' => __d(RBK_MONEY_MODULE, 'RBK_MONEY_ITEM_IDS'),
        'type' => 'textarea',
        'value' => $rbkmoneyRecurrentItems,
    ]
);

$content .= $this->Admin->formButton(__d(RBK_MONEY_MODULE, 'RBK_MONEY_SAVE'), 'cus-tick', [
    'class' => 'btn btn-primary',
    'type' => 'submit',
    'name' => 'submit'
]);

echo $content;