<?php

namespace App\Services;

use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function notify(User $user, string $type, string $title, string $message, array $data = []): Notificacao
    {
        $notification = Notificacao::create([
            'usuario_id' => $user->id,
            'tipo' => $type,
            'titulo' => $title,
            'mensagem' => $message,
            'dados' => $data,
            'email_enviado' => false,
        ]);

        if ($user->emails_ativados && $user->status === 'ATIVO') {
            try {
                Mail::raw($message."\n\nAcesse a plataforma: ".config('app.frontend_url'), function ($mail) use ($user, $title) {
                    $mail->to($user->email)->subject($title);
                });
                $notification->update(['email_enviado' => true]);
            } catch (\Throwable $exception) {
                Log::warning('Falha no envio de e-mail transacional', ['notification_id' => $notification->id, 'exception' => $exception->getMessage()]);
            }
        }

        return $notification;
    }
}
