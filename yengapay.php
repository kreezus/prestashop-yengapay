<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kreezus
 * @copyright Since 2024 Kreezus
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class YengaPay extends PaymentModule
{
    const CONFIG_GROUP_ID = 'YENGAPAY_GROUP_ID';
    const CONFIG_API_KEY = 'YENGAPAY_API_KEY';
    const CONFIG_PROJECT_ID = 'YENGAPAY_PROJECT_ID';
    const CONFIG_WEBHOOK_URL = 'YENGAPAY_WEBHOOK_URL';
    const CONFIG_WEBHOOK_SECRET = 'YENGAPAY_WEBHOOK_SECRET';

    public function __construct()
    {
        $this->name = 'yengapay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Kreezus';
        $this->need_instance = 1;

        $this->ps_versions_compliancy = [
            'min' => '1.7.6.0',
            'max' => _PS_VERSION_,
        ];

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('YengaPay');
        $this->description = $this->l('Accept payments via YengaPay mobile money and other local payment methods.');

        // Vérifie si les informations obligatoires sont configurées
        if (!$this->checkApiCredentials()) {
            $this->warning = $this->l('The YengaPay API credentials must be configured before using this module.');
        }

        // Générer l'URL du webhook
        $shopUrl = Tools::getShopDomainSsl(true);
        $this->webhook_url = $shopUrl . '/module/' . $this->name . '/webhook';
    
        // Sauvegarder l'URL du webhook dans la configuration
        if (!Configuration::get(self::CONFIG_WEBHOOK_URL)) {
            Configuration::updateValue(self::CONFIG_WEBHOOK_URL, $this->webhook_url);
        }
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Installation des hooks nécessaires
        if (!$this->registerHook('paymentOptions') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('displayAdminOrderLeft')
        ) {
            return false;
        }

        // Installation des configurations par défaut
        if (!$this->installConfiguration()) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // Suppression des configurations
        if (!$this->uninstallConfiguration() ||
            !parent::uninstall()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Installation des configurations par défaut
     */
    private function installConfiguration()
    {
        return Configuration::updateValue(self::CONFIG_GROUP_ID, '') &&
            Configuration::updateValue(self::CONFIG_API_KEY, '') &&
            Configuration::updateValue(self::CONFIG_PROJECT_ID, '') &&
            Configuration::updateValue(self::CONFIG_WEBHOOK_URL, $this->webhook_url) &&
            Configuration::updateValue(self::CONFIG_WEBHOOK_SECRET, '');
    }

    /**
     * Suppression des configurations
     */
    private function uninstallConfiguration()
    {
        return Configuration::deleteByName(self::CONFIG_GROUP_ID) &&
            Configuration::deleteByName(self::CONFIG_API_KEY) &&
            Configuration::deleteByName(self::CONFIG_PROJECT_ID) &&
            Configuration::deleteByName(self::CONFIG_WEBHOOK_URL) &&
            Configuration::deleteByName(self::CONFIG_WEBHOOK_SECRET);
    }

    /**
     * Vérifie si les credentials API sont configurés
     */
    private function checkApiCredentials()
    {
        return !empty(Configuration::get(self::CONFIG_GROUP_ID)) &&
            !empty(Configuration::get(self::CONFIG_API_KEY)) &&
            !empty(Configuration::get(self::CONFIG_PROJECT_ID));
    }

    /**
     * Hook pour afficher les options de paiement
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkApiCredentials()) {
            return [];
        }

        $payment_options = [];

        $newOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $newOption->setModuleName($this->name)
                ->setCallToActionText($this->l('Payer avec YengaPay'))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
                ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/yengapay_icon.png'))
                ->setAdditionalInformation('<style>.payment-option label img { max-width: 50px; max-height: 50px; object-fit: contain; margin-left: 10px; vertical-align: middle; }</style>');

        $payment_options[] = $newOption;

        return $payment_options;
    }
    

    /**
     * Configuration du module dans le back-office
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $group_id = Tools::getValue(self::CONFIG_GROUP_ID);
            $api_key = Tools::getValue(self::CONFIG_API_KEY);
            $project_id = Tools::getValue(self::CONFIG_PROJECT_ID);
            $webhook_secret = Tools::getValue(self::CONFIG_WEBHOOK_SECRET);

            if (empty($group_id)) {
                $output .= $this->displayError($this->l('Group ID is required.'));
            } elseif (empty($api_key)) {
                $output .= $this->displayError($this->l('API Key is required.'));
            } elseif (empty($project_id)) {
                $output .= $this->displayError($this->l('Project ID is required.'));
            } else {
                Configuration::updateValue(self::CONFIG_GROUP_ID, $group_id);
                Configuration::updateValue(self::CONFIG_API_KEY, $api_key);
                Configuration::updateValue(self::CONFIG_PROJECT_ID, $project_id);
                Configuration::updateValue(self::CONFIG_WEBHOOK_SECRET, $webhook_secret);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output . $this->renderForm();
    }

    /**
     * Génération du formulaire de configuration
     */
    protected function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('YengaPay Configuration'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    // Section Identification
                    [
                        'type' => 'html',
                        'name' => 'identification_header',
                        'html_content' => '<div class="form-section"><h3>' . $this->l('Account Identification') . '</h3></div>'
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Group ID'),
                        'name' => self::CONFIG_GROUP_ID,
                        'required' => true,
                        'desc' => $this->l('Enter the Group ID provided in your YengaPay dashboard settings'),
                        'class' => 'fixed-width-xxl',
                        'prefix' => '<i class="icon-group"></i>',
                        'hint' => $this->l('You can find this in your YengaPay account settings'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Project ID'),
                        'name' => self::CONFIG_PROJECT_ID,
                        'required' => true,
                        'desc' => $this->l('Enter the Project ID found in your YengaPay project settings'),
                        'class' => 'fixed-width-xxl',
                        'prefix' => '<i class="icon-folder"></i>',
                        'hint' => $this->l('Located in your project dashboard'),
                    ],
    
                    // Section Sécurité
                    [
                        'type' => 'html',
                        'name' => 'security_header',
                        'html_content' => '<div class="form-section"><h3>' . $this->l('Security Settings') . '</h3></div>'
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('API Key'),
                        'name' => self::CONFIG_API_KEY,
                        'required' => true,
                        'desc' => $this->l('Enter your YengaPay API Key used for secure API authentication'),
                        'class' => 'fixed-width-xxl',
                        'prefix' => '<i class="icon-key"></i>',
                        'suffix' => '<i class="icon-eye show-password" title="' . $this->l('Show/Hide Password') . '"></i>',
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Webhook Secret'),
                        'name' => self::CONFIG_WEBHOOK_SECRET,
                        'required' => true,
                        'desc' => $this->l('Used to verify webhook notifications from YengaPay'),
                        'class' => 'fixed-width-xxl',
                        'prefix' => '<i class="icon-lock"></i>',
                        'suffix' => '<i class="icon-eye show-password" title="' . $this->l('Show/Hide Password') . '"></i>',
                    ],
    
                    // Section Webhook
                    [
                        'type' => 'html',
                        'name' => 'webhook_header',
                        'html_content' => '<div class="form-section"><h3>' . $this->l('Webhook Configuration') . '</h3></div>'
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Webhook URL'),
                        'name' => self::CONFIG_WEBHOOK_URL,
                        'readonly' => true,
                        'desc' => $this->l('Copy this URL into your YengaPay dashboard to receive payment notifications.'),
                        'class' => 'fixed-width-xxl',
                        'prefix' => '<i class="icon-link"></i>',
                        'suffix' => '<button type="button" class="btn btn-default copy-to-clipboard" data-clipboard-target="#' . self::CONFIG_WEBHOOK_URL . '"><i class="icon-copy"></i> ' . $this->l('Copy') . '</button>',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-primary pull-right',
                    'icon' => 'process-icon-save',
                ],
            ],
        ];
    
        // Ajout du CSS personnalisé
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        // Ajout du JavaScript personnalisé
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
    
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
    
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit' . $this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
    
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];
    
        return $helper->generateForm([$fields_form]);
    }
    
    protected function getConfigFieldsValues()
    {
        return [
            self::CONFIG_GROUP_ID => Tools::getValue(self::CONFIG_GROUP_ID, Configuration::get(self::CONFIG_GROUP_ID)),
            self::CONFIG_API_KEY => Tools::getValue(self::CONFIG_API_KEY, Configuration::get(self::CONFIG_API_KEY)),
            self::CONFIG_PROJECT_ID => Tools::getValue(self::CONFIG_PROJECT_ID, Configuration::get(self::CONFIG_PROJECT_ID)),
            self::CONFIG_WEBHOOK_URL => Tools::getValue(self::CONFIG_WEBHOOK_URL, Configuration::get(self::CONFIG_WEBHOOK_URL)),
            self::CONFIG_WEBHOOK_SECRET => Tools::getValue(self::CONFIG_WEBHOOK_SECRET, Configuration::get(self::CONFIG_WEBHOOK_SECRET)),
        ];
    }
}