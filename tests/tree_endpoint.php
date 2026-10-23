<?php
declare(strict_types=1);
// Isolated CGI fixture. Never deploy. Paths and digests come from the test process.
$package = getenv('NWM_TEST_PACKAGE'); $root = getenv('NWM_TEST_ROOT');
require $package . '/src/Maintenance/TreeOperations.php';
require $package . '/src/Maintenance/ResumableTree.php';
require $package . '/src/Transport/BackupEndpoint.php';
$e = new Nicode\Plugin\System\NicodeWebMonitor\Transport\BackupEndpoint(
    getenv('NWM_TEST_SITE'), getenv('NWM_TEST_DIGEST'), getenv('NWM_TEST_INVENTORY'), $root . '/store', $root . '/source', true);
[$status, $body] = $e->handle($_SERVER, $_GET, file_get_contents('php://input', false, null, 0, 4097),
    function ($id) use ($root) {
        if (!is_file($root . '/armed')) { throw new RuntimeException('Not armed'); }
        $r = (new Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableTree($root . '/store/trees'))->step($root . '/source', $id, true);
        if ($id === 'crash-after-step') { throw new RuntimeException('Injected lost journal receipt'); }
        return $r;
    });
http_response_code($status);
header('Content-Type: application/json'); header('Cache-Control: no-store, private');
echo json_encode($body, JSON_THROW_ON_ERROR);
