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

                $buffer = '';
                while (null !== $chunk = yield $socket->read()) {
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, PHP_EOL)) !== false) {
                        $this->broadcast($remoteAddr . ' says: ' . substr($buffer, 0, $pos) . PHP_EOL);
                        $buffer = substr($buffer, $pos + 1);
                    }
                }

                unset($this->clients[(string)$remoteAddr]);

                echo "Client disconnected: {$remoteAddr}" . PHP_EOL;
                $this->broadcast($remoteAddr . ' left the chat.' . PHP_EOL);
            });
        }

        public function handleMessage(Socket $socket, string $message): void
        {
            if ($message === '') {
                return;
            }

            if (strpos($message, '/') === 0) {
                $message = substr($message, 1);
                $args = explode(' ', $message);
                $name = strtolower(array_shift($args));

                switch ($name) {
                    case 'time':
                        $socket->write(date("l js \of F Y h:i:s A") . PHP_EOL);
                        break;
                    case 'up':
                        $socket->write(strtoupper(implode(' ', $args)) . PHP_EOL);
                        break;
                    case 'down':
                        $socket->write(strtolower(implode(' ', $args)) . PHP_EOL);
                        break;
                    default:
                        $socket->write("Unknown command: {$name}" . PHP_EOL);
                }

                return;
            }

            $this->broadcast($socket->getRemoteAddress() . ' says: ' . $message . PHP_EOL);
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
