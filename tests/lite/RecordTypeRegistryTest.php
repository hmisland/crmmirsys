<?php

declare(strict_types=1);

use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use Tests\Lite\Fixtures\FakeGate;

require_once __DIR__ . '/Fixtures/FakeModules.php';

$user = ['id' => 7, 'organization_id' => 1];

/** Registry with the same Core types Application registers. */
$coreRegistry = function (ModuleGate $gate): RecordTypeRegistry {
    $registry = new RecordTypeRegistry($gate);
    $registry->register(new RecordType('contact', null, 'contacts.view', 'nav.contacts', '/contacts/{id}'));
    $registry->register(new RecordType('company', null, 'companies.view', 'nav.companies', '/companies/{id}'));
    $registry->register(new RecordType('lead', null, 'leads.view', 'nav.leads', '/leads/{id}'));

    return $registry;
};
$widget = new RecordType('widget', 'widgets', 'widgets.view', 'widgets.record', '/widgets/{id}', 'box');
$allCore = ['contacts.view', 'companies.view', 'leads.view'];

return [
    'Core record types are available with their permissions' => function () use ($coreRegistry, $user, $allCore) {
        $registry = $coreRegistry(FakeGate::make([], $allCore));

        foreach (['contact', 'company', 'lead'] as $key) {
            LiteAssert::true($registry->has($key), "{$key} registered");
            LiteAssert::true($registry->isAvailable($key, $user), "{$key} available (Core needs no module)");
            LiteAssert::true($registry->canView($key, $user), "{$key} viewable");
        }
    },

    'Core show URLs match the old RecordSupportController::backTo()' => function () use ($coreRegistry) {
        $registry = $coreRegistry(FakeGate::make([], []));

        LiteAssert::same('/contacts/5', $registry->get('contact')->showUrl(5));
        LiteAssert::same('/companies/5', $registry->get('company')->showUrl(5));
        LiteAssert::same('/leads/5', $registry->get('lead')->showUrl(5));
    },

    'a fake module can register a record type and it can be looked up' => function () use ($coreRegistry, $widget) {
        $registry = $coreRegistry(FakeGate::make(['widgets'], []));

        LiteAssert::true($registry->register($widget));
        LiteAssert::true($registry->get('widget') === $widget);
        LiteAssert::same(['contact', 'company', 'lead', 'widget'], array_map(fn ($t) => $t->key, $registry->all()));
    },

    'record type of an unavailable module is not usable' => function () use ($coreRegistry, $widget, $user) {
        $registry = $coreRegistry(FakeGate::make([], ['widgets.view']));
        $registry->register($widget);

        LiteAssert::true($registry->has('widget'), 'registered...');
        LiteAssert::false($registry->isAvailable('widget', $user), '...but module not available');
        LiteAssert::false($registry->canView('widget', $user));
    },

    'permission is respected for module and Core types' => function () use ($coreRegistry, $widget, $user) {
        $registry = $coreRegistry(FakeGate::make(['widgets'], ['contacts.view']));
        $registry->register($widget);

        LiteAssert::true($registry->isAvailable('widget', $user));
        LiteAssert::false($registry->canView('widget', $user), 'no widgets.view');
        LiteAssert::false($registry->canView('lead', $user), 'no leads.view');
        LiteAssert::same(['contact'], array_map(fn ($t) => $t->key, $registry->viewableBy($user)));
    },

    'guests and unknown types are never usable' => function () use ($coreRegistry, $allCore, $user) {
        $registry = $coreRegistry(FakeGate::make([], $allCore));

        LiteAssert::false($registry->canView('contact', null));
        LiteAssert::false($registry->isAvailable('nope', $user));
        LiteAssert::false($registry->canView('nope', $user));
        LiteAssert::same(null, $registry->get('nope'));
    },

    'duplicate key: first wins' => function () use ($coreRegistry) {
        $registry = $coreRegistry(FakeGate::make([], []));
        $hijack = new RecordType('contact', 'widgets', null, 'x', '/evil/{id}');

        LiteAssert::false($registry->register($hijack));
        LiteAssert::same('/contacts/1', $registry->get('contact')->showUrl(1), 'Core contact type unchanged');
    },

    'invalid keys are rejected' => function () {
        LiteAssert::throws(\InvalidArgumentException::class, fn () => new RecordType('Bad Key', null, null, 'x', '/x/{id}'));
        LiteAssert::throws(\InvalidArgumentException::class, fn () => new RecordType('../x', null, null, 'x', '/x/{id}'));
    },

    'module availability is looked up once per module and user (memo), reset clears it' => function () use ($widget, $user) {
        $calls = new \ArrayObject();
        $gate = FakeGate::make(['widgets'], ['widgets.view'], $calls);
        $registry = new RecordTypeRegistry($gate);
        $registry->register($widget);

        $registry->canView('widget', $user);
        $registry->canView('widget', $user);
        $registry->isAvailable('widget', $user);
        LiteAssert::same(1, count($calls));

        $gate->reset();
        $registry->canView('widget', $user);
        LiteAssert::same(2, count($calls));
    },
];
