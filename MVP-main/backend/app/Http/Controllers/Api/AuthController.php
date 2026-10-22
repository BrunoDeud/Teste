<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        if ($request->filled('email')) $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'nome' => ['required','string','min:2','max:120'],
            'email' => ['required','email:rfc','max:190','unique:usuarios,email'],
            'senha' => ['required','string','min:8','confirmed'],
        ]);
        $email = Str::lower(trim($data['email']));
        $domain = Str::afterLast($email, '@');
        $allowed = \Illuminate\Support\Facades\DB::table('dominios_institucionais')->where('dominio', $domain)->where('ativo', true)->exists();
        if (!$allowed) {
            throw ValidationException::withMessages(['email' => 'Use seu e-mail institucional.']);
        }

        $token = Str::random(64);
        $user = User::create([
            'nome' => trim($data['nome']), 'email' => $email,
            'senha_hash' => Hash::make($data['senha']), 'papel' => 'USUARIO', 'status' => 'PENDENTE',
            'emails_ativados' => true, 'token_verificacao_hash' => hash('sha256', $token),
            'verificacao_expira_em' => now()->addHours(24),
        ]);
        $url = rtrim(config('app.frontend_url'), '/').'/?verificar='.urlencode($token);
        try {
            Mail::raw("Olá, {$user->nome}! Confirme seu e-mail institucional pelo link abaixo (válido por 24 horas):\n\n{$url}", function ($mail) use ($user) {
                $mail->to($user->email)->subject('Confirme seu cadastro — Achados e Perdidos');
            });
        } catch (\Throwable $e) {
            report($e);
        }

        $response = ['message' => 'Cadastro criado. Verifique o e-mail institucional para ativar sua conta.'];
        if (app()->environment(['local','testing'])) {
            $response['verification_token_dev'] = $token;
            $response['verification_url_dev'] = $url;
        }
        return response()->json($response, 201);
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate(['token' => ['required','string']]);
        $user = User::where('token_verificacao_hash', hash('sha256', $data['token']))->first();
        if (!$user || !$user->verificacao_expira_em || $user->verificacao_expira_em->isPast()) {
            return response()->json(['message' => 'Link de verificação inválido ou expirado. Solicite um novo link.'], 422);
        }
        $user->update(['status' => 'ATIVO', 'email_verificado_em' => now(), 'token_verificacao_hash' => null, 'verificacao_expira_em' => null]);
        $token = $user->createToken('web', ['*'], now()->addHour())->plainTextToken;
        return response()->json(['message' => 'E-mail verificado. Sua conta está ativa.', 'token' => $token, 'user' => $this->publicUser($user)]);
    }

    public function resendVerification(Request $request)
    {
        $data = $request->validate(['email' => ['required','email']]);
        $user = User::where('email', Str::lower($data['email']))->first();
        if (!$user || $user->status === 'ATIVO') {
            return response()->json(['message' => 'Se houver uma conta pendente para este e-mail, um novo link será enviado.']);
        }
        $token = Str::random(64);
        $user->update(['token_verificacao_hash' => hash('sha256', $token), 'verificacao_expira_em' => now()->addHours(24)]);
        $url = rtrim(config('app.frontend_url'), '/').'/?verificar='.urlencode($token);
        Mail::raw("Confirme seu e-mail pelo link (válido por 24 horas):\n\n{$url}", fn ($mail) => $mail->to($user->email)->subject('Novo link de verificação'));
        return response()->json(['message' => 'Se houver uma conta pendente para este e-mail, um novo link será enviado.'] + (app()->environment(['local','testing']) ? ['verification_token_dev' => $token] : []));
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required','email'], 'senha' => ['required','string']]);
        $user = User::where('email', Str::lower($data['email']))->first();
        if (!$user || !Hash::check($data['senha'], $user->senha_hash)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        if ($user->status === 'PENDENTE') {
            return response()->json(['message' => 'Verifique seu e-mail institucional antes de entrar.', 'verification_required' => true], 403);
        }
        if ($user->status !== 'ATIVO') {
            return response()->json(['message' => 'Esta conta está suspensa ou inativa.'], 403);
        }
        $token = $user->createToken('web', ['*'], now()->addHour())->plainTextToken;
        return response()->json(['token' => $token, 'user' => $this->publicUser($user)]);
    }

    public function me(Request $request) { return response()->json(['user' => $this->publicUser($request->user())]); }
    public function logout(Request $request) { $request->user()->currentAccessToken()?->delete(); return response()->json(['message' => 'Sessão encerrada.']); }

    public function preferences(Request $request)
    {
        $data = $request->validate(['emails_ativados' => ['required','boolean']]);
        $request->user()->update($data);
        return response()->json(['message' => 'Preferências salvas.', 'user' => $this->publicUser($request->user()->fresh())]);
    }

    private function publicUser(User $user): array
    {
        return ['id' => $user->id, 'nome' => $user->nome, 'email' => $user->email, 'papel' => $user->papel, 'status' => $user->status, 'emails_ativados' => $user->emails_ativados];
    }
}
