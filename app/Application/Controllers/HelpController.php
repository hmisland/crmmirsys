<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\HelpCategories;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Pagination\Pagination;
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
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $category = (string) $request->query('category', '');
        if (!HelpCategories::valid($category)) {
            $category = '';
        }
        // Cards (default) or a list of titles only.
        $view = $request->query('view') === 'list' ? 'list' : 'grid';
        $locale = $this->locale();
        $pagination = Pagination::fromRequest($request, $this->articles->countForLocale($locale, $isSuperAdmin, $search, $category));

        // The filter offers every category that has articles, plus the ones always listed (FAQ) and the selected one.
        $counts = $this->articles->categoryCounts($locale, $isSuperAdmin);
        $categories = [];
        foreach (HelpCategories::KEYS as $key) {
            if (($counts[$key] ?? 0) > 0 || in_array($key, HelpCategories::ALWAYS_LISTED, true) || $key === $category) {
                $categories[$key] = ['label' => HelpCategories::label($key), 'count' => $counts[$key] ?? 0];
            }
        }

        return $this->viewWithLayout('help/index', 'layout', [
            'articles' => $this->articles->listForLocale($locale, $isSuperAdmin, $search, $category, $pagination->perPage, $pagination->offset()),
            'search' => $search,
            'category' => $category,
            'categories' => $categories,
            'view' => $view,
            'pagination' => $pagination,
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

        return $this->form(null, ['slug' => '', 'sort_order' => 0, 'is_published' => 1, 'categories' => []], [], []);
    }

    public function edit(Request $request): Response
    {
        $this->requireSuperAdmin();
        $article = $this->findOrFail((int) $request->attribute('id'));

        return $this->form($article, $article + ['categories' => $this->articles->categoriesOf((int) $article['id'])], $this->articles->translations((int) $article['id']), []);
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
            'categories' => HelpCategories::normalize((array) $request->input('categories', [])),
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

        $this->articles->save($id, $values, $filled, $userId, $values['categories']);
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
            'categoryOptions' => array_combine(HelpCategories::KEYS, array_map(fn (string $k): string => HelpCategories::label($k), HelpCategories::KEYS)),
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
