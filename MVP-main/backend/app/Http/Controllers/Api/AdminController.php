<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\LocalCampus;
use App\Models\PontoGuarda;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function categories() { return response()->json(Categoria::orderBy('nome')->get()); }
    public function createCategory(Request $request) {
        $data=$request->validate(['nome'=>['required','string','max:80','unique:categorias,nome'],'descricao'=>['nullable','string','max:255']]);
        return response()->json(['message'=>'Categoria criada.','categoria'=>Categoria::create($data+['ativo'=>true])],201);
    }
    public function updateCategory(Request $request,string $id) {
        $category=Categoria::findOrFail($id); $data=$request->validate(['nome'=>['sometimes','required','string','max:80','unique:categorias,nome,'.$id],'descricao'=>['nullable','string','max:255'],'ativo'=>['sometimes','boolean']]);
        $category->update($data); return response()->json(['message'=>'Categoria atualizada.','categoria'=>$category->fresh()]);
    }

    public function locations() { return response()->json(LocalCampus::with('pontosGuarda')->orderBy('nome')->get()); }
    public function createLocation(Request $request) {
        $data=$request->validate(['nome'=>['required','string','max:120'],'bloco'=>['nullable','string','max:80']]);
        return response()->json(['message'=>'Local criado.','local'=>LocalCampus::create($data+['ativo'=>true])],201);
    }
    public function updateLocation(Request $request,string $id) {
        $location=LocalCampus::findOrFail($id); $data=$request->validate(['nome'=>['sometimes','required','string','max:120'],'bloco'=>['nullable','string','max:80'],'ativo'=>['sometimes','boolean']]);
        $location->update($data); return response()->json(['message'=>'Local atualizado.','local'=>$location->fresh()]);
    }

    public function guardPoints() { return response()->json(PontoGuarda::with('local:id,nome,bloco')->orderBy('nome')->get()); }
    public function createGuardPoint(Request $request) {
        $data=$request->validate(['local_id'=>['required','uuid','exists:locais_campus,id'],'nome'=>['required','string','max:120'],'referencia'=>['nullable','string','max:255']]);
        return response()->json(['message'=>'Ponto de guarda criado.','ponto'=>PontoGuarda::create($data+['ativo'=>true])],201);
    }
    public function updateGuardPoint(Request $request,string $id) {
        $point=PontoGuarda::findOrFail($id); $data=$request->validate(['local_id'=>['sometimes','required','uuid','exists:locais_campus,id'],'nome'=>['sometimes','required','string','max:120'],'referencia'=>['nullable','string','max:255'],'ativo'=>['sometimes','boolean']]);
        $point->update($data); return response()->json(['message'=>'Ponto de guarda atualizado.','ponto'=>$point->fresh()]);
    }

    public function users() { return response()->json(User::orderBy('nome')->get(['id','nome','email','papel','status','created_at'])); }
    public function updateUserRole(Request $request,string $id) {
        $data=$request->validate(['papel'=>['required','in:USUARIO,MODERADOR,ADMINISTRADOR']]);
        $user=User::findOrFail($id); $user->update(['papel'=>$data['papel']]);
        return response()->json(['message'=>'Perfil atualizado.','usuario'=>$user->only(['id','nome','email','papel','status'])]);
    }
    public function updateUserStatus(Request $request,string $id) {
        $data=$request->validate(['status'=>['required','in:ATIVO,SUSPENSO']]);
        $user=User::findOrFail($id); $user->update(['status'=>$data['status']]);
        if ($data['status'] === 'SUSPENSO') $user->tokens()->delete();
        return response()->json(['message'=>'Status do usuário atualizado.','usuario'=>$user->only(['id','nome','email','papel','status'])]);
    }

    public function domains() { return response()->json(DB::table('dominios_institucionais')->orderBy('dominio')->get()); }
    public function createDomain(Request $request) {
        if ($request->filled('dominio')) $request->merge(['dominio'=>Str::lower(trim((string)$request->input('dominio')))]);
        $data=$request->validate(['dominio'=>['required','string','max:190','regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i','unique:dominios_institucionais,dominio']]);
        $id=DB::table('dominios_institucionais')->insertGetId(['dominio'=>Str::lower($data['dominio']),'ativo'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Domínio institucional adicionado.','dominio'=>DB::table('dominios_institucionais')->find($id)],201);
    }
    public function updateDomain(Request $request,int $id) {
        $data=$request->validate(['ativo'=>['required','boolean']]);
        $exists=DB::table('dominios_institucionais')->where('id',$id)->exists(); abort_unless($exists,404,'Domínio não encontrado.');
        DB::table('dominios_institucionais')->where('id',$id)->update(['ativo'=>$data['ativo'],'updated_at'=>now()]);
        return response()->json(['message'=>'Domínio atualizado.','dominio'=>DB::table('dominios_institucionais')->find($id)]);
    }
}
