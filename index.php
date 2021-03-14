<?php
define("_SFTPGO", 1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/configuration.php';
require __DIR__ . '/functions.php';

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\StreamingParser;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

// Run this script, then visit http://localhost:9001/ in your browser (or whatever port you have set in configuration.php).

Amp\Loop::run(function () {
    global $port;

    $port = (int) $port;

    if ($port < 0) {
        $port = 9001; // Default Port:
    }

    $servers = [
        Socket\Server::listen("0.0.0.0:" . $port),
        Socket\Server::listen("[::]:" . $port),
    ];

    $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $server = new HttpServer($servers, new CallableRequestHandler(function (Request $request) {
        $remoteIP = $request->getClient()->getRemoteAddress()->getHost();
        $data = yield $request->getBody()->buffer();

        if (isAllowedIP($remoteIP)) {
            return authenticateUser($data);
        } else {
            return denyRequest();
        }

    }), $logger);

    yield $server->start();

    // Doesn't seem to work on Windows:

    // Stop the server gracefully when SIGINT is received.
    // This is technically optional, but it is best to call Server::stop().
    //Amp\Loop::onSignal(\SIGINT, function (string $watcherId) use ($server) {
    //    Amp\Loop::cancel($watcherId);
    //    yield $server->stop();
    //});
});