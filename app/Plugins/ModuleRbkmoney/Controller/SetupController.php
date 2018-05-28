<?php

class SetupController extends ModuleRbkmoneyAppController
{

    /**
     * @var string
     */
    private $moduleName = 'Rbkmoney';

    /**
     * @var string
     */
    private $alias = 'rbkmoney';

    /**
     * @param CakeRequest | null  $request
     * @param CakeResponse | null $response
     */
    public function __construct($request = null, $response = null)
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/app/Plugin/ModuleRbkmoney/src/settings.php';

        parent::__construct($request, $response);
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
            'name' => Inflector::humanize($this->moduleName),
            'icon' => 'rbkmoney.png',
            'alias' => $this->moduleName,
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

        $this->redirect('/modules/admin/');
    }

    /**
     * @return void
     */
    public function uninstall()
    {
        $paymentMethod = $this->PaymentMethod->findByAlias($this->moduleName);
        $module = $this->Module->find('first', ['conditions' => ['alias' => $this->alias]]);

        $this->PaymentMethod->delete($paymentMethod['PaymentMethod']['id'], true);
        $this->Module->delete($module['Module']['id'], true);

        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_invoices`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrents`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrent_customers`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_recurrent_items`');
        $this->PaymentMethod->query('DROP TABLE IF EXISTS `module_rbkmoney_settings`');

        $this->Session->setFlash(__('Module Uninstalled'));

        $this->redirect('/modules/admin/');
    }

}