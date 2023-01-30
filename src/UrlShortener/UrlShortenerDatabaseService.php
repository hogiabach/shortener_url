<?php

declare(strict_types=1);

namespace UrlShortener;

use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Insert;
use Laminas\Db\Sql\Select;
use Laminas\Db\TableGateway\AbstractTableGateway;

final class UrlShortenerDatabaseService implements UrlShortenerPersistenceInterface
{
  private AbstractTableGateway $tableGateway;

  public function __construct(AbstractTableGateway $tableGateway)
  {
    $this->$tableGateway = $tableGateway;
  }

  public function getLongUrl(string $shortUrl): string
  {
    $rowSet = $this->tableGateway->select(
      function (Select $select) use ($shortUrl) {
        $select->columns(["long_url"])->where(["short_url" => $shortUrl]);
      }
    );

    $data = $rowSet->current();

    return ($data["long_url"]);
  }

  public function hasShortUrl(string $shortUrl): bool
  {
    $rowSet = $this->tableGateway->select(function (Select $select) use ($shortUrl) {
      $select->columns(["count" => new Expression("COUNT(*)")])->where(["short_url" => $shortUrl]);
    });

    $data = $rowSet->current();
    return (bool)$data["count"];
  }

  public function persistUrl(string $longUrl, string $shortenedUrl): bool
  {
    $insert = new Insert('urls');
    $insert
      ->columns(['long', 'short'])
      ->values([$longUrl, $shortenedUrl]);

    return (bool)$this->tableGateway->insertWith($insert);
  }
}
