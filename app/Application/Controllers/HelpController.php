<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\HelpArticleRepository;
use App\Infrastructure\Repositories\HelpCategoryRepository;
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
    /** Category keys: lowercase letters / digits, "-" or "_" between (module keys use "_"). */
    private const CATEGORY_KEY_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

    public function __construct(
        TemplateEngine $templates,
        private readonly HelpArticleRepository $articles,
        private readonly ?\App\Application\Services\PlatformTicketService $tickets = null,
        private readonly HelpCategoryRepository $categories = new HelpCategoryRepository(),
    ) {
        parent::__construct($templates);
    }

    // ---- Every user -----------------------------------------------------

    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $isSuperAdmin = !empty($user['is_super_admin']);
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $locale = $this->locale();
        $allCategories = $this->categories->all($locale);
        $category = (string) $request->query('category', '');
        if (!in_array($category, array_column($allCategories, 'key'), true)) {
            $category = '';
        }
        // Cards (default) or a list of titles only.
        $view = $request->query('view') === 'list' ? 'list' : 'grid';
        $pagination = Pagination::fromRequest($request, $this->articles->countForLocale($locale, $isSuperAdmin, $search, $category));

        // The filter offers every category that has articles, plus the ones listed even while empty (e.g. FAQ) and the selected one.
        $counts = $this->articles->categoryCounts($locale, $isSuperAdmin);
        $categories = [];
        foreach ($allCategories as $row) {
            $key = $row['key'];
            if (($counts[$key] ?? 0) > 0 || $row['always_listed'] === 1 || $key === $category) {
                $categories[$key] = ['label' => $row['name'], 'count' => $counts[$key] ?? 0];
            }
        }

        return $this->viewWithLayout('help/index', 'layout', [
            'articles' => $this->articles->listForLocale($locale, $isSuperAdmin, $search, $category, $pagination->perPage, $pagination->offset()),
            'search' => $search,
            'category' => $category,
            'categories' => $categories,
            'categoryNames' => array_column($allCategories, 'name', 'key'),
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

    // ---- Super Admin: categories ------------------------------------------

    public function categoryIndex(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->viewWithLayout('help/categories', 'layout', ['categories' => $this->categories->all($this->locale())]);
    }

    public function categoryCreate(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->categoryForm(null, ['key' => '', 'sort_order' => 0, 'always_listed' => false], [], []);
    }

    public function categoryEdit(Request $request): Response
    {
        $this->requireSuperAdmin();
        $category = $this->findCategoryOrFail((int) $request->attribute('id'));

        return $this->categoryForm($category, $category, $this->categories->translations((int) $category['id']), []);
    }

    public function categoryStore(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->saveCategory($request, null);
    }

    public function categoryUpdate(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->saveCategory($request, $this->findCategoryOrFail((int) $request->attribute('id')));
    }

    public function categoryDestroy(Request $request): Response
    {
        $this->requireSuperAdmin();
        $category = $this->findCategoryOrFail((int) $request->attribute('id'));
        $this->categories->deleteCategory((int) $category['id']);
        Session::setFlash('success', t('help.categories_admin.deleted'));

        return $this->redirect('/admin/help-categories');
    }

    private function saveCategory(Request $request, ?array $existing): Response
    {
        $id = $existing !== null ? (int) $existing['id'] : null;
        $values = [
            'key' => strtolower(trim((string) $request->input('key', ''))),
            'sort_order' => (int) $request->input('sort_order', 0),
            'always_listed' => $request->input('always_listed') === '1',
        ];
        $raw = (array) $request->input('names', []);
        $names = [];
        foreach (Translator::SUPPORTED_LOCALES as $locale) {
            $names[$locale] = trim((string) ($raw[$locale] ?? ''));
        }

        $errors = [];
        if ($values['key'] === '' || strlen($values['key']) > 40 || preg_match(self::CATEGORY_KEY_PATTERN, $values['key']) !== 1) {
            $errors['key'] = t('help.categories_admin.error.key');
        } elseif ($this->categories->keyTaken($values['key'], $id ?? 0)) {
            $errors['key'] = t('help.categories_admin.error.key_taken');
        }
        foreach ($names as $locale => $name) {
            if (mb_strlen($name) > 100) {
                $errors['names.' . $locale] = t('help.categories_admin.error.name_length');
            }
        }
        if ($names[HelpCategoryRepository::FALLBACK_LOCALE] === '') {
            $errors['names.' . HelpCategoryRepository::FALLBACK_LOCALE] = t('help.categories_admin.error.name_required');
        }
        if ($errors !== []) {
            return $this->categoryForm($existing, $values, $names, $errors, 422);
        }

        $this->categories->save($id, $values, array_filter($names, fn (string $n): bool => $n !== ''));
        Session::setFlash('success', t('help.categories_admin.saved'));

        return $this->redirect('/admin/help-categories');
    }

    private function categoryForm(?array $category, array $values, array $names, array $errors, int $status = 200): Response
    {
        return $this->viewWithLayout('help/category-form', 'layout', [
            'category' => $category,
            'values' => $values,
            'names' => $names,
            'errors' => $errors,
            'locales' => Translator::SUPPORTED_LOCALES,
        ], $status);
    }

    private function findCategoryOrFail(int $id): array
    {
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new NotFoundException();
        }

        return $category;
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
            // Only existing categories, in their display order.
            'categories' => array_values(array_intersect($this->categories->keys(), array_map('strval', (array) $request->input('categories', [])))),
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
            'categoryOptions' => $this->categories->names($this->locale()),
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
