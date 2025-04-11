<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
require dirname(__DIR__) . '/vendor/autoload.php';

class SyncServer implements MessageComponentInterface {
    public function onOpen(ConnectionInterface $conn) {
        echo "Cliente conectado\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Aquí puedes manejar mensajes del frontend si necesitas bidireccionalidad
    }

    public function onClose(ConnectionInterface $conn) {
        echo "Cliente desconectado\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

$server = \Ratchet\Server\IoServer::factory(
    new \Ratchet\Http\HttpServer(
        new \Ratchet\WebSocket\WsServer(
            new SyncServer()
        )
    ),
    8080
);
$server->run();
