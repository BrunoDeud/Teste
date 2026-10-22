<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Item;
use App\Models\ItemFoto;
use App\Models\LocalCampus;
use App\Models\PontoGuarda;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('dominios_institucionais')->updateOrInsert(['dominio'=>'campus.edu.br'],['ativo'=>true,'updated_at'=>now(),'created_at'=>now()]);

        $categories = [
            ['nome'=>'Roupas e acessórios','descricao'=>'Roupas, mochilas, bonés e acessórios.'],
            ['nome'=>'Eletrônicos','descricao'=>'Fones, carregadores e aparelhos eletrônicos.'],
            ['nome'=>'Documentos','descricao'=>'Documentos pessoais ou acadêmicos.'],
            ['nome'=>'Cartões','descricao'=>'Cartões de identificação, transporte ou pagamento.'],
            ['nome'=>'Chaves','descricao'=>'Chaves e chaveiros.'],
            ['nome'=>'Livros e materiais','descricao'=>'Livros, cadernos e materiais de aula.'],
            ['nome'=>'Outros','descricao'=>'Objetos que não se encaixam nas categorias.'],
        ];
        foreach ($categories as $category) Categoria::firstOrCreate(['nome'=>$category['nome']], $category+['ativo'=>true]);

        $locations = [
            ['nome'=>'Biblioteca','bloco'=>'A'],
            ['nome'=>'Bloco de salas','bloco'=>'B'],
            ['nome'=>'Laboratórios','bloco'=>'C'],
            ['nome'=>'Cantina','bloco'=>'Central'],
            ['nome'=>'Estacionamento','bloco'=>'Externo'],
        ];
        foreach ($locations as $location) LocalCampus::firstOrCreate($location,['ativo'=>true]);
        $library = LocalCampus::where('nome','Biblioteca')->firstOrFail();
        PontoGuarda::firstOrCreate(['nome'=>'Recepção da Biblioteca','local_id'=>$library->id],['referencia'=>'Balcão principal','ativo'=>true]);

        $admin = User::firstOrCreate(['email'=>'admin@campus.edu.br'],[
            'nome'=>'Administrador de demonstração','senha_hash'=>Hash::make('Admin@12345'),'papel'=>'ADMINISTRADOR','status'=>'ATIVO','emails_ativados'=>true,'email_verificado_em'=>now(),
        ]);
        User::firstOrCreate(['email'=>'moderador@campus.edu.br'],[
            'nome'=>'Moderador de demonstração','senha_hash'=>Hash::make('Moderador@12345'),'papel'=>'MODERADOR','status'=>'ATIVO','emails_ativados'=>true,'email_verificado_em'=>now(),
        ]);
        $student = User::firstOrCreate(['email'=>'estudante@campus.edu.br'],[
            'nome'=>'Estudante de demonstração','senha_hash'=>Hash::make('Estudante@12345'),'papel'=>'USUARIO','status'=>'ATIVO','emails_ativados'=>true,'email_verificado_em'=>now(),
        ]);

        if (!Item::where('titulo','Moletom cinza encontrado')->exists()) {
            $category = Categoria::where('nome','Roupas e acessórios')->firstOrFail();
            $item = Item::create([
                'usuario_id'=>$student->id,'categoria_id'=>$category->id,'local_id'=>$library->id,
                'tipo'=>'ACHADO','titulo'=>'Moletom cinza encontrado','descricao'=>'Moletom cinza encontrado perto das mesas de estudo. Para manter a segurança, confirme um detalhe que não foi publicado.',
                'data_ocorrencia'=>today(),'pergunta_verificacao'=>'Qual detalhe identifica o moletom?','resposta_verificacao'=>encrypt('etiqueta azul no bolso interno'),
                'status'=>'ABERTO','expira_em'=>now()->addDays(60),'atualizado_conteudo_em'=>now(),
            ]);
            ItemFoto::create(['item_id'=>$item->id,'ordem'=>1,'caminho'=>'itens/exemplo-moletom.svg']);
        }
    }
}
