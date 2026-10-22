<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Denuncia;
use App\Models\Item;
use App\Models\LogModeracao;
use App\Models\Reivindicacao;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModerationController extends Controller
{
    public function reports(Request $request)
    {
        $query = Denuncia::with(['item.fotos','item.categoria:id,nome','item.local:id,nome,bloco','denunciante:id,nome'])->latest();
        if ($request->filled('status')) $query->where('status',$request->input('status'));
        else $query->where('status','ABERTA');
        return response()->json($query->paginate(30));
    }

    public function claims(Request $request)
    {
        $claims = Reivindicacao::with(['item.fotos','item.categoria:id,nome','item.local:id,nome,bloco','item.publicador:id,nome','solicitante:id,nome'])
            ->where(function ($query) {
                $query->where(function ($pending) {
                    $pending->where('status','PENDENTE')->where(function ($queue) {
                        $queue->whereNotNull('encaminhada_moderacao_em')
                            ->orWhereHas('item',fn($items)=>$items->whereNotNull('ponto_guarda_id'));
                    });
                })->orWhere(function ($approved) {
                    $approved->where('status','APROVADA')
                        ->whereHas('item',fn($items)=>$items->whereNotNull('ponto_guarda_id'));
                });
            })->latest()->paginate(30);
        $claims->getCollection()->each(fn($claim)=>$claim->makeVisible('resposta_verificacao'));
        return response()->json($claims);
    }

    public function resolveReport(Request $request, string $id, NotificationService $notifications)
    {
        $data = $request->validate(['acao'=>['required','in:REMOVER_ANUNCIO,MANTER_ANUNCIO'],'justificativa'=>['required','string','min:5','max:2000']]);
        $report = Denuncia::with('item.publicador')->findOrFail($id);
        if ($report->status !== 'ABERTA') abort(422,'Esta denúncia já foi tratada.');
        $wasHidden = $report->item->status === 'OCULTO';
        DB::transaction(function () use ($report,$data,$request,$wasHidden) {
            $resolvedAt = now();
            if ($data['acao'] === 'REMOVER_ANUNCIO') {
                $report->item->update(['status'=>'OCULTO']);
                // A decisão vale para todas as denúncias abertas sobre o mesmo anúncio.
                $report->item->denuncias()->where('status','ABERTA')->update([
                    'status'=>'TRATADA', 'acao_moderacao'=>'REMOVER_ANUNCIO',
                    'resolvida_por'=>$request->user()->id, 'resolucao'=>trim($data['justificativa']),
                    'resolvida_em'=>$resolvedAt, 'updated_at'=>$resolvedAt,
                ]);
            } else {
                if ($wasHidden) $report->item->update(['status'=>$report->item->ponto_guarda_id ? 'PONTO_GUARDA' : 'ABERTO']);
                $report->update([
                    'status'=>'TRATADA', 'acao_moderacao'=>'MANTER_ANUNCIO',
                    'resolvida_por'=>$request->user()->id, 'resolucao'=>trim($data['justificativa']),
                    'resolvida_em'=>$resolvedAt,
                ]);
            }
            LogModeracao::create(['moderador_id'=>$request->user()->id,'item_id'=>$report->item_id,'acao'=>$data['acao'],'justificativa'=>trim($data['justificativa']),'detalhes'=>['denuncia_id'=>$report->id],'created_at'=>$resolvedAt]);
        });
        $owner = $report->item->publicador;
        if ($data['acao'] === 'REMOVER_ANUNCIO' && $owner) {
            $notifications->notify($owner,'ANUNCIO_REMOVIDO','Anúncio ocultado pela moderação',"Seu anúncio '{$report->item->titulo}' foi ocultado após revisão. Justificativa: ".trim($data['justificativa']),['item_id'=>$report->item_id]);
            $procedentReports = Denuncia::where('acao_moderacao','REMOVER_ANUNCIO')
                ->whereHas('item',fn($items)=>$items->where('usuario_id',$owner->id))->count();
            if ($procedentReports >= 3 && $owner->status === 'ATIVO') {
                $owner->update(['status'=>'SUSPENSO']);
                $owner->tokens()->delete();
                $notifications->notify($owner,'CONTA_SUSPENSA','Conta suspensa após denúncias procedentes','Sua conta foi suspensa automaticamente após três denúncias procedentes. Entre em contato com a administração da instituição para solicitar uma revisão.',[]);
                User::whereIn('papel',['MODERADOR','ADMINISTRADOR'])->where('status','ATIVO')->where('id','!=',$owner->id)->get()->each(
                    fn($moderator)=>$notifications->notify($moderator,'REVISAO_CONTA_SUSPENSA','Conta requer revisão',"A conta de {$owner->nome} foi suspensa automaticamente após três denúncias procedentes.",['usuario_id'=>$owner->id])
                );
            }
        } elseif ($wasHidden && $owner) {
            $notifications->notify($owner,'ANUNCIO_RESTAURADO','Anúncio mantido após revisão',"Seu anúncio '{$report->item->titulo}' foi restaurado após análise da denúncia.",['item_id'=>$report->item_id]);
        }
        return response()->json(['message'=>'Denúncia tratada e ação registrada.','denuncia'=>$report->fresh()]);
    }

    public function log(Request $request)
    {
        return response()->json(LogModeracao::with('ator:id,nome')->latest('created_at')->paginate(50));
    }
}
