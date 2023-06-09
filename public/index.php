<?php

namespace Hexlet\Code;

use DI\ContainerBuilder;
use Hexlet\Helpers\Checker;
use Hexlet\Helpers\Normalize;
use Postgre;
use Postgre\Connection;
use Postgre\InsertValue;
use Postgre\Select;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Valitron\Validator as V;

$autoloadPath1 = __DIR__ . '/../../../autoload.php';
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(
    [
        'flash' => function () {
            $storage = [];
            return new Messages($storage);
        }
    ]
);


AppFactory::setContainer($containerBuilder->build());

$app = AppFactory::create();
$app->add(
    function ($request, $next) {
        // Start PHP session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Change flash message storage
        $this->get('flash')->__construct($_SESSION);

        return $next->handle($request);
    }
);

$app->addErrorMiddleware(true, true, true);


$twig = Twig::create(__DIR__ . '/../templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));


$app->get('/', function (Request $request, Response $response) {
    $view = Twig::fromRequest($request);

    return $view->render($response, 'index.html.twig', ['headerMainActive' => 'active']);
})->setName('main');


$app->get('/urls', function (Request $request, Response $response) {
    $view = Twig::fromRequest($request);

    $connection = Connection::get()->connect();

    $urlList = Select::prepareAllUrls($connection);
    $params = [
        'urlList' => $urlList,
        'headerSitesActive' => 'active'
    ];
    return $view->render($response, 'urls.html.twig', $params);
})->setName('urls');

$app->get('/urls/{id}', function (Request $request, Response $response, $args) {
    $view = Twig::fromRequest($request);

    $flash = $this->get('flash')->getMessages();
    if (isset($flash)) {
        $flashClass = isset($flash['error']) ? 'danger' : 'success';
    }

    $id = $args['id'];

    $connection = Connection::get()->connect();

    $checks = Select::selectAllChecks($connection, $id);
    $siteParamsList = Select::selectUrlById($connection, $id);
    $params = [
        'checks' => $checks,
        'siteParamsList' => $siteParamsList,
        'flash' => $flash,
        'flashClass' => $flashClass ?? ''
    ];
    return $view->render($response, 'url-id.html.twig', $params);
})->setName('url');




$router = $app->getRouteCollector()->getRouteParser();


$app->post('/urls', function (Request $request, Response $response) use ($router) {
    $view = Twig::fromRequest($request);

    $url = $request->getParsedBodyParam('url')['name'];

    $validation = new V(['url' => $url]);

    $validation->rule('required', 'url');
    $validation->rule('lengthMax', 'url', 255);
    $validation->rule('url', 'url');


    if (!$validation->validate()) {
        $params = [
            'inputClass' => 'is-invalid',
            'inputValue' => htmlspecialchars($url)
        ];
        $response->withStatus(422);
        return $view->render($response, 'index.html.twig', $params);
    }
    $normalizedUrl = Normalize::normalizeUrl($url);

    $connection = Connection::get()->connect();
    $existingUrls = Select::selectUrlByName($connection, $normalizedUrl);
    $this->get('flash')->addMessage('success', 'Страница уже существует');

    if (count($existingUrls) === 0) {
        $insert = new InsertValue($connection);
        $lastInsertId = $insert->insertValue('urls', $normalizedUrl);
        $this->get('flash')->clearMessage('success');
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    }
    $urlId = $lastInsertId ?? Select::getId($existingUrls);

    return $response->withRedirect($router->urlFor('url', ['id' => $urlId]));
});



$app->post('/urls/{url_id}/checks', function (Request $request, Response $response, $args) use ($router) {
    $url_id = $args['url_id'];

    $check = new Checker();
    $check->makeCheck($url_id);

    if ($check->getErrors()) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
    } else {
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    }

    return $response->withRedirect($router->urlFor('url', ['id' => $url_id]));
})->setName('check');


$app->run();
