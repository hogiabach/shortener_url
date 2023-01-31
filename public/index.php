<?php

use DI\Container;
use Laminas\Db\Adapter\Exception\InvalidQueryException;
use Laminas\Db\TableGateway\{Feature\RowGatewayFeature, TableGateway};
use Laminas\Diactoros\Response\{JsonResponse, RedirectResponse};
use Laminas\Filter\{StringTrim, StripTags};
use Laminas\InputFilter\{Input, InputFilter};
use Laminas\Validator\{Db\NoRecordExists, NotEmpty, Uri};
use Laminas\Db\Adapter\Adapter;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\{Twig, TwigMiddleware};
use UrlShortener\{UrlShortenerService, UrlShortenerDatabaseService};

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$container = new Container;
$container->set(InputFilter::class, function (ContainerInterface $container): InputFilter {
  $url = new Input('url');
  $url->getValidatorChain()
    ->attach(new NotEmpty([
      'messages' => [
        NotEmpty::IS_EMPTY => 'Please provide a URL'
      ]
    ]))
    ->attach(new Uri([
      'messages' => [
        Uri::INVALID => 'That URL is not valid',
        Uri::NOT_URI => 'That URL is not valid',
      ]
    ]))
    ->attach(new NoRecordExists([
      'table'   => $_SERVER['DB_TABLE_NAME'],
      'field'   => 'long',
      'adapter' => $container->get(Adapter::class),
      'messages' => [
        NoRecordExists::ERROR_RECORD_FOUND => 'That URL has already been shortened. Please try another one.'
      ]
    ]));
  $url->getFilterChain()
    ->attach(new StringTrim())
    ->attach(new StripTags());

  $inputFilter = new InputFilter();
  $inputFilter->add($url);

  return $inputFilter;
});

$container->set(Adapter::class, function (): Adapter {
  return new Adapter([
    'database' => $_SERVER['DB_NAME'],
    'driver'   => 'Pdo_Pgsql',
    'host'     => $_SERVER['DB_HOST'],
    'password' => $_SERVER['DB_PASSWORD'],
    'username' => $_SERVER['DB_USERNAME'],
  ]);
});

$container->set(
  UrlShortenerService::class,
  function (ContainerInterface $container): UrlShortenerService {
    $tableGateway = new TableGateway(
      'urls',
      $container->get(Adapter::class),
      [new RowGatewayFeature(['long', 'short'])]
    );
    return new UrlShortenerService(
      new UrlShortenerDatabaseService($tableGateway)
    );
  }
);

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(TwigMiddleware::create(
  $app,
  Twig::create(__DIR__ . '/../src/templates/', ['cache' => false])
));

$app->map(
  ['GET', 'POST'],
  '/',
  function (Request $request, Response $response, array $args) {
    $data = [];

    if ($request->getMethod() === 'POST') {
      /** @var InputFilter $filter */
      $filter = $this->get(InputFilter::class);
      $filter->setData((array)$request->getParsedBody());
      if (!$filter->isValid()) {
        $data['errors'] = $filter->getMessages();
        $data['values'] = $filter->getValues();
      } else {
        /** @var UrlShortenerService $shortener */
        $shortener = $this->get(UrlShortenerService::class);
        try {
          $shortUrl = $shortener->getShortUrl(
            $filter->getValue('url')
          );
          $data = array_merge(
            $data,
            [
              'shortUrl' => $shortUrl,
              'longUrl' => $filter->getValue('url'),
              'success' => true
            ]
          );
        } catch (InvalidQueryException $e) {
          echo $e->getMessage();
        }
      }
    }

    return Twig::fromRequest($request)
      ->render($response, 'default.html.twig', $data);
  }
);

$app->get(
  '/{url:[a-zA-Z0-9]{9}}',
  function (Request $request, Response $response, array $args) {
    /** @var InputFilter $filter */
    $filter = $this->get(InputFilter::class);
    $filter->setData($args);

    /** @var UrlShortenerService $shortener */
    $shortener = $this->get(UrlShortenerService::class);

    if (
      $filter->isValid() &&
      $shortener->hasShortUrl($filter->getValue('url'))
    ) {
      return new RedirectResponse(
        $shortener->getLongUrl($filter->getValue('url'))
      );
    }

    return new JsonResponse(
      sprintf("No URL matching '%s' available", $filter->getValue('url')),
      404
    );
  }
);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
  Request $request
) use ($app) {
  $response = $app->getResponseFactory()->createResponse();
  return Twig::fromRequest($request)
    ->render($response, '404.html.twig', []);
});

$app->run();
