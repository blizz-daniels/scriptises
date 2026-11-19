<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;
use RuntimeException;

final class AuthController extends BaseController
{
    public function setup(): void
    {
        if ($this->auth->isConfigured()) {
            Http::redirect('/login');
        }
        View::render('auth/setup', ['pageTitle' => 'Create your private account']);
    }

    public function createInitialUser(): void
    {
        $this->requirePost();
        $this->csrf();
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('The passwords do not match.');
        }
        $this->auth->registerInitial($this->requestText('email'), $password);
        Flash::set('success', 'Your private writing account is ready.');
        Http::redirect('/');
    }

    public function loginForm(): void
    {
        if (!$this->auth->isConfigured()) {
            Http::redirect('/setup');
        }
        if ($this->auth->user() !== null) {
            Http::redirect('/');
        }
        View::render('auth/login', ['pageTitle' => 'Sign in']);
    }

    public function login(): void
    {
        $this->requirePost();
        $this->csrf();
        if (!$this->auth->login($this->requestText('email'), (string) ($_POST['password'] ?? ''))) {
            Flash::set('error', 'Those sign-in details were not recognized.');
            Http::redirect('/login');
        }
        Http::redirect('/');
    }

    public function logout(): void
    {
        $this->requirePost();
        $this->csrf();
        $this->auth->logout();
        Http::redirect('/login');
    }
}
