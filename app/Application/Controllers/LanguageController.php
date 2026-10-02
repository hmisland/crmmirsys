<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class LanguageController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly UserRepository $users)
    {
        parent::__construct($templates);
    }

    public function switch(Request $request): Response
    {
        $locale = (string) $request->query('lang', '');
        $returnTo = (string) $request->query('return_to', '/');

        // Never redirect off-site with an attacker-supplied return_to
        // — only a same-origin relative path is honored.
        if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
            $returnTo = '/';
        }

        if (in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
            $user = CurrentUser::user();
            if ($user !== null) {
                $this->users->updateProfile((int) $user['id'], ['language' => $locale]);
            } else {
                Session::setGuestLocale($locale);
            }
        }

        return $this->redirect($returnTo);
    }
}
