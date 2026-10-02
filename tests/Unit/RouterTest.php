<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], []);
    }

    public function test_matches_a_static_route(): void
    {
        $router = new Router();
        $router->get('/health', fn () => \App\Core\Response::json(['ok' => true]));

        $response = $router->dispatch($this->request('GET', '/health'));

        $this->assertInstanceOf(\App\Core\Response::class, $response);
    }

    public function test_matches_a_route_with_a_param_and_exposes_it_on_the_request(): void
    {
        $router = new Router();
        $captured = null;

        $router->get('/contacts/{id}', function (Request $req) use (&$captured) {
            $captured = $req->attribute('id');
            return \App\Core\Response::json(['id' => $captured]);
        });

        $router->dispatch($this->request('GET', '/contacts/42'));

        $this->assertSame('42', $captured);
    }

    public function test_group_applies_prefix_and_middleware(): void
    {
        $router = new Router();
        $order = [];

        $middleware = new class ($order) implements \App\Core\Middleware\MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function handle(Request $request, callable $next): \App\Core\Response
            {
                $this->order[] = 'middleware';
                return $next($request);
            }
        };

        $router->group('/api/v1', [$middleware], function (Router $r) use (&$order) {
            $r->get('/ping', function () use (&$order) {
                $order[] = 'handler';
                return \App\Core\Response::json(['pong' => true]);
            });
        });

        $router->dispatch($this->request('GET', '/api/v1/ping'));

        $this->assertSame(['middleware', 'handler'], $order);
    }

    public function test_throws_not_found_for_unmatched_route(): void
    {
        $this->expectException(\App\Core\Exceptions\NotFoundException::class);

        (new Router())->dispatch($this->request('GET', '/nope'));
    }
}
