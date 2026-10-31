<?php

namespace Tests\Feature\Concerns;

use App\Support\GoogleCalendarClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Stateful fake of the user's primary Google Calendar, keyed by event id in $events. */
trait FakesGoogleCalendar
{
    protected array $events = [];

    protected array $history = [];

    protected array $transport = [];

    protected string $calendarTimezone = 'America/Argentina/Buenos_Aires';

    /** Stateful Google transport: committed events are read by the real availability and booking services. */
    protected function installGoogle(?callable $intercept = null): void
    {
        $this->history = [];
        $this->transport = [];
        $handler = function (RequestInterface $request, array $options) use ($intercept): mixed {
            $this->transport[] = ['request' => $request, 'options' => $options];
            try {
                if ($intercept && ($response = $intercept($request, $options))) {
                    return $response;
                }
            } catch (ConnectException $e) {
                return PromiseCreate::rejectionFor($e);
            }
            $path = $request->getUri()->getPath();
            if (preg_match('~/events/([^/]+)$~', $path, $match)) {
                $id = rawurldecode($match[1]);
                if (! isset($this->events[$id])) {
                    return $this->errorResponse(404);
                }
                if ($request->getMethod() === 'DELETE') {
                    unset($this->events[$id]);

                    return new Response(204);
                }

                return $this->jsonResponse($this->events[$id]);
            }
            if ($request->getMethod() === 'POST') {
                $event = json_decode((string) $request->getBody(), true);
                $this->events[$event['id']] = [...$event, 'status' => 'confirmed', 'htmlLink' => 'https://calendar.google.com/event?eid='.$event['id']];

                return $this->jsonResponse($this->events[$event['id']]);
            }

            return $this->jsonResponse(['timeZone' => $this->calendarTimezone, 'items' => array_values($this->events)]);
        };
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 150, $handler)));
        $stack->push(Middleware::history($this->history));
        GoogleCalendarClient::$testHttpClient = new Client(['handler' => $stack, 'http_errors' => false]);
    }

    protected function jsonResponse(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    protected function errorResponse(int $status, string $reason = 'notFound'): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => $status, 'message' => 'Google request failed', 'errors' => [['reason' => $reason]]]]));
    }

    protected function writes(): array
    {
        return array_values(array_filter($this->history, fn ($entry) => in_array($entry['request']->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)));
    }
}
