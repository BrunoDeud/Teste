<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notificacoes()->latest()->paginate(30);
        return response()->json(['nao_lidas'=>$request->user()->notificacoes()->whereNull('lida_em')->count()] + $notifications->toArray());
    }
    public function read(Request $request, string $id)
    {
        $notification = $request->user()->notificacoes()->findOrFail($id);
        $notification->update(['lida_em'=>now()]);
        return response()->json(['message'=>'Notificação marcada como lida.']);
    }
    public function readAll(Request $request)
    {
        $request->user()->notificacoes()->whereNull('lida_em')->update(['lida_em'=>now()]);
        return response()->json(['message'=>'Notificações marcadas como lidas.']);
    }
}
