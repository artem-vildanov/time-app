<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

require 'App.php';

class AppTest extends TestCase
{
    private const MAX_SECONDS_DELAY = 1;

    private static ?Client $client = null;

    public static function setUpBeforeClass(): void {
//        self::$client = new Client(['base_uri' => "http://" . URI . "/"]);
        self::$client = new Client(['base_uri' => "http://time-app:8080/"]);
    }

    protected function setUp(): void {
        try {
            self::$client->request('GET', '/ping');
        } catch (ServerException|ConnectException|ClientException $e) {
            echo($e->getMessage());
            throw new Exception("App server not started, run it before tests");
        }
    }

    public function testGetTimeHtml(): void {
        $response = self::$client->request('GET', '/');
        $this->assertEquals(200, $response->getStatusCode());
        $currentTime = new DateTime();
        $responseTime = $this->getDatetimeFromHtmlResponse($response);
        $this->compareTime($currentTime, $responseTime);
    }

    public function testGetTimeByTimezoneHtml(): void {
        /**
         * Positive
         */
        $response = $this->get('/Europe%2FLondon');
        $this->assertEquals(200, $response->getStatusCode());
        $currentTime = new DateTime('Europe/London');
        $responseTime = $this->getDatetimeFromHtmlResponse($response);
        $this->compareTime($currentTime, $responseTime);
        /**
         * Negative
         */
        $response = $this->get('/London%2FLondon');
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid timezone', $this->getByKeyFromResponse($response, 'error'));
    }

    public function testGetTimeByTimezoneJson(): void {
        /**
         * Positive with tz
         */
        $response = $this->post('/api/v1/time', ['tz' => 'Europe/London']);
        $this->assertEquals(200, $response->getStatusCode());
        $currentTime = new DateTime('Europe/London');
        $responseTime = $this->getDatetimeFromJsonResponse($response);
        $this->compareTime($currentTime, $responseTime);
        /**
         * Positive without tz
         */
        $response = $this->post('/api/v1/time');
        $this->assertEquals(200, $response->getStatusCode());
        $currentTime = new DateTime();
        $responseTime = $this->getDatetimeFromJsonResponse($response);
        $this->compareTime($currentTime, $responseTime);
        /**
         * Negative
         */
        $response = $this->post('/api/v1/time', ['tz' => 'Europe/Europe']);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid timezone', $this->getByKeyFromResponse($response, 'error'));
    }

    public function testGetDateByTimezoneJson(): void {
        /**
         * Positive with tz
         */
        $response = $this->post('/api/v1/date', ['tz' => 'Europe/London']);
        $this->assertEquals(200, $response->getStatusCode());
        $currentDate = (new DateTime('Europe/London'))->format('Y-m-d');
        $responseDate = $this->getByKeyFromResponse($response, 'date');
        $this->assertEquals($currentDate, $responseDate);
        /**
         * Positive without tz
         */
        $response = $this->post('/api/v1/date');
        $this->assertEquals(200, $response->getStatusCode());
        $currentDate = (new DateTime())->format('Y-m-d');
        $responseDate = $this->getByKeyFromResponse($response, 'date');
        $this->assertEquals($currentDate, $responseDate);
        /**
         * Negative
         */
        $response = $this->post('/api/v1/time', ['tz' => 'Europe/Europe']);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid timezone', $this->getByKeyFromResponse($response, 'error'));
    }

    public function testGetDatesDiff(): void {
        /**
         * Positive with tz
         */
        $response = $this->post('/api/v1/datediff', [
            'start' => [
                'date' => '07.18.2003 10:30:00',
                'tz' => 'UTC'
            ],
            'end' => [
                'date' => '07.18.2003 10:30:00',
                'tz' => 'Asia/Novosibirsk'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $expected = "0 days, 7 hours, 0 minutes, 0 seconds";
        $this->assertEquals($expected, $this->getByKeyFromResponse($response, 'difference'));
        /**
         * Positive without tz
         */
        $response = $this->post('/api/v1/datediff', [
            'start' => [
                'date' => '18.07.2003 10:30:00',
            ],
            'end' => [
                'date' => '12:45pm 2003-18-09',
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $expected = "2 days, 2 hours, 15 minutes, 0 seconds";
        $this->assertEquals($expected, $this->getByKeyFromResponse($response, 'difference'));
        /**
         * Negative
         */
        $response = $this->post('/api/v1/datediff', [
            'start' => [
                'date' => '07.18.2003 10:30:00',
            ],
            'end' => [
                'date' => '19.28.203 112:45:15',
            ]
        ]);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid date', $this->getByKeyFromResponse($response, 'error'));
    }

    private function compareTime(DateTime $expected, DateTime $actual): void {
        $this->assertLessThanOrEqual(
            self::MAX_SECONDS_DELAY,
            abs($expected->getTimestamp() - $actual->getTimestamp())
        );
    }

    private function getDatetimeFromHtmlResponse(ResponseInterface $response): DateTime {
        $responseTime = $response->getBody()->getContents();
        $responseTime = strip_tags($responseTime);
        return DateTime::createFromFormat(DATETIME_FORMAT, $responseTime);
    }

    private function getDatetimeFromJsonResponse(ResponseInterface $response): DateTime {
        $responseTime = $this->getByKeyFromResponse($response, 'time');
        return DateTime::createFromFormat(DATETIME_FORMAT, $responseTime);
    }

    private function getByKeyFromResponse(ResponseInterface $response, string $key): string {
        $response = $response->getBody()->getContents();
        /** @var array $responseDecoded */
        $responseDecoded = json_decode($response, true);
        if (isset($responseDecoded[$key])) {
            return $responseDecoded[$key];
        } else {
            throw new Exception("$key not provided in response");
        }
    }

    private function get(string $uri): ResponseInterface {
        return self::$client->request('GET', $uri, ['http_errors' => false]);
    }

    private function post(string $uri, array $body = []): ResponseInterface {
        return self::$client->request('POST', $uri, [
            'http_errors' => false,
            'json' => $body
        ]);
    }
}
