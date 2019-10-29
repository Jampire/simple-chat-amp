<?php

require_once __DIR__ . '/vendor/autoload.php';

use Amp\Loop;
use Amp\Socket\Socket;
use Amp\Socket\Server;
use function Amp\asyncCall;

Loop::run(static function () {
    $server = new class
    {
        private $uri = 'tcp://127.0.0.1:1337';

        // $clientAddr => $client
        private $clients = [];

        public function listen(): void
        {
            asyncCall(function () {
                $server = Server::listen($this->uri);

                echo 'Listening on ', $server->getAddress(), ' ...', PHP_EOL;

                while ($socket = yield $server->accept()) {
                    $this->handleClient($socket);
                }
            });
        }

        public function handleClient(Socket $socket): void
        {
            asyncCall(static function () use ($socket) {
                $remoteAddr = $socket->getRemoteAddress();

                echo "Accepted new client: {$remoteAddr}" . PHP_EOL;
                $this->broadcast($remoteAddr . ' joined the chat.' . PHP_EOL);

                $this->clients[(string)$remoteAddr] = $socket;

                while (null !== $chunk = yield $socket->read()) {
                    $this->broadcast($remoteAddr . ' says: ' . trim($chunk) . PHP_EOL);
                }

                unset($this->clients[(string)$remoteAddr]);

                echo "Client disconnected: {$remoteAddr}" . PHP_EOL;
                $this->broadcast($remoteAddr . ' left the chat.' . PHP_EOL);
            });
        }

        private function broadcast(string $message): void
        {
            /** @var Socket $client */
            foreach ($this->clients as $client) {
                $client->write($message);
            }
        }
    };

    $server->listen();
});
