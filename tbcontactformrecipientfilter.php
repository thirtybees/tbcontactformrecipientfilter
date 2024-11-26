<?php
/**
 * Copyright (C) 2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2024 thirty bees
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * Class TbContactFormIPBlock
 */
class TbContactFormRecipientFilter extends Module
{
    const RULE_TYPE_CONTAINS = 'contains';
    const RULE_TYPE_STARTS_WITH = 'starts_with';
    const RULE_TYPE_ENDS_WITH = 'ends_with';

    /**
     * @var array|null
     */
    protected $rules = null;

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'tbcontactformrecipientfilter';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'thirty bees';

        $this->controllers = [];
        $this->bootstrap = true;
        $this->need_instance = false;
        $this->tb_min_version = '1.6.0';
        $this->tb_versions_compliancy = '>= 1.6.0';

        parent::__construct();
        $this->displayName = $this->l('Contact Form Recipient Email Address Filter');
        $this->description = $this->l('Contact Form Recipient Email Address Filter');
    }

    /**
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function reset()
    {
        return (
            $this->uninstall(false) &&
            $this->install(false)
        );
    }

    /**
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function uninstall($full = true)
    {
        if ($full) {
            $this->uninstallDb($full);
        }
        return parent::uninstall();
    }

    /**
     * @param bool $drop
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    private function uninstallDb($drop)
    {
        if (!$drop) {
            return true;
        }
        return $this->executeSqlScript('uninstall', false);
    }

    /**
     * @param string $script
     * @param bool $check
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function executeSqlScript($script, $check = true)
    {
        $file = dirname(__FILE__) . '/sql/' . $script . '.sql';
        if (!file_exists($file)) {
            return false;
        }
        $sql = file_get_contents($file);
        if (!$sql) {
            return false;
        }
        $sql = str_replace([
            'PREFIX_',
            'ENGINE_TYPE',
            'CHARSET_TYPE',
            'COLLATE_TYPE',
            'ENUM_VALUES_ROLE_TYPES',
        ], [
            _DB_PREFIX_,
            _MYSQL_ENGINE_,
            'utf8mb4',
            'utf8mb4_unicode_ci',
            $this->getEnumValues($this->getRuleTypes()),
        ], $sql);
        $sql = preg_split("/;\s*[\r\n]+/", $sql);
        foreach ($sql as $statement) {
            $stmt = trim($statement);
            if ($stmt) {
                try {
                    if (!Db::getInstance()->execute($stmt)) {
                        PrestaShopLogger::addLog($this->name . ": sql script $script: $stmt: error");
                        if ($check) {
                            return false;
                        }
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog($this->name . ": sql script $script: $stmt: $e");
                    if ($check) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Install this module
     *
     * @return bool Whether the module has been successfully installed
     *
     * @throws PrestaShopException
     */
    public function install($full = true)
    {
        return (
            parent::install() &&
            $this->installDb($full) &&
            $this->registerHook('actionValidateContactFormMessage')
        );
    }

    /**
     * @param bool $create
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    private function installDb($create)
    {
        if (!$create) {
            return true;
        }
        return (
        $this->executeSqlScript('install')
        );
    }

    /**
     * @param array $params
     *
     * @return array
     *
     * @throws PrestaShopException
     */
    public function hookActionValidateContactFormMessage($params)
    {
        /** @var Contact $contact */
        $contact = $params['contact'];
        $ts = date("Y-m-d H:i:s");
        $email = (string)$params['from']['email'];
        $rules = $this->getRules();

        $blocked = false;
        foreach ($rules as $rule) {
            $id = (int)$rule['id_cfrf_recipient_rule'];
            $type = (string)$rule['type'];
            $rule = (string)$rule['rule'];
            if ($this->ruleMatches($email, $type, $rule)) {
                $this->registerActivity($id, $ts);
                $blocked = true;
            }
        }

        if ($blocked) {
            return $this->returnError($contact);
        }
        // everything is ok
        return [];
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    protected function getRules()
    {
        if (is_null($this->rules)) {
            $this->rules = Db::getInstance()->getArray((new DbQuery())
                ->from('cfrf_recipient_rule')
            );
        }
        return $this->rules;
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        /** @var AdminController $controller */
        $controller = $this->context->controller;
        if (Tools::isSubmit('deleteRule')) {
            Db::getInstance()->delete('cfrf_recipient_rule', 'id_cfrf_recipient_rule = ' . Tools::getIntValue('deleteRule'));
            $controller->confirmations[] = $this->l('Filter rule has been deleted');
            $controller->setRedirectAfter(Context::getContext()->link->getAdminLink('AdminModules', true, ['configure' => $this->name]));
        }
        if (Tools::isSubmit('addRule')) {
            $rule = Tools::getValue('rule');
            $type = Tools::getValue('type');
            if ($rule && $type) {
                Db::getInstance()->insert('cfrf_recipient_rule', [
                    'type' => pSQL($type),
                    'rule' => pSQL($rule),
                ]);
                $controller->confirmations[] = $this->l('Filter rule has been created');
                $controller->setRedirectAfter(Context::getContext()->link->getAdminLink('AdminModules', true, ['configure' => $this->name]));
            }
        }

        return (
            $this->renderAddIpAddressForm() .
            $this->renderRulesList()
        );
    }

    /**
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderAddIpAddressForm()
    {
        $ruleTypes = [];
        foreach ($this->getRuleTypes() as $type) {
            $ruleTypes[] = [
                'type' => $type,
                'name' => $this->getRuleTypeName($type),
            ];
        }
        $formFields = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Add filter rule'),
                    'icon' => 'icon-lock',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Type'),
                        'name' => 'type',
                        'required' => true,
                        'options' => [
                            'query' => $ruleTypes,
                            'id' => 'type',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Rule'),
                        'name' => 'rule',
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Add rule'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'addRule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        /** @var AdminController $controller */
        $controller = $this->context->controller;
        $helper->tpl_vars = [
            'fields_value' => [
                'type' => 'contains',
                'rule' => '',
            ],
            'languages' => $controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$formFields]);
    }

    /**
     * @return false|string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderRulesList()
    {
        $sql = (new DbQuery())
            ->select('rr.id_cfrf_recipient_rule, rr.type, rr.rule')
            ->select('MIN(a.ts) as first_seen')
            ->select('MAX(a.ts) as last_seen')
            ->select('SUM(CASE WHEN a.id_cfrf_recipient_rule_activity IS NULL THEN 0 ELSE 1 END) as cnt_total')
            ->select('SUM(CASE WHEN a.ts > DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as cnt_last_day')
            ->from('cfrf_recipient_rule', 'rr')
            ->leftJoin('cfrf_recipient_rule_activity', 'a', '(rr.id_cfrf_recipient_rule = a.id_cfrf_recipient_rule)')
            ->groupBy('rr.id_cfrf_recipient_rule')
            ->orderBy('rr.id_cfrf_recipient_rule');

        $conn = Db::getInstance();
        $data = $conn->getArray($sql);
        $total = count($data);

        $fieldsList = [
            'type' => [
                'title' => $this->l('Type'),
                'callback_object' => $this,
                'callback' => 'getRuleTypeName',
            ],
            'rule' => [
                'title' => $this->l('Rule'),
            ],
            'first_seen' => [
                'title' => $this->l('First Seen'),
                'type' => 'datetime',
            ],
            'last_seen' => [
                'title' => $this->l('Last Seen'),
                'type' => 'datetime',
            ],
            'cnt_total' => [
                'title' => $this->l('Requests (total)'),
            ],
            'cnt_last_day' => [
                'title' => $this->l('Requests (last day)'),
            ],
            'id_cfrf_recipient_rule' => [
                'title' => $this->l('Actions'),
                'callback_object' => $this,
                'callback' => 'renderDeleteAction'
            ]
        ];

        $helper = new HelperList();
        $helper->no_link = true;
        $helper->simple_header = true;
        $helper->shopLinkType = '';
        $helper->actions = [];
        $helper->show_toolbar = true;
        $helper->module = $this;
        $helper->listTotal = $total;
        $helper->identifier = 'id_cfrf_recipient_rule';
        $helper->title = $this->l('Filter Rules');
        $helper->table = 'cfrf_recipient_rule';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        return $helper->generateList($data, $fieldsList);
    }

    /**
     *
     * @param int $ruleId
     * @param string $ts
     *
     * @return void
     * @throws PrestaShopException
     */
    protected function registerActivity(int $ruleId, string $ts)
    {
        $conn = Db::getInstance();
        $conn->insert('cfrf_recipient_rule_activity', [
            'id_cfrf_recipient_rule' => (int)$ruleId,
            'ts' => $ts,
        ]);
    }

    /**
     * @param Contact $contact
     *
     * @return array
     */
    protected function returnError(Contact $contact): array
    {
        return [
            sprintf(
                $this->l("Your email address is blocked from sending messages through contact form. Please send email to %s instead"),
                $contact->email
            )
        ];
    }

    /**
     * @param string $email
     * @param string $type
     * @param string $rule
     *
     * @return bool
     */
    protected function ruleMatches(string $email, string $type, string $rule): bool
    {
        switch ($type) {
            case static::RULE_TYPE_CONTAINS:
                return $this->strContains($email, $rule);
            case static::RULE_TYPE_STARTS_WITH:
                return $this->strStartsWith($email, $rule);
            case static::RULE_TYPE_ENDS_WITH:
                return $this->strEndsWith($email, $rule);
            default:
                return false;
        }
    }

    /**
     * @param string $type
     * @return string
     */
    public function getRuleTypeName(string $type): string
    {
        switch ($type) {
            case static::RULE_TYPE_CONTAINS:
                return $this->l('Contains');
            case static::RULE_TYPE_STARTS_WITH:
                return $this->l('Starts with');
            case static::RULE_TYPE_ENDS_WITH:
                return $this->l('Ends with');
            default:
                return $type;
        }
    }

    /**
     * @return string[]
     */
    protected function getRuleTypes(): array
    {
        return [
            static::RULE_TYPE_CONTAINS,
            static::RULE_TYPE_STARTS_WITH,
            static::RULE_TYPE_ENDS_WITH,
        ];
    }

    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    protected function strStartsWith(string $haystack, string $needle): bool
    {
        return mb_strlen($needle) === 0 || mb_strpos($haystack, $needle) === 0;
    }

    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    protected function strContains(string $haystack, string $needle): bool
    {
        return mb_strpos($haystack, $needle) !== false;
    }

    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    protected function strEndsWith(string $haystack, string $needle): bool
    {
        return mb_strlen($needle) === 0 || mb_substr($haystack, - mb_strlen($needle)) === $needle;
    }

    /**
     * @param array $values
     * @return string
     */
    private function getEnumValues(array $values): string
    {
        return "'" . implode("', '", $values) . "'";
    }

    /**
     * @param int $id
     *
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function renderDeleteAction($id)
    {
        $link = Context::getContext()->link->getAdminLink('AdminModules', true, [
            'deleteRule' => $id,
            'configure' => $this->name,
        ]);
        $label = $this->l('Delete');
        return '<a href="' . $link . '" ><i class="icon-delete"></i> ' . $label . '</a>';
    }
}


