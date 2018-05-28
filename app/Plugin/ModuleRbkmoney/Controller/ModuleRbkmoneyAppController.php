<?php

App::uses('AppController', 'Controller');

class ModuleRbkmoneyAppController extends AppController
{
    /**
     * @var array 
     */
    public $uses = [
        'ModuleRbkmoneySetting',
        'ModuleRbkmoneyRecurrent',
        'ModuleRbkmoneyInvoice',
        'ModuleRbkmoneyRecurrentCustomer',
        'ModuleRbkmoneyRecurrentItem',
        'OrderStatusDescription',
        'PaymentMethod',
        'Module',
        'Language',
    ];

    /**
     * @var array
     */
    public $components = ['ModuleBase'];
}
