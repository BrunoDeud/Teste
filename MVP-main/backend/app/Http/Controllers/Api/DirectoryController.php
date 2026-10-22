<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\LocalCampus;
use App\Models\PontoGuarda;

class DirectoryController extends Controller
{
    public function categories() { return Categoria::where('ativo', true)->orderBy('nome')->get(['id','nome','descricao']); }
    public function locations() { return LocalCampus::where('ativo', true)->orderBy('nome')->get(['id','nome','bloco']); }
    public function guardPoints() { return PontoGuarda::with('local:id,nome,bloco')->where('ativo', true)->orderBy('nome')->get(['id','local_id','nome','referencia']); }
}
