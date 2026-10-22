<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Categoria extends Model { use HasUuids; protected $table='categorias'; protected $fillable=['nome','descricao','ativo']; protected function casts(): array { return ['ativo'=>'boolean']; } }
