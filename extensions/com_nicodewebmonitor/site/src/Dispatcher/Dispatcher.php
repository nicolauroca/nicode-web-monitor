<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Site\Dispatcher;
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\PairingService;

final class Dispatcher extends \Joomla\CMS\Dispatcher\ComponentDispatcher
{
    public function dispatch()
    {
        $status=400; $response=['ok'=>false,'error'=>'connection_failed'];
        if ($this->input->getCmd('task')!=='connection.claim') { $status=404; }
        elseif (!in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)) { $status=403; }
        elseif ($this->input->getMethod()!=='POST') { $status=405; $this->app->setHeader('Allow','POST',true); }
        else {
            try {
                if (strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json') throw new \RuntimeException();
                $raw=file_get_contents('php://input',false,null,0,4097);
                if ($raw===false || strlen($raw)>4096) throw new \RuntimeException();
                $payload=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new \RuntimeException();
                $response=(new PairingService(Factory::getContainer()->get(DatabaseInterface::class),new CredentialVault($this->app->get('secret'))))->claim($payload);
                $status=200;
            } catch (\Throwable $e) {
                $response['error']=in_array($e->getMessage(),['code_unavailable','verification_failed'],true) ? $e->getMessage() : 'connection_failed';
            }
        }
        $this->app->setHeader('Status',(string)$status,true);
        http_response_code($status);
        $this->app->setHeader('Content-Type','application/json; charset=utf-8',true);
        $this->app->setHeader('Cache-Control','no-store, private',true);
        $this->app->setHeader('Referrer-Policy','no-referrer',true);
        $this->app->sendHeaders();
        echo json_encode($response,JSON_THROW_ON_ERROR); $this->app->close();
    }
}
