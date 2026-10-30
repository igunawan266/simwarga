<?php

namespace App\Controllers;

use App\Exceptions\DomainRuleException;
use App\Services\AuthService;
use App\Services\MemberService;

/** Public invitation page: /invite/{token}. */
class InvitationController extends BaseController
{
    public function show(string $token)
    {
        $invite = (new MemberService())->findInvitation($token);

        return view('invite/accept', ['title' => 'Undangan · Virtual AI Office', 'invite' => $invite, 'token' => $token, 'errors' => [], 'name' => '']);
    }

    public function accept(string $token)
    {
        $service = new MemberService();
        $invite  = $service->findInvitation($token);
        if ($invite === null) {
            return view('invite/accept', ['title' => 'Undangan · Virtual AI Office', 'invite' => null, 'token' => $token, 'errors' => [], 'name' => '']);
        }

        $rules = $invite['is_new_user']
            ? [
                'name'             => 'required|string|min_length[2]|max_length[150]',
                'password'         => 'required|min_length[10]|max_length[200]',
                'password_confirm' => 'required|matches[password]',
            ]
            : ['password' => 'required|max_length[200]'];
        $input = $this->request->getPost(['name', 'password', 'password_confirm']);

        $errors = [];
        if (! $this->validateData($input, $rules, [
            'name'             => ['required' => 'Nama wajib diisi.', 'min_length' => 'Nama minimal 2 karakter.', 'max_length' => 'Nama terlalu panjang.'],
            'password'         => ['required' => 'Password wajib diisi.', 'min_length' => 'Password minimal 10 karakter.', 'max_length' => 'Password terlalu panjang.'],
            'password_confirm' => ['required' => 'Ulangi password.', 'matches' => 'Konfirmasi password tidak sama.'],
        ])) {
            $errors = $this->validator->getErrors();
        } else {
            try {
                $service->accept($token, $input);
                (new AuthService())->startSession((int) $invite['user_id'], (int) $invite['tenant_id']);

                return redirect()->to('/?welcome=1');
            } catch (DomainRuleException $e) {
                $errors = ['form' => $e->getMessage()];
            }
        }

        // Passwords are never echoed back; the name is kept so the user does not retype it.
        return view('invite/accept', ['title' => 'Undangan · Virtual AI Office', 'invite' => $invite, 'token' => $token, 'errors' => $errors, 'name' => (string) ($input['name'] ?? '')]);
    }
}
