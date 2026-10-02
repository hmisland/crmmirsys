<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\BankAccountService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class BankAccountController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly BankAccountService $bankAccounts)
    {
        parent::__construct($templates);
    }

    public function add(Request $request): Response
    {
        $actingUser = CurrentUser::user();

        try {
            $this->bankAccounts->add((int) $actingUser['id'], (int) $actingUser['organization_id'], $request->all());
            Session::setFlash('success', 'Bank account added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add bank account.');
        }

        return $this->redirect('/profile');
    }

    public function delete(Request $request): Response
    {
        $this->bankAccounts->delete((int) CurrentUser::id(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Bank account removed.');

        return $this->redirect('/profile');
    }
}
