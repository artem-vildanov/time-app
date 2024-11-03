<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;
use React\Socket\SocketServer;


/**
 * Utils
 */

/**
 * @throws InvalidTimeZoneException
 */
function to_timezone($tz): DateTimeZone {
    try {
        return new DateTimeZone($tz);
    } catch (Exception $e) {
        throw new InvalidTimeZoneException();
    }
}

/**
 * @throws InvalidTimeZoneException
 */
function get_timezone_from_body(\Psr\Http\Message\StreamInterface $body): DateTimeZone | null {
    $parsedBody = json_decode($body->getContents(), true);
    if (isset($parsedBody['tz'])) {
        return to_timezone($parsedBody['tz']);
    }
    return null;
}

/**
 * Exceptions
 */

abstract class AppException extends \Exception {
    public function getFailResponse(): Response {
        return new Response(
            status: $this->code,
            body: json_encode([ 'error' => $this->message ], JSON_PRETTY_PRINT) . PHP_EOL
        );
    }
}

class NotFoundException extends AppException {
    public function __construct() {
        parent::__construct("Not found", 404);
    }
}

class InvalidTimeZoneException extends AppException {
    public function __construct() {
        parent::__construct("Invalid timezone", 400);
    }
}

class DateNotProvidedException extends AppException {
    public function __construct() {
        parent::__construct("Date not provided", 400);
    }
}

class InvalidDateException extends AppException {
    public function __construct() {
        parent::__construct("Invalid date", 400);
    }
}

class StartParamNotProvidedException extends AppException {
    public function __construct() {
        parent::__construct("Start param not provided", 400);
    }
}

class EndParamNotProvidedException extends AppException {
    public function __construct() {
        parent::__construct("End param not provided", 400);
    }
}

/**
 * Models
 */

class DateModel {
    public DateTime $date;
    public DateTimeZone | null $timezone = null;

    /**
     * @throws DateNotProvidedException
     * @throws InvalidDateException
     * @throws InvalidTimeZoneException
     */
    public static function fromArray(array $array): self {
        $date = new self();

        if (isset($array['tz'])) {
            $timezone = $array['tz'];
            try {
                $date->timezone = new DateTimeZone($timezone);
            } catch (\Exception $e) {
                throw new InvalidTimeZoneException();
            }
        }

        if (!isset($array['date'])) {
            throw new DateNotProvidedException();
        }

        $availableFormats = ['m.d.Y H:i:s', 'g:ia Y-m-d'];
        /** @var DateTime|bool $formattedDate */
        $formattedDate = false;
        foreach ($availableFormats as $format) {
            $formattedDate = DateTime::createFromFormat($format, $array['date'], $date->timezone);
            if ($formattedDate) {
                break;
            }
        }

        if (!$formattedDate) {
            throw new InvalidDateException();
        }

        $date->date = $formattedDate;
        return $date;
    }
}

class DatesDiffModel {
    public DateModel $start;
    public DateModel $end;

    /**
     * @throws StartParamNotProvidedException
     * @throws EndParamNotProvidedException
     * @throws DateNotProvidedException
     * @throws InvalidDateException
     * @throws InvalidTimeZoneException
     */
    public static function fromBody(\Psr\Http\Message\StreamInterface $body): self {
        $parsedBody = json_decode($body->getContents(), true);
        if (!isset($parsedBody['start'])) {
            throw new StartParamNotProvidedException();
        }

        if (!isset($parsedBody['end'])) {
            throw new EndParamNotProvidedException();
        }

        $datesDiff = new self();

        $datesDiff->start = DateModel::fromArray($parsedBody['start']);
        $datesDiff->end = DateModel::fromArray($parsedBody['end']);

        return $datesDiff;
    }

    public function calculateDiff(): string {
        $startDate = clone $this->start->date;
        $endDate = clone $this->end->date;

        $startDate->setTimezone(new DateTimeZone('UTC'));
        $endDate->setTimezone(new DateTimeZone('UTC'));

        $difference  = $startDate->diff($endDate);

        return $difference->days . " days, "
            . $difference->h . " hours, "
            . $difference->i . " minutes, "
            . $difference->s . " seconds";
    }
}

/**
 * Constants
 */

const URI = '0.0.0.0:8080';
const DATETIME_FORMAT = 'Y-m-d H:i:s';

/**
 * Routing
 */

class AppRouter extends AltoRouter {
    public function __construct() {
        parent::__construct();

        $this->newRoute('GET', '/ping', function(ServerRequest $request): Response {
            return Response::plaintext('OK');
        });

        $this->newRoute('GET', '/[:tz]', function(string $tz, ServerRequest $request): Response {
            $tzDecoded = rawurldecode($tz);
            $time = (new DateTime('now', to_timezone($tzDecoded)))->format(DATETIME_FORMAT);
            return Response::html("<h1>$time</h1>");
        });

        $this->newRoute('GET', '/', function(ServerRequest $request): Response {
            $time = (new DateTime('now'))->format(DATETIME_FORMAT);
            return Response::html("<h1>$time</h1>");
        });

        $this->newRoute('POST', '/api/v1/time', function(ServerRequest $request): Response {
            $timezone = get_timezone_from_body($request->getBody());
            $time = (new DateTime('now', $timezone))->format(DATETIME_FORMAT);
            return Response::json(['time' => $time]);
        });

        $this->newRoute('POST', '/api/v1/date', function(ServerRequest $request): Response {
            $timezone = get_timezone_from_body($request->getBody());
            $date = (new DateTime('now', $timezone))->format('Y-m-d');
            return Response::json(['date' => $date]);
        });

        $this->newRoute('POST', '/api/v1/datediff', function(ServerRequest $request): Response {
            $dateDiff = DatesDiffModel::fromBody($request->getBody());
            return Response::json(['difference' => $dateDiff->calculateDiff()]);
        });
    }

    /**
     * @param string $method
     * @param string $uri
     * @param callable(mixed ...$params, ServerRequest $request): Response $target
     * @return void
     * @throws Exception
     */
    private function newRoute(string $method, string $uri, callable $target): void {
        $this->map($method, $uri, function($args) use ($target): Response {
            try {
                return $target(...$args);
            } catch (AppException $e) {
                return $e->getFailResponse();
            } catch (Exception) {
                return new Response(status: 500, body: 'Internal server error');
            }
        });
    }
}

/**
 * app entrypoint
 */

function run_app(string $uri): void {
    $router = new AppRouter();
    $server = new HttpServer(function (ServerRequestInterface $request) use ($router) {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $match = $router->match($path, $method);

        if ($match) {
            return ($match['target'])(array_merge($match['params'], ['request' => $request]));
        }

        return (new NotFoundException())->getFailResponse();
    });

    $socket = new SocketServer(uri: $uri);
    $server->listen($socket);

    echo "Server running at http://$uri\n";
}
