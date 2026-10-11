<?php

declare(strict_types=1);

use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use Tests\Lite\Fixtures\FakeGate;

require_once __DIR__ . '/Fixtures/FakeModules.php';

$user = ['id' => 7, 'organization_id' => 1];
$panel = fn (string $id, ?string $module, int $order = 100, ?string $permission = null, ?\Closure $render = null) => new RelatedPanel(
    'deal',
    $id,
    $module,
    'widgets.panel',
    $render ?? fn (array $subject, array $user) => '<p>' . $id . ' for #' . $subject['id'] . '</p>',
    $order,
    $permission,
);
$ids = fn (array $panels) => array_map(fn (RelatedPanel $p) => $p->id, $panels);

return [
    'a fake module can register a panel and it is retrievable for its subject type' => function () use ($panel, $user, $ids) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['widgets'], []));
        LiteAssert::true($registry->register($panel('widgets.deal', 'widgets')));

        LiteAssert::same(['widgets.deal'], $ids($registry->panelsFor('deal', $user)));
        LiteAssert::same([], $registry->panelsFor('contact', $user), 'other subject types unaffected');
    },

    'panel of an unavailable module is excluded' => function () use ($panel, $user, $ids) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['gadgets'], []));
        $registry->register($panel('widgets.deal', 'widgets'));
        $registry->register($panel('gadgets.deal', 'gadgets'));

        LiteAssert::same(['gadgets.deal'], $ids($registry->panelsFor('deal', $user)));
    },

    'panel permission is respected; guests see nothing' => function () use ($panel, $user, $ids) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['widgets'], ['widgets.view']));
        $registry->register($panel('needs-view', 'widgets', 100, 'widgets.view'));
        $registry->register($panel('needs-admin', 'widgets', 100, 'widgets.admin'));

        LiteAssert::same(['needs-view'], $ids($registry->panelsFor('deal', $user)));
        LiteAssert::same([], $registry->panelsFor('deal', null));
        LiteAssert::same([], $registry->render('deal', ['id' => 1], null));
    },

    'ordering: by order, then id' => function () use ($panel, $user, $ids) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['widgets'], []));
        $registry->register($panel('c', 'widgets', 20));
        $registry->register($panel('b', 'widgets', 10));
        $registry->register($panel('a', 'widgets', 20));
        $registry->register($panel('core', null, 5));

        LiteAssert::same(['core', 'b', 'a', 'c'], $ids($registry->panelsFor('deal', $user)));
    },

    'render passes the subject and skips empty output' => function () use ($panel, $user) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['widgets'], []));
        $registry->register($panel('full', 'widgets'));
        $registry->register($panel('empty', 'widgets', 100, null, fn () => '   '));

        $out = $registry->render('deal', ['id' => 42], $user);
        LiteAssert::same(1, count($out));
        LiteAssert::same('<p>full for #42</p>', $out[0]['html']);
    },

    'a throwing panel is skipped, others still render' => function () use ($panel, $user) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['widgets'], []));
        $registry->register($panel('broken', 'widgets', 1, null, fn () => throw new \RuntimeException('boom')));
        $registry->register($panel('ok', 'widgets', 2));

        $out = $registry->render('deal', ['id' => 1], $user);
        LiteAssert::same(['ok'], array_map(fn ($r) => $r['panel']->id, $out));
    },

    'duplicate subject+id: first wins' => function () use ($panel, $user) {
        $registry = new RelatedPanelRegistry(FakeGate::make(['widgets'], []));
        $registry->register($panel('dup', 'widgets', 1, null, fn () => 'first'));
        LiteAssert::false($registry->register($panel('dup', 'widgets', 1, null, fn () => 'second')));

        LiteAssert::same('first', $registry->render('deal', ['id' => 1], $user)[0]['html']);
    },
];
