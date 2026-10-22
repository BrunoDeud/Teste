<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class LocalCampus extends Model { use HasUuids; protected $table='locais_campus'; protected $fillable=['nome','bloco','ativo']; protected function casts(): array { return ['ativo'=>'boolean']; } public function pontosGuarda(){return $this->hasMany(PontoGuarda::class,'local_id');} }
