<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class ItemFoto extends Model { use HasUuids; protected $table='item_fotos'; protected $fillable=['item_id','ordem','caminho']; protected $appends=['url']; public function getUrlAttribute(): string { return \Illuminate\Support\Facades\Storage::disk('public')->url($this->caminho); } public function item(){return $this->belongsTo(Item::class,'item_id');} }
