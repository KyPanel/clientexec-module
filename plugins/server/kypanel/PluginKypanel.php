<?php

require_once 'modules/admin/models/ServerPlugin.php';
require_once 'plugins/server/kypanel/lib/api.php';

// KyPanel server plugin for Clientexec
class PluginKypanel extends ServerPlugin
{
    public $features = [
        'packageName' => true,
        'testConnection' => true,
        'showNameservers' => false,
        'directlink' => true,
        'upgrades' => true,
    ];

    public function getVariables()
    {
        return [
            'Name' => [
                'type' => 'hidden',
                'description' => 'Used by CE to show plugin',
                'value' => 'KyPanel',
            ],
            'Description' => [
                'type' => 'hidden',
                'description' => 'Description viewable by admin in server settings',
                'value' => 'Sell and manage hosting accounts on KyPanel servers.',
            ],
            'API Token' => [
                'type' => 'password',
                'description' => 'Ky Admin › Development › Manage API Tokens, with "accounts" (read and change) and "packages" (read).',
                'value' => '',
                'encryptable' => true,
            ],
            'Port' => [
                'type' => 'text',
                'description' => 'KyPanel API port',
                'value' => '7711',
            ],
            'Verify SSL' => [
                'type' => 'yesno',
                'description' => 'Verify the server certificate. Turn off only for a new server that still has a self-signed one.',
                'value' => '1',
            ],
            'Actions' => [
                'type' => 'hidden',
                'description' => 'Current actions that are active for this plugin per server',
                'value' => 'Create,Delete,Suspend,UnSuspend',
            ],
            'Registered Actions For Customer' => [
                'type' => 'hidden',
                'description' => 'Current actions that are active for this plugin per server for customers',
                'value' => '',
            ],
            'package_addons' => [
                'type' => 'hidden',
                'description' => 'Supported signup addons variables',
                'value' => [],
            ],
            'package_vars' => [
                'type' => 'hidden',
                'description' => 'Whether package settings are set',
                'value' => '0',
            ],
        ];
    }

    public function validateCredentials($args)
    {
    }

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->create($this->buildParams($userPackage));
        return 'Package has been created.';
    }

    public function doUpdate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->update($this->buildParams($userPackage, $args));
        return 'Package has been updated.';
    }

    public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->suspend($this->buildParams($userPackage));
        return 'Package has been suspended.';
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->unsuspend($this->buildParams($userPackage));
        return 'Package has been unsuspended.';
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->delete($this->buildParams($userPackage));
        return 'Package has been deleted.';
    }

    public function create($args)
    {
        $this->run($args, function (KypanelApi $api) use ($args) {
            $api->createAccount(
                $this->username($args),
                $args['package']['domain_name'],
                $args['package']['password'],
                $args['customer']['email'] ?? '',
                $args['package']['name_on_server'] ?? '',
                !empty($args['package']['is_reseller'])
            );
        });
    }

    public function update($args)
    {
        $this->run($args, function (KypanelApi $api) use ($args) {
            $username = $this->username($args);
            foreach ($args['changes'] ?? [] as $key => $value) {
                switch ($key) {
                    case 'password':
                        $api->changePassword($username, $value);
                        break;
                    case 'package':
                        $api->changePackage($username, $args['package']['name_on_server'] ?? '');
                        break;
                    case 'username':
                    case 'domain':
                    case 'ip':
                        throw new CE_Exception('KyPanel doesn\'t support changing the ' . $key . ' from here. Change it in Ky Admin.');
                }
            }
        });
    }

    public function suspend($args)
    {
        $this->run($args, function (KypanelApi $api) use ($args) {
            $api->suspend($this->username($args), 'Suspended by billing');
        });
    }

    public function unsuspend($args)
    {
        $this->run($args, function (KypanelApi $api) use ($args) {
            $api->unsuspend($this->username($args));
        });
    }

    public function delete($args)
    {
        $this->run($args, function (KypanelApi $api) use ($args) {
            $api->terminate($this->username($args));
        });
    }

    public function testConnection($args)
    {
        CE_Lib::log(4, 'Testing connection to KyPanel');
        $this->run($args, function (KypanelApi $api) {
            $api->testConnection();
        });
    }

    public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        if (trim($args['package']['username'] ?? '') === '') {
            return ['Create'];
        }
        try {
            $account = $this->api($args)->account($args['package']['username']);
        } catch (Exception $e) {
            return ['Create'];
        }
        $actions = ['Delete'];
        $actions[] = ($account['status'] ?? '') === 'suspended' ? 'UnSuspend' : 'Suspend';
        return $actions;
    }

    // the sign-in link lasts 60 seconds, so the real one is only made when it's opened
    public function getDirectLink($userPackage, $getRealLink = true, $fromAdmin = false, $isReseller = false)
    {
        $args = $this->buildParams($userPackage);
        $reseller = $isReseller && !empty($args['package']['is_reseller']);
        $linkText = $this->user->lang($reseller ? 'Login to Ky Admin' : 'Login to KyPanel');

        if ($fromAdmin) {
            return [
                'cmd' => $reseller ? 'panellogin_reseller' : 'panellogin',
                'label' => $linkText,
            ];
        }
        if ($getRealLink) {
            $url = '';
            $this->run($args, function (KypanelApi $api) use ($args, $reseller, &$url) {
                $url = $api->loginUrl($this->username($args), $reseller ? 'admin' : 'user');
            });
            return [
                'fa' => 'fa fa-user fa-fw',
                'link' => $url,
                'rawlink' => $url,
                'text' => $linkText,
                'form' => '',
            ];
        }

        $link = 'index.php?fuse=clients&controller=products&action=openpackagedirectlink&packageId='
            . $userPackage->getId() . '&sessionHash=' . CE_Lib::getSessionHash();
        if ($reseller) {
            $link .= '&isReseller=1';
        }
        return [
            'fa' => 'fa fa-user fa-fw',
            'link' => $link,
            'text' => $linkText,
            'form' => '',
        ];
    }

    public function dopanellogin($args)
    {
        $response = $this->getDirectLink(new UserPackage($args['userPackageId']));
        return $response['link'];
    }

    public function dopanellogin_reseller($args)
    {
        $response = $this->getDirectLink(new UserPackage($args['userPackageId']), true, false, true);
        return $response['link'];
    }

    private function api($args)
    {
        $vars = $args['server']['variables'];
        return new KypanelApi(
            $vars['ServerHostName'] ?? '',
            $vars['plugin_kypanel_API_Token'] ?? '',
            $vars['plugin_kypanel_Port'] ?? 7711,
            !empty($vars['plugin_kypanel_Verify_SSL'])
        );
    }

    // runs $fn with an API client; KyPanel errors are shown to the admin/client
    private function run($args, callable $fn)
    {
        try {
            $fn($this->api($args));
        } catch (CE_Exception $e) {
            throw $e;
        } catch (Exception $e) {
            CE_Lib::log(1, 'KyPanel: ' . $e->getMessage());
            throw new CE_Exception('KyPanel: ' . $e->getMessage());
        }
    }

    private function username($args)
    {
        $username = strtolower(trim($args['package']['username'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9]{0,15}$/', $username)) {
            throw new CE_Exception('The KyPanel username must start with a letter and have only lowercase letters and digits, 16 at most.');
        }
        return $username;
    }
}
