<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Correspondencia;
use App\Models\Item;
use App\Models\ItemFoto;
use App\Models\LocalCampus;
use App\Models\LogModeracao;
use App\Models\PontoGuarda;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes','nullable','string','max:120'],
            'tipo' => ['sometimes','nullable','in:ACHADO,PERDIDO'],
            'categoria_id' => ['sometimes','nullable','uuid'],
            'local_id' => ['sometimes','nullable','uuid'],
            'data_inicio' => ['sometimes','nullable','date'],
            'data_fim' => ['sometimes','nullable','date','after_or_equal:data_inicio'],
            'status' => ['sometimes','nullable','in:ABERTO,PONTO_GUARDA'],
            'per_page' => ['sometimes','integer','min:1','max:50'],
        ]);
        $query = Item::query()->with(['fotos','categoria:id,nome','local:id,nome,bloco','pontoGuarda:id,nome,local_id'])
            ->whereIn('status', ['ABERTO','PONTO_GUARDA']);
        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            if (DB::getDriverName() === 'mysql' && trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $term)) !== '') {
                $tokens = preg_split('/\s+/u', trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $term))) ?: [];
                $booleanTerm = implode(' ', array_map(fn($word) => '+'.$word.'*', array_filter($tokens)));
                $query->whereRaw('MATCH(titulo, descricao) AGAINST (? IN BOOLEAN MODE)', [$booleanTerm]);
            } else {
                $query->where(fn ($q) => $q->where('titulo','like','%'.$term.'%')->orWhere('descricao','like','%'.$term.'%'));
            }
        }
        foreach (['tipo','categoria_id','local_id','status'] as $filter) {
            if ($request->filled($filter) && $filter !== 'status') $query->where($filter, $request->input($filter));
        }
        if ($request->filled('data_inicio')) $query->whereDate('data_ocorrencia','>=',$request->input('data_inicio'));
        if ($request->filled('data_fim')) $query->whereDate('data_ocorrencia','<=',$request->input('data_fim'));
        if ($request->filled('status') && in_array($request->input('status'), ['ABERTO','PONTO_GUARDA'], true)) $query->where('status',$request->input('status'));
        return response()->json($query->latest()->paginate(min(max((int)$request->input('per_page', 20), 1), 50)));
    }

    public function show(Request $request, string $id)
    {
        $item = Item::with(['fotos','categoria:id,nome','local:id,nome,bloco','pontoGuarda:id,nome,local_id','publicador:id,nome'])->findOrFail($id);
        $viewer = auth('sanctum')->user();
        if ($item->status === 'OCULTO' && $viewer?->id !== $item->usuario_id && !($viewer?->isModerator() ?? false)) abort(404, 'Anúncio não encontrado.');
        $item->makeHidden(['pergunta_verificacao','resposta_verificacao']);
        return response()->json(['item' => $item]);
    }

    public function verificationQuestion(Request $request, string $id)
    {
        $item = Item::findOrFail($id);
        abort_unless($item->tipo === 'ACHADO' && in_array($item->status, ['ABERTO','PONTO_GUARDA'], true), 422, 'Este item não aceita novas reivindicações.');
        abort_unless($item->usuario_id !== $request->user()->id, 422, 'Você não pode reivindicar um item que publicou.');
        abort_if($item->reivindicacoes()->where('status','APROVADA')->exists(), 422, 'Este item já possui uma reivindicação aprovada.');
        return response()->json(['pergunta_verificacao' => $item->pergunta_verificacao]);
    }

    public function store(Request $request, NotificationService $notifications)
    {
        $guardEntry = $request->is('api/moderation/guard-items');
        $data = $request->validate([
            'tipo' => ['required', $guardEntry ? 'in:ACHADO' : 'in:ACHADO,PERDIDO'],
            'titulo' => ['required','string','min:3','max:140'],
            'descricao' => ['required','string','min:5','max:5000'],
            'categoria_id' => ['required','uuid','exists:categorias,id'],
            'local_id' => ['required','uuid','exists:locais_campus,id'],
            'data_ocorrencia' => ['required','date','before_or_equal:today'],
            'pergunta_verificacao' => ['required_if:tipo,ACHADO','nullable','string','max:255'],
            'resposta_verificacao' => ['required_if:tipo,ACHADO','nullable','string','max:1000'],
            'ponto_guarda_id' => [$guardEntry ? 'required' : 'nullable','uuid','exists:pontos_guarda,id'],
            'fotos' => ['sometimes','array','max:3'],
            'fotos.*' => ['image','mimes:jpg,jpeg,png,webp','max:5120'],
        ]);
        $user = $request->user();
        $category = Categoria::findOrFail($data['categoria_id']);
        if (!$category->ativo) throw ValidationException::withMessages(['categoria_id' => 'Selecione uma categoria ativa.']);
        if (!\App\Models\LocalCampus::where('id',$data['local_id'])->where('ativo',true)->exists()) throw ValidationException::withMessages(['local_id' => 'Selecione um local ativo.']);
        $files = $request->file('fotos', []);
        if ($data['tipo'] === 'ACHADO' && count($files) < 1) throw ValidationException::withMessages(['fotos' => 'Itens achados precisam de pelo menos uma foto.']);
        $sensitive = in_array(mb_strtolower($category->nome), ['documentos','cartões','cartoes','eletrônicos','eletronicos'], true);
        if ($sensitive && (!$user->isModerator() || empty($data['ponto_guarda_id']))) {
            throw ValidationException::withMessages(['categoria_id' => 'Documentos, cartões e eletrônicos devem ser cadastrados por um moderador com ponto de guarda definido.']);
        }
        if ($guardEntry && $data['tipo'] !== 'ACHADO') throw ValidationException::withMessages(['tipo' => 'O ponto de guarda só aceita cadastro de itens achados.']);
        if (!empty($data['ponto_guarda_id'])) {
            $guardPoint = PontoGuarda::where('id', $data['ponto_guarda_id'])->where('ativo', true)->first();
            if (!$guardPoint) throw ValidationException::withMessages(['ponto_guarda_id' => 'Selecione um ponto de guarda ativo.']);
            if ($guardPoint->local_id !== $data['local_id']) throw ValidationException::withMessages(['local_id' => 'O local do anúncio deve corresponder ao local do ponto de guarda selecionado.']);
            if (!$user->isModerator()) abort(403, 'Somente moderadores podem registrar itens no ponto de guarda.');
        }
        if ($data['tipo'] === 'ACHADO' && mb_strlen(trim($data['pergunta_verificacao'] ?? '')) < 8) throw ValidationException::withMessages(['pergunta_verificacao' => 'Informe uma pergunta de verificação clara (mínimo de 8 caracteres).']);
        if ($user->itens()->whereIn('status',['ABERTO','PONTO_GUARDA'])->count() >= 10) throw ValidationException::withMessages(['titulo' => 'Você já possui 10 anúncios abertos. Resolva algum antes de publicar outro.']);
        if ($user->itens()->whereDate('created_at',today())->count() >= 5) throw ValidationException::withMessages(['titulo' => 'O limite é de 5 anúncios por dia.']);

        $item = DB::transaction(function () use ($data, $files, $user) {
            $item = Item::create([
                'usuario_id' => $user->id,
                'categoria_id' => $data['categoria_id'], 'local_id' => $data['local_id'],
                'ponto_guarda_id' => $data['ponto_guarda_id'] ?? null,
                'tipo' => $data['tipo'], 'titulo' => trim($data['titulo']), 'descricao' => trim($data['descricao']),
                'data_ocorrencia' => $data['data_ocorrencia'],
                'pergunta_verificacao' => $data['tipo'] === 'ACHADO' ? trim($data['pergunta_verificacao']) : null,
                'resposta_verificacao' => $data['tipo'] === 'ACHADO' ? encrypt(trim($data['resposta_verificacao'])) : null,
                'status' => !empty($data['ponto_guarda_id']) ? 'PONTO_GUARDA' : 'ABERTO',
                'expira_em' => now()->addDays(!empty($data['ponto_guarda_id']) ? 90 : 60), 'atualizado_conteudo_em' => now(),
            ]);
            foreach ($files as $order => $file) {
                $path = $file->store('itens', 'public');
                ItemFoto::create(['item_id' => $item->id, 'ordem' => $order + 1, 'caminho' => $path]);
            }
            return $item;
        });

        if ($data['tipo'] === 'PERDIDO') {
            $suggestions = $this->findMatches($item, $notifications);
        } else {
            $this->findLostMatches($item, $notifications);
            $suggestions = [];
        }
        $item->load(['fotos','categoria:id,nome','local:id,nome,bloco','pontoGuarda:id,nome,local_id']);
        return response()->json(['message' => 'Anúncio publicado com sucesso.', 'item' => $item, 'correspondencias' => $suggestions], 201);
    }

    public function update(Request $request, string $id)
    {
        $item = Item::findOrFail($id);
        abort_unless($request->user()->id === $item->usuario_id, 403, 'Somente quem publicou pode editar o anúncio.');
        abort_unless($item->status === 'ABERTO', 422, 'Somente anúncios abertos podem ser editados.');
        $data = $request->validate([
            'titulo' => ['sometimes','required','string','min:3','max:140'],
            'descricao' => ['sometimes','required','string','min:5','max:5000'],
            'fotos' => ['sometimes','array','max:3'],
            'fotos.*' => ['image','mimes:jpg,jpeg,png,webp','max:5120'],
        ]);
        DB::transaction(function () use ($data, $request, $item) {
            $item->fill(array_intersect_key($data, array_flip(['titulo','descricao'])));
            $item->atualizado_conteudo_em = now();
            $item->expira_em = now()->addDays(60);
            $item->save();
            if ($request->hasFile('fotos')) {
                foreach ($item->fotos as $photo) Storage::disk('public')->delete($photo->caminho);
                $item->fotos()->delete();
                foreach ($request->file('fotos') as $order => $file) $item->fotos()->create(['ordem'=>$order+1,'caminho'=>$file->store('itens','public')]);
            }
        });
        return response()->json(['message' => 'Anúncio atualizado.', 'item' => $item->fresh()->load(['fotos','categoria','local'])]);
    }

    public function claim(Request $request, string $id, NotificationService $notifications)
    {
        $data = $request->validate(['resposta_verificacao' => ['required','string','min:2','max:2000']]);
        $item = Item::with('publicador')->findOrFail($id);
        abort_unless($item->tipo === 'ACHADO' && in_array($item->status,['ABERTO','PONTO_GUARDA'],true), 422, 'Este anúncio não aceita reivindicações.');
        abort_unless($item->usuario_id !== $request->user()->id, 422, 'Você não pode reivindicar um item que publicou.');
        if ($item->reivindicacoes()->where('status','APROVADA')->exists()) abort(422, 'Este item já possui uma reivindicação aprovada.');
        if ($item->reivindicacoes()->where('solicitante_id',$request->user()->id)->whereIn('status',['PENDENTE','APROVADA'])->exists()) abort(422, 'Você já possui uma reivindicação ativa para este item.');
        $claim = DB::transaction(fn () => $item->reivindicacoes()->create(['solicitante_id'=>$request->user()->id,'resposta_verificacao'=>trim($data['resposta_verificacao']),'status'=>'PENDENTE']));
        if ($item->ponto_guarda_id) {
            User::whereIn('papel',['MODERADOR','ADMINISTRADOR'])->where('status','ATIVO')->get()->each(fn ($mod) => $notifications->notify($mod,'NOVA_REIVINDICACAO','Nova reivindicação de item guardado',"Há uma nova reivindicação para '{$item->titulo}'.",['item_id'=>$item->id,'reivindicacao_id'=>$claim->id]));
        } elseif ($item->publicador) {
            $notifications->notify($item->publicador,'NOVA_REIVINDICACAO','Nova reivindicação recebida',"Alguém solicitou a devolução do item '{$item->titulo}'.",['item_id'=>$item->id,'reivindicacao_id'=>$claim->id]);
        }
        return response()->json(['message'=>'Solicitação enviada. O responsável analisará sua resposta.','reivindicacao'=>$claim->makeHidden(['resposta_verificacao'])],201);
    }

    private function findMatches(Item $lost, NotificationService $notifications): array
    {
        $query = Item::with(['fotos','categoria:id,nome','local:id,nome,bloco'])
            ->where('tipo','ACHADO')->whereIn('status',['ABERTO','PONTO_GUARDA'])
            ->where('categoria_id',$lost->categoria_id)
            ->whereDate('data_ocorrencia','>=',$lost->data_ocorrencia)
            ->whereDate('data_ocorrencia','<=',\Carbon\Carbon::parse($lost->data_ocorrencia)->addDays(15));
        $this->applySameBlock($query, $lost);
        $matches = $query->latest()->limit(5)->get();
        foreach ($matches as $found) {
            $pair = Correspondencia::firstOrCreate(['item_perdido_id'=>$lost->id,'item_achado_id'=>$found->id],['pontuacao'=>3]);
            if (!$pair->wasRecentlyCreated) continue;
            if ($lost->publicador) $notifications->notify($lost->publicador,'CORRESPONDENCIA','Possível item encontrado',"Há um possível correspondente para '{$lost->titulo}': '{$found->titulo}'.",['item_id'=>$found->id,'item_perdido_id'=>$lost->id]);
            if ($found->publicador && $found->usuario_id !== $lost->usuario_id) $notifications->notify($found->publicador,'CORRESPONDENCIA','Possível correspondência',"Um anúncio de item perdido pode corresponder a '{$found->titulo}'.",['item_id'=>$found->id,'item_perdido_id'=>$lost->id]);
        }
        return $matches->all();
    }

    private function applySameBlock($query, Item $reference): void
    {
        $local = LocalCampus::find($reference->local_id);
        if ($local && filled($local->bloco)) {
            $query->whereHas('local', fn($localQuery) => $localQuery->where('bloco', $local->bloco));
        } else {
            $query->where('local_id', $reference->local_id);
        }
    }

    private function findLostMatches(Item $found, NotificationService $notifications): void
    {
        $query = Item::with('publicador')->where('tipo','PERDIDO')->where('status','ABERTO')
            ->where('categoria_id',$found->categoria_id)
            ->whereDate('data_ocorrencia','<=',$found->data_ocorrencia)
            ->whereDate('data_ocorrencia','>=',\Carbon\Carbon::parse($found->data_ocorrencia)->subDays(15));
        $this->applySameBlock($query, $found);
        $lostItems = $query->get();
        foreach ($lostItems as $lost) {
            $pair = Correspondencia::firstOrCreate(['item_perdido_id'=>$lost->id,'item_achado_id'=>$found->id],['pontuacao'=>3]);
            if (!$pair->wasRecentlyCreated) continue;
            if ($lost->publicador) $notifications->notify($lost->publicador,'CORRESPONDENCIA','Possível item encontrado',"Há um possível correspondente para '{$lost->titulo}': '{$found->titulo}'.",['item_id'=>$found->id,'item_perdido_id'=>$lost->id]);
            if ($found->publicador && $found->usuario_id !== $lost->usuario_id) $notifications->notify($found->publicador,'CORRESPONDENCIA','Possível correspondência',"Um anúncio de item perdido pode corresponder a '{$found->titulo}'.",['item_id'=>$found->id,'item_perdido_id'=>$lost->id]);
        }
    }
}
