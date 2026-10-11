<?php

declare(strict_types=1);

use App\Core\Container;
use App\Infrastructure\Modules\ModuleServiceRegistrar;
use Tests\Lite\Fixtures\FakePlainModule;
use Tests\Lite\Fixtures\FakeProvidingModule;
use Tests\Lite\Fixtures\UnrelatedService;
use Tests\Lite\Fixtures\WidgetCatalog;
use Tests\Lite\Fixtures\WidgetService;

require_once __DIR__ . '/Fixtures/FakeModules.php';

return [
    'module implementing ProvidesServices gets its services bound' => function () {
        $container = new Container();
        $service = new WidgetService();

        $bound = (new ModuleServiceRegistrar())->registerModule($container, 'widgets', new FakeProvidingModule([WidgetService::class => $service]));

        LiteAssert::same([WidgetService::class], $bound);
        LiteAssert::true($container->has(WidgetService::class));
        LiteAssert::true($container->make(WidgetService::class) === $service, 'the same instance is returned');
    },

    'a service can be exposed under an interface' => function () {
        $container = new Container();
        (new ModuleServiceRegistrar())->registerModule($container, 'widgets', new FakeProvidingModule([WidgetCatalog::class => new WidgetService()]));

        LiteAssert::same(['a', 'b'], $container->make(WidgetCatalog::class)->names());
    },

    'module without ProvidesServices is left untouched' => function () {
        $container = new Container();
        $bound = (new ModuleServiceRegistrar())->registerModule($container, 'plain', new FakePlainModule());

        LiteAssert::same([], $bound);
        LiteAssert::false($container->has(WidgetService::class));
    },

    'duplicate registration: first wins, deterministically' => function () {
        $container = new Container();
        $registrar = new ModuleServiceRegistrar();
        $first = new WidgetService('first');

        $registrar->registerModule($container, 'widgets', new FakeProvidingModule([WidgetService::class => $first]));
        $bound = $registrar->registerModule($container, 'other', new FakeProvidingModule([WidgetService::class => new WidgetService('second')]));

        LiteAssert::same([], $bound, 'second module binds nothing');
        LiteAssert::same('first', $container->make(WidgetService::class)->label);
    },

    'a module cannot replace an existing Core binding' => function () {
        $container = new Container();
        $core = new WidgetService('core');
        $container->instance(WidgetService::class, $core);

        (new ModuleServiceRegistrar())->registerModule($container, 'widgets', new FakeProvidingModule([WidgetService::class => new WidgetService('module')]));

        LiteAssert::same('core', $container->make(WidgetService::class)->label);
    },

    'null entries are skipped silently' => function () {
        $container = new Container();
        $bound = (new ModuleServiceRegistrar())->registerModule($container, 'widgets', new FakeProvidingModule([WidgetService::class => null]));

        LiteAssert::same([], $bound);
        LiteAssert::false($container->has(WidgetService::class));
    },

    'invalid entries are skipped: wrong instance type, non-class key' => function () {
        $container = new Container();
        $bound = (new ModuleServiceRegistrar())->registerModule($container, 'widgets', new FakeProvidingModule([
            WidgetService::class => new UnrelatedService(),
            'not-a-class' => new WidgetService(),
            0 => new WidgetService(),
        ]));

        LiteAssert::same([], $bound);
        LiteAssert::false($container->has(WidgetService::class));
    },
];
