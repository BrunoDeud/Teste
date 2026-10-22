<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Denuncia;
use App\Models\Item;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function store(Request $request, string $itemId, NotificationService $notifications)
    {
        $data = $request->validate(['motivo'=>['required','string','min:5','max:2000']]);
        $item = Item::findOrFail($itemId);
        if ($item->usuario_id === $request->user()->id) abort(422,'Você não pode denunciar seu próprio anúncio.');
        if (Denuncia::where('item_id',$item->id)->where('denunciante_id',$request->user()->id)->exists()) abort(409,'Você já denunciou este anúncio.');
        $report = Denuncia::create(['item_id'=>$item->id,'denunciante_id'=>$request->user()->id,'motivo'=>trim($data['motivo']),'status'=>'ABERTA']);
        $count = Denuncia::where('item_id',$item->id)->distinct('denunciante_id')->count('denunciante_id');
        if ($count >= 3 && $item->status !== 'OCULTO') {
            $item->update(['status'=>'OCULTO']);
            foreach (User::whereIn('papel',['MODERADOR','ADMINISTRADOR'])->where('status','ATIVO')->get() as $moderator) {
                $notifications->notify($moderator,'ITEM_OCULTO','Anúncio ocultado automaticamente',"O anúncio '{$item->titulo}' atingiu três denúncias e aguarda revisão.",['item_id'=>$item->id]);
            }
        }
        return response()->json(['message'=>'Denúncia registrada. Obrigado por ajudar a manter a comunidade segura.','denuncia'=>$report],201);
    }
}
