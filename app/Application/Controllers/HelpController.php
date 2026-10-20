<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Repositories\HelpArticleRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\HtmlSanitizer;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Help center. Every signed-in user reads published articles at /help
 * (or in a popup via help_link()); only the Super Admin creates, edits
 * and deletes them (/admin/help). Bodies are HTML, sanitized on save
 * and again on output.
 */
final class HelpController extends BaseController
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        TemplateEngine $templates,
        private readonly HelpArticleRepository $articles,
        private readonly ?\App\Application\Services\PlatformTicketService $tickets = null,
    ) {
        parent::__construct($templates);
    }

    // ---- Every user -----------------------------------------------------

    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $isSuperAdmin = !empty($user['is_super_admin']);
        $search = trim((string) $request->query('q', ''));

        return $this->viewWithLayout('help/index', 'layout', [
            'articles' => $this->articles->listForLocale($this->locale(), $isSuperAdmin, mb_substr($search, 0, 100)),
            'search' => $search,
            'canManage' => $isSuperAdmin,
            // "Send a ticket" to the platform team (organization admins).
            'canSendTicket' => $this->tickets?->canUse($user) ?? false,
        ]);
    }

    public function show(Request $request): Response
    {
        $user = $this->requireUser();
        $isSuperAdmin = !empty($user['is_super_admin']);

        $article = $this->articles->findBySlug((string) $request->attribute('slug'), $this->locale());
        if ($article === null || (!$article['is_published'] && !$isSuperAdmin)) {
            throw new NotFoundException();
        }
        $article['body'] = HtmlSanitizer::clean((string) $article['body']);

        if ($request->query('fragment') === '1') {
            return $this->view('help/fragment', ['article' => $article]);
        }

        return $this->viewWithLayout('help/show', 'layout', [
            'article' => $article,
            'canManage' => $isSuperAdmin,
        ]);
    }

    // ---- Super Admin ----------------------------------------------------

    public function create(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->form(null, ['slug' => '', 'sort_order' => 0, 'is_published' => 1], [], []);
    }

    public function edit(Request $request): Response
    {
        $this->requireSuperAdmin();
        $article = $this->findOrFail((int) $request->attribute('id'));

        return $this->form($article, $article, $this->articles->translations((int) $article['id']), []);
    }

    public function store(Request $request): Response
    {
        $user = $this->requireSuperAdmin();

        return $this->save($request, null, (int) $user['id']);
    }

    public function update(Request $request): Response
    {
        $user = $this->requireSuperAdmin();
        $article = $this->findOrFail((int) $request->attribute('id'));

        return $this->save($request, $article, (int) $user['id']);
    }

    public function destroy(Request $request): Response
    {
        $this->requireSuperAdmin();
        $this->articles->deleteArticle((int) $request->attribute('id'));
        Session::setFlash('success', t('help.flash.deleted'));

        return $this->redirect('/help');
    }

    // ---- Internals -------------------------------------------------------

    private function save(Request $request, ?array $existing, int $userId): Response
    {
        $id = $existing !== null ? (int) $existing['id'] : null;
        $slug = strtolower(trim((string) $request->input('slug', '')));
        $values = [
            'slug' => $slug,
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_published' => $request->input('is_published') === '1',
        ];

        $translations = [];
        $raw = (array) $request->input('tr', []);
        foreach (Translator::SUPPORTED_LOCALES as $locale) {
            $row = (array) ($raw[$locale] ?? []);
            $translations[$locale] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'summary' => trim((string) ($row['summary'] ?? '')),
                'body' => trim((string) ($row['body'] ?? '')),
            ];
        }

        $errors = [];
        if ($slug === '' || strlen($slug) > 100 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $errors['slug'] = t('help.error.slug');
        } elseif ($this->articles->slugTaken($slug, $id ?? 0)) {
            $errors['slug'] = t('help.error.slug_taken');
        }

        $filled = [];
        foreach ($translations as $locale => $tr) {
            if ($tr['title'] === '' && $tr['summary'] === '' && $tr['body'] === '') {
                continue;
            }
            if ($tr['title'] === '' || mb_strlen($tr['title']) > 200) {
                $errors['tr.' . $locale . '.title'] = t('help.error.title');
            }
            if (mb_strlen($tr['summary']) > 500) {
                $errors['tr.' . $locale . '.summary'] = t('help.error.summary');
            }
            if ($tr['body'] === '') {
                $errors['tr.' . $locale . '.body'] = t('help.error.body');
            }
            $filled[$locale] = ['body' => HtmlSanitizer::clean($tr['body'])] + $tr;
        }
        if (!isset($filled[HelpArticleRepository::FALLBACK_LOCALE]) && !isset($errors['tr.en.title'])) {
            $errors['tr.en.title'] = t('help.error.fallback_required');
        }

        if ($errors !== []) {
            return $this->form($existing, $values, $translations, $errors, 422);
        }

        $this->articles->save($id, $values, $filled, $userId);
        Session::setFlash('success', t('help.flash.saved'));

        return $this->redirect('/help/' . rawurlencode($slug));
    }

    private function form(?array $article, array $values, array $translations, array $errors, int $status = 200): Response
    {
        return $this->viewWithLayout('help/form', 'layout', [
            'article' => $article,
            'values' => $values,
            'translations' => $translations,
            'errors' => $errors,
            'locales' => Translator::SUPPORTED_LOCALES,
        ], $status);
    }

    private function findOrFail(int $id): array
    {
        $article = $this->articles->find($id);
        if ($article === null) {
            throw new NotFoundException();
        }

        return $article;
    }

    private function locale(): string
    {
        $translator = Locale::get();

        return $translator !== null ? $translator->locale() : HelpArticleRepository::FALLBACK_LOCALE;
    }

    private function requireSuperAdmin(): array
    {
        $user = $this->requireUser();
        if (empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }

        return $user;
    }

    private function requireUser(): array
    {
        $user = CurrentUser::user();
        if ($user === null) {
            throw new AuthorizationException();
        }

        return $user;
    }
}
