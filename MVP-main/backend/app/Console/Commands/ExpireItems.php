<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Reivindicacao;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ExpireItems extends Command
{
    protected $signature = 'items:expire';
    protected $description = 'Expira anúncios vencidos e encaminha reivindicações pendentes à moderação.';

    public function handle(NotificationService $notifications): int
    {
        $due = Item::with('publicador')->whereIn('status',['ABERTO','PONTO_GUARDA'])->whereNotNull('expira_em')->where('expira_em','<=',now())->get();
        foreach ($due as $item) {
            $item->update(['status'=>'EXPIRADO']);
            if ($item->publicador) $notifications->notify($item->publicador,'ITEM_EXPIRADO','Anúncio expirado',"O anúncio '{$item->titulo}' expirou e foi removido das buscas públicas.",['item_id'=>$item->id]);
        }

        $reminders = Item::with('publicador')->where('status','PONTO_GUARDA')->whereNotNull('expira_em')
            ->whereBetween('expira_em',[now(),now()->addDays(7)])->whereNull('aviso_expiracao_enviado_em')->get();
        foreach ($reminders as $item) {
            $item->update(['aviso_expiracao_enviado_em'=>now()]);
            if ($item->publicador) $notifications->notify($item->publicador,'AVISO_DESCARTE','Aviso de prazo do ponto de guarda',"O item '{$item->titulo}' está próximo do prazo configurado para o ponto de guarda. Consulte a política institucional.",['item_id'=>$item->id]);
        }

        $overdue = Reivindicacao::with(['item','solicitante'])->where('status','PENDENTE')->whereNull('encaminhada_moderacao_em')->where('created_at','<=',now()->subDays(7))->get();
        $moderators = User::whereIn('papel',['MODERADOR','ADMINISTRADOR'])->where('status','ATIVO')->get();
        foreach ($overdue as $claim) {
            $claim->update(['encaminhada_moderacao_em'=>now()]);
            foreach ($moderators as $moderator) $notifications->notify($moderator,'REIVINDICACAO_ATRASADA','Reivindicação pendente há 7 dias',"A reivindicação do item '{$claim->item?->titulo}' aguarda análise há mais de sete dias.",['item_id'=>$claim->item_id,'reivindicacao_id'=>$claim->id]);
        }
        $this->info("{$due->count()} anúncio(s) expirado(s); {$reminders->count()} aviso(s); {$overdue->count()} reivindicação(ões) encaminhada(s).");
        return self::SUCCESS;
    }
}
