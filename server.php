<?php

require_once __DIR__ . '/vendor/autoload.php';

use Amp\Loop;
use Amp\Socket\Socket;
use Amp\Socket\Server;
use function Amp\asyncCall;

Loop::run(function () {
    $server = new class
    {
        private $uri = 'tcp://127.0.0.1:1337';

        // $clientAddr => $client
        private $clients = [];

        private $usernames = [];

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
            asyncCall(function () use ($socket) {
                $remoteAddr = $socket->getRemoteAddress();

                echo "Accepted new client: {$remoteAddr}" . PHP_EOL;
                $this->broadcast($remoteAddr . ' joined the chat.' . PHP_EOL);

                $this->clients[(string)$remoteAddr] = $socket;

                $buffer = '';
                while (null !== $chunk = yield $socket->read()) {
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, PHP_EOL)) !== false) {
                        $this->handleMessage($socket, substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);
                    }
                }

                $user = $this->getUsername($socket);
                unset($this->clients[(string)$remoteAddr], $this->usernames[(string)$remoteAddr]);

                echo "Client disconnected: {$remoteAddr}" . PHP_EOL;
                $this->broadcast("{$user} left the chat." . PHP_EOL);
            });
        }

        public function handleMessage(Socket $socket, string $message): void
        {
            if ($message === '') {
                return;
            }

            $shouldReturn = true;

            if (strpos($message, '/') === 0) {
                $message = substr($message, 1);
                $args = explode(' ', $message);
                $name = strtolower(array_shift($args));

                switch ($name) {
                    case 'time':
                        $socket->write(date("l js \of F Y h:i:s A") . PHP_EOL);
                        break;
                    case 'up':
                        $message = strtoupper(implode(' ', $args));
                        $shouldReturn = false;
                        $socket->write($message . PHP_EOL);
                        break;
                    case 'down':
                        $message = strtolower(implode(' ', $args));
                        $shouldReturn = false;
                        $socket->write($message . PHP_EOL);
                        break;
                    case 'exit':
                        $socket->end('Bye.' . PHP_EOL);
                        break;
                    case 'nick':
                        $nick = implode(' ', $args);

                        if (!preg_match('/^[a-z0-9-.]{3,15}$/i', $nick)) {
                            $error = 'Username must only contain letters, digits and ' .
                                'its length must be between 3 and 15 characters.';
                            $socket->write($error . PHP_EOL);
                            return;
                        }

                        $oldNick = $this->getUsername($socket);
                        $this->setUsername($socket, $nick);

                        $this->broadcast($oldNick . ' is now ' . $nick . PHP_EOL);
                        break;
                    default:
                        $socket->write("Unknown command: {$name}" . PHP_EOL);
                }

                if ($shouldReturn) {
                    return;
                }
            }

            $this->broadcast($this->getUsername($socket) . ' says: ' . $message . PHP_EOL);
        }

        private function broadcast(string $message): void
        {
            /** @var Socket $client */
            foreach ($this->clients as $client) {
                $client->write($message);
            }
        }

        private function setUsername(Socket $socket, string $name): void
        {
            $this->usernames[(string)$socket->getRemoteAddress()] = $name;
        }

        private function getUsername(Socket $socket): string
        {
            $remoteAddr = (string)$socket->getRemoteAddress();
            return $this->usernames[$remoteAddr] ?? $remoteAddr;
        }
    };

    $server->listen();
});
