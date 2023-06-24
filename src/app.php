<?php
// @codingStandardsIgnoreLine

use DI\Container;
use Error;
use Exception;
use InterNations\Component\HttpMock\RequestStorage;
use InterNations\Component\HttpMock\StatusCode;
use InterNations\Component\HttpMock\Util;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

$autoloadFiles = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
];

$autoloaderFound = false;

foreach ($autoloadFiles as $autoloadFile) {
    if (file_exists($autoloadFile)) {
        require_once $autoloadFile;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    throw new RuntimeException(sprintf('Could not locate autoloader file. Tried "%s"', implode('", "', $autoloadFiles)));
}

// Create the container with the APP
$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

// Define Custom Error Handler - https://stackoverflow.com/questions/57648078/replacement-for-notfoundhandler-setting
$customErrorHandler = function (
    Psr\Http\Message\ServerRequestInterface $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app, $container) {
    $response = $app->getResponseFactory()->createResponse();
    if ($exception instanceof HttpNotFoundException) {
        $this->get('storage')->append(
            $request,
            'requests',
            serialize(
                [
                    'request' => Util::serializePsrMessage($request),
                    'server' => $request->getServerParams(),
                ]
            )
        );

        $notFoundResponse = $response->withStatus(StatusCode::HTTP_NOT_FOUND);

        $expectations = $this->get('storage')->read($request, 'expectations');

        foreach ($expectations as $pos => $expectation) {
            foreach ($expectation['matcher'] as $matcher) {
                if (!$matcher($request)) {
                    continue 2;
                }
            }

            if (isset($expectation['limiter']) && !$expectation['limiter']($expectation['runs'])) {
                if ($notFoundResponse->getStatusCode() != StatusCode::HTTP_GONE) {
                    $notFoundResponse = Util::writeToResponse(
                        $response->withStatus(StatusCode::HTTP_GONE),
                        'Expectation no longer applicable'
                    );
                }
                continue;
            }

            ++$expectations[$pos]['runs'];
            $this->get('storage')->store($request, 'expectations', $expectations);

            $r = Util::responseDeserialize($expectation['response']);
            if (!empty($expectation['responseCallback'])) {
                $callback = $expectation['responseCallback'];

                return $callback($r);
            }

            return $r;
        }

        if ($notFoundResponse->getStatusCode() == StatusCode::HTTP_NOT_FOUND) {
            $notFoundResponse = Util::writeToResponse($notFoundResponse, 'No matching expectation found');
        }

        return $notFoundResponse;
    }

    return Util::writeToResponse(
        $response->withStatus(500)->withHeader('Content-Type', 'text/plain'),
        $exception->getMessage()."\n".$exception->getTraceAsString()."\n"
    );
};

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setErrorHandler(\Slim\Exception\HttpNotFoundException::class, $customErrorHandler);
$errorMiddleware->setErrorHandler(\Throwable::class, $customErrorHandler);

// Create the storage object
$storage = new RequestStorage(getmypid(), __DIR__.'/../state/');

// Set the storage object in the container
$container->set('storage', $storage);

$app->delete(
    '/_expectation',
    function (Request $request, Response $response) {
        $this->get('storage')->clear($request, 'expectations');

        return $response->withStatus(200);
    }
);


$app->post(
    '/_expectation',
    function (Request $request, Response $response) {
        $data = json_decode($request->getBody()->getContents(), true);
        $matcher = [];

        if (!empty($data['matcher'])) {
            $matcher = Util::silentDeserialize($data['matcher']);
            $validator = function ($closure) {
                return is_callable($closure);
            };

            if (!is_array($matcher) || count(array_filter($matcher, $validator)) !== count($matcher)) {
                return Util::writeToResponse(
                    $response->withStatus(StatusCode::HTTP_EXPECTATION_FAILED),
                    'POST data key "matcher" must be a serialized list of closures'
                );
            }
        }

        if (empty($data['response'])) {
            return Util::writeToResponse(
                $response->withStatus(StatusCode::HTTP_EXPECTATION_FAILED),
                'POST data key "response" not found in POST data'
            );
        }

        try {
            $responseToSave = Util::responseDeserialize($data['response']);
        } catch (Exception $e) {

            return Util::writeToResponse(
                $response->withStatus(StatusCode::HTTP_EXPECTATION_FAILED),
                'POST data key "response" must be an http response message in text form'
            );
        }

        $limiter = null;

        if (!empty($data['limiter'])) {
            $limiter = Util::silentDeserialize($data['limiter']);

            if (!is_callable($limiter)) {
                return Util::writeToResponse(
                    $response->withStatus(StatusCode::HTTP_EXPECTATION_FAILED),
                    'POST data key "limiter" must be a serialized closure'
                );
            }
        }

        // Fix issue with silex default error handling
        // not sure if this is need anymore
        $response = $response->withHeader('X-Status-Code', $response->getStatusCode());

        $responseCallback = null;
        if (!empty($data['responseCallback'])) {
            $responseCallback = Util::silentDeserialize($data['responseCallback']);

            if ($responseCallback !== null && !is_callable($responseCallback)) {
                return Util::writeToResponse(
                    $response->withStatus(StatusCode::HTTP_EXPECTATION_FAILED),
                    'POST data key "responseCallback" must be a serialized closure: '
                    .print_r($data['responseCallback'], true)
                );
            }
        }

        $this->get('storage')->prepend(
            $request,
            'expectations',
            [
                'matcher' => $matcher,
                'response' => $data['response'],
                'limiter' => $limiter,
                'responseCallback' => $responseCallback,
                'runs' => 0,
            ]
        );

        return $response->withStatus(StatusCode::HTTP_CREATED);
    }
);

$app->get(
    '/_request/count',
    function (Request $request, Response $response) {
        $count = count($this->get('storage')->read($request, 'requests'));

        return Util::writeToResponse(
            $response->withStatus(StatusCode::HTTP_OK)->withHeader('Content-Type', 'text/plain'),
            $count
        );
    }
);

$app->get(
    '/_request/{index:[0-9]+}',
    function (Request $request, Response $response, $args) {
        $index = (int)$args['index'];
        $requestData = $this->get('storage')->read($request, 'requests');

        if (!isset($requestData[$index])) {
            return Util::writeToResponse(
                $response->withStatus(StatusCode::HTTP_NOT_FOUND),
                'Index '.$index.' not found'
            );
        }

        return Util::writeToResponse(
            $response->withStatus(StatusCode::HTTP_OK)->withHeader('Content-Type', 'text/plain'),
            $requestData[$index]
        );

    }
);

$app->delete(
    '/_request/{action:last|latest|first}',
    function (Request $request, Response $response, $args) {
        $action = $args['action'];

        $requestData = $this->get('storage')->read($request, 'requests');
        $fn = 'array_'.($action === 'last' || $action === 'latest' ? 'pop' : 'shift');
        $requestString = $fn($requestData);
        $this->get('storage')->store($request, 'requests', $requestData);

        if (!$requestString) {
            return Util::writeToResponse(
                $response->withStatus(StatusCode::HTTP_NOT_FOUND),
                $action.' not possible'
            );
        }

        return Util::writeToResponse($response->withStatus(StatusCode::HTTP_OK), $requestString)
            ->withHeader('Content-Type', 'text/plain');
    }
);

$app->get(
    '/_request/{action:last|latest|first}',
    function (Request $request, Response $response, $args) {
        $action = $args['action'];
        $requestData = $this->get('storage')->read($request, 'requests');
        $fn = 'array_'.($action === 'last' || $action === 'latest' ? 'pop' : 'shift');
        $requestString = $fn($requestData);

        if (!$requestString) {
            return Util::writeToResponse(
                $response->withStatus(
                    StatusCode::HTTP_NOT_FOUND,

                ), $action.' not available'
            );
        }

        return Util::writeToResponse(
            $response->withStatus(StatusCode::HTTP_OK)->withHeader('Content-Type', 'text/plain'),
            $requestString
        );
    }
);

$app->delete(
    '/_request',
    function (Request $request, Response $response) {
        $this->get('storage')->store($request, 'requests', []);

        return $response->withStatus(StatusCode::HTTP_OK);
    }
);

$app->delete(
    '/_all',
    function (Request $request, Response $response) {
        $this->get('storage')->store($request, 'requests', []);
        $this->get('storage')->store($request, 'expectations', []);

        return $response->withStatus(StatusCode::HTTP_OK);
    }
);

$app->get(
    '/_me',
    function (Request $request, Response $response) {
        return Util::writeToResponse(
            $response->withStatus(StatusCode::HTTP_IM_A_TEAPOT),
            "O RLY?"
        )->withHeader('Content-Type', 'text/plain');
    }
);

return $app;