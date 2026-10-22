<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogModeracao;
use App\Models\Reivindicacao;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaimController extends Controller
{
    public function myItems(Request $request)
    {
        $user = $request->user();
        $published = $user->itens()->with(['fotos','categoria:id,nome','local:id,nome,bloco'])->latest()->get();
        $claims = Reivindicacao::with(['item.fotos','item.categoria:id,nome','item.local:id,nome,bloco','solicitante:id,nome'])
            ->where(function ($q) use ($user) {
                $q->where('solicitante_id',$user->id)->orWhereHas('item',fn($iq)=>$iq->where('usuario_id',$user->id));
            })->latest()->get();
        $claims->each(function ($claim) use ($user) {
            if ($user->isModerator() || $claim->item?->usuario_id === $user->id) $claim->makeVisible('resposta_verificacao');
        });
        return response()->json(['anuncios'=>$published, 'reivindicacoes'=>$claims]);
    }

    public function decide(Request $request, string $id, NotificationService $notifications)
    {
        $claim = Reivindicacao::with(['item.publicador','solicitante'])->findOrFail($id);
        $item = $claim->item;
        $user = $request->user();
        if ($item->ponto_guarda_id) {
            abort_unless($user->isModerator(),403,'Somente moderadores podem analisar itens no ponto de guarda.');
        } elseif ($claim->encaminhada_moderacao_em && $user->isModerator()) {
            // Reivindicações sem resposta após sete dias entram na fila da moderação.
        } else {
            abort_unless($item->usuario_id === $user->id,403,'Somente quem publicou o item pode analisar a reivindicação.');
        }
        abort_unless($claim->status === 'PENDENTE',422,'Esta reivindicação já foi analisada.');
        $data = $request->validate(['decisao'=>['required','in:APROVAR,RECUSAR'],'motivo_recusa'=>['required_if:decisao,RECUSAR','nullable','string','min:3','max:1000']]);

        DB::transaction(function () use ($claim,$item,$user,$data) {
            $lockedItem = \App\Models\Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($data['decisao'] === 'APROVAR') {
                if ($lockedItem->reivindicacoes()->where('status','APROVADA')->exists()) abort(422,'Este item já possui outra reivindicação aprovada.');
                $lockedItem->reivindicacoes()->where('status','PENDENTE')->where('id','!=',$claim->id)->update(['status'=>'CANCELADA','decidido_por'=>$user->id,'decidido_em'=>now(),'updated_at'=>now()]);
                $claim->update(['status'=>'APROVADA','decidido_por'=>$user->id,'decidido_em'=>now(),'motivo_recusa'=>null]);
            } else {
                $claim->update(['status'=>'RECUSADA','motivo_recusa'=>trim($data['motivo_recusa']),'decidido_por'=>$user->id,'decidido_em'=>now()]);
            }
            LogModeracao::create(['moderador_id'=>$user->id,'item_id'=>$item->id,'acao'=>$data['decisao']==='APROVAR'?'REIVINDICACAO_APROVADA':'REIVINDICACAO_RECUSADA','justificativa'=>$data['decisao']==='APROVAR'?'Verificação aprovada.':trim($data['motivo_recusa']),'detalhes'=>['reivindicacao_id'=>$claim->id],'created_at'=>now()]);
        });
        $title = $data['decisao'] === 'APROVAR' ? 'Sua reivindicação foi aprovada' : 'Sua reivindicação foi recusada';
        $msg = $data['decisao'] === 'APROVAR' ? "A reivindicação para '{$item->titulo}' foi aprovada. Use o chat interno para combinar a devolução." : "A reivindicação para '{$item->titulo}' foi recusada. Motivo: ".trim($data['motivo_recusa']);
        $notifications->notify($claim->solicitante,$data['decisao']==='APROVAR'?'REIVINDICACAO_APROVADA':'REIVINDICACAO_RECUSADA',$title,$msg,['item_id'=>$item->id,'reivindicacao_id'=>$claim->id]);
        return response()->json(['message'=>'Decisão registrada.','reivindicacao'=>$claim->fresh()->makeHidden(['resposta_verificacao'])]);
    }

    public function messages(Request $request, string $id)
    {
        $claim = Reivindicacao::with('item')->findOrFail($id);
        $this->ensureParticipant($request, $claim);
        abort_unless($claim->status === 'APROVADA',422,'O chat é liberado somente após a aprovação da reivindicação.');
        if ($request->isMethod('get')) {
            return response()->json(['mensagens'=>$claim->mensagens()->with('remetente:id,nome')->get()]);
        }
        $data = $request->validate(['conteudo'=>['required','string','min:1','max:500']]);
        $message = $claim->mensagens()->create(['remetente_id'=>$request->user()->id,'conteudo'=>trim($data['conteudo'])]);
        $otherUserId = $request->user()->id === $claim->solicitante_id ? $claim->item->usuario_id : $claim->solicitante_id;
        $other = User::find($otherUserId);
        if ($other) app(NotificationService::class)->notify($other,'NOVA_MENSAGEM','Nova mensagem na reivindicação',"Você recebeu uma mensagem sobre '{$claim->item->titulo}'.",['item_id'=>$claim->item_id,'reivindicacao_id'=>$claim->id]);
        return response()->json(['message'=>'Mensagem enviada.','mensagem'=>$message->load('remetente:id,nome')],201);
    }

    public function returned(Request $request, string $id)
    {
        $claim = Reivindicacao::with('item')->findOrFail($id);
        $item = $claim->item;
        abort_unless($claim->status === 'APROVADA',422,'É necessário ter uma reivindicação aprovada para concluir a devolução.');
        if ($item->ponto_guarda_id) abort_unless($request->user()->isModerator(),403,'Somente moderadores podem concluir devoluções do ponto de guarda.');
        else abort_unless($request->user()->id === $item->usuario_id,403,'Somente quem publicou o item pode concluir a devolução.');
        DB::transaction(function () use ($claim,$item,$request) {
            $item->update(['status'=>'RESOLVIDO','resolvido_em'=>now()]);
            $claim->update(['status'=>'DEVOLVIDA','decidido_por'=>$request->user()->id,'decidido_em'=>now()]);
            LogModeracao::create(['moderador_id'=>$request->user()->id,'item_id'=>$item->id,'acao'=>'ITEM_DEVOLVIDO','justificativa'=>'Devolução confirmada pelo responsável.','detalhes'=>['reivindicacao_id'=>$claim->id],'created_at'=>now()]);
        });
        return response()->json(['message'=>'Devolução registrada. O anúncio foi encerrado e removido das buscas.']);
    }

    private function ensureParticipant(Request $request, Reivindicacao $claim): void
    {
        $uid = $request->user()->id;
        abort_unless($uid === $claim->solicitante_id || $uid === $claim->item->usuario_id || $request->user()->isModerator(),403,'Você não participa desta reivindicação.');
    }
}
