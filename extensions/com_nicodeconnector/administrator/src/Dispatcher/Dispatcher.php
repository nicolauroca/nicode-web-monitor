<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeConnector\Administrator\Dispatcher;
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeConnector\Administrator\Service\Connection;
use Nicode\Component\NicodeConnector\Administrator\Service\CredentialVault;

final class Dispatcher extends \Joomla\CMS\Dispatcher\ComponentDispatcher
{
    public function dispatch()
    {
        if (!$this->app->isClient('administrator')) throw new \RuntimeException('Access denied',403);
        $this->checkAccess(); $user=$this->app->getIdentity();
        $connection=new Connection(Factory::getContainer()->get(DatabaseInterface::class),new CredentialVault($this->app->get('secret')));
        $task=$this->input->getCmd('task','display');
        if ($task!=='display') {
            if ($this->input->getMethod()!=='POST' || !Session::checkToken('post') || !$user->authorise('core.admin','com_nicodeconnector') || $user->guest || $user->block) throw new \RuntimeException('Access denied',403);
            try {
                if ($task==='saveSharing') {
                    $connection->saveSharing($user,$this->input->post->get('sharing',[],'array'));
                    $this->app->enqueueMessage('Permisos de consulta guardados. Se aplican desde la próxima consulta del central.');
                    $this->app->redirect('index.php?option=com_nicodeconnector&sharing=1#nwm-sharing');return;
                } elseif ($task==='connect') {
                    if (!in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)) throw new \RuntimeException('https_required');
                    $connection->connect($this->input->post->getString('central_url'),$this->input->post->getString('code'),rtrim(Uri::root(),'/'));
                    $this->app->enqueueMessage('Conectado. El panel central ha verificado este sitio y guardado su primer inventario.');
                } elseif ($task==='disconnect' && $this->input->post->getString('confirmed')==='1') {
                    $connection->disconnect(); $this->app->enqueueMessage('Acceso de inventario revocado. Puedes eliminar el registro del panel central.');
                } else { throw new \RuntimeException('Invalid request'); }
            } catch (\Throwable $e) {
                $messages=['code_unavailable'=>'El código no es válido, ha caducado o ya se ha utilizado. Cancela el intento y genera otro en el central.',
                    'verification_failed'=>'El central no ha podido comprobar este sitio. Comprueba HTTPS, el acceso público y que el servidor permita la cabecera Authorization.',
                    'public_address_required'=>'La conexión necesita una dirección pública. No se admiten direcciones de red privada.',
                    'dns_failed'=>'No se ha podido resolver el dominio del central.',
                    'invalid_url'=>'Introduce la dirección HTTPS del Joomla central, sin /administrator ni parámetros.',
                    'tls_verification_failed'=>'El certificado HTTPS del central no se ha podido validar.',
                    'redirect_rejected'=>'El central redirige a otra dirección. Utiliza su dirección HTTPS definitiva.',
                    'central_auth_required'=>'La ruta de vinculación del central pide autenticación de inventario. Actualiza el paquete Central y, si está instalado allí, el paquete Conector de esta misma versión de desarrollo.',
                    'central_access_denied'=>'El central ha rechazado la vinculación (HTTP 403). Comprueba las restricciones de acceso o firewall del alojamiento.',
                    'central_component_missing'=>'No se encuentra la ruta de vinculación (HTTP 404). Comprueba la URL e instala el paquete Nicode Web Monitor — Central completo.',
                    'central_server_error'=>'El Joomla central ha devuelto un error de servidor. Revisa su registro de errores y que ambos paquetes estén actualizados.',
                    'connection_failed'=>'No se ha podido contactar con el central por HTTPS. Comprueba la URL, DNS y las conexiones salientes del hosting.',
                    'invalid_response'=>'El central no ha devuelto la respuesta JSON esperada. Comprueba si hay una pantalla de acceso, protección del hosting o versiones distintas de los paquetes.',
                    'connection_uncertain'=>'El central no ha confirmado la petición a tiempo. Repite el mismo intento antes de que caduque el código, o cancélalo para revocar el acceso.',
                    'connection_busy'=>'Ya hay otra conexión o desconexión en curso. Espera a que termine antes de repetir.',
                    'https_required'=>'Abre la administración de este Joomla mediante HTTPS.',
                    'pending_connection'=>'Hay una conexión pendiente. Repite el mismo código o cancela el intento antes de usar otro.',
                    'already_connected'=>'Este Joomla ya está conectado. Desconéctalo antes de cambiar de central.',
                    'package_incomplete'=>'Las piezas instaladas no son de la misma versión. Instala los ZIP completos Nicode Web Monitor — Central y Nicode Web Monitor — Conector de esta prerelease; no los ZIP internos individuales.'];
                $known=isset($messages[$e->getMessage()]);
                $this->app->enqueueMessage(($messages[$e->getMessage()]??'No se ha podido completar la vinculación por un error interno. Comprueba las versiones instaladas y el registro de errores de Joomla.') . ' [NWM: '.($known?$e->getMessage():'internal_error').']','error');
            }
            $this->app->redirect('index.php?option=com_nicodeconnector'); return;
        }
        $state=$connection->state();
        $sharing=$connection->sharing();
        $sharingCategories=\Nicode\Component\NicodeConnector\Administrator\Service\InventorySchema::categories();
        $this->app->setHeader('Cache-Control','no-store, private',true);
        require JPATH_ADMINISTRATOR.'/components/com_nicodeconnector/tmpl/connection.php';
    }
}
