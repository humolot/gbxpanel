<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DockerNote extends Model
{
    protected $fillable = ['type', 'ref', 'note'];

    /** @return array<string, string> ref => note */
    public static function map(string $type): array
    {
        return static::query()->where('type', $type)->pluck('note', 'ref')->all();
    }

    public static function put(string $type, string $ref, ?string $note): void
    {
        $note = trim((string) $note);
        if ($note === '') {
            static::query()->where('type', $type)->where('ref', $ref)->delete();

            return;
        }
        static::query()->updateOrCreate(['type' => $type, 'ref' => $ref], ['note' => mb_substr($note, 0, 255)]);
    }
}
