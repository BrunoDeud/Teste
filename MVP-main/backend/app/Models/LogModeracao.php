<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class LogModeracao extends Model { use HasUuids; public $timestamps=false; protected $table='logs_moderacao'; protected $fillable=['moderador_id','item_id','acao','justificativa','detalhes','created_at']; protected function casts():array{return ['detalhes'=>'array','created_at'=>'datetime'];} public function ator(){return $this->belongsTo(User::class,'moderador_id');} }
