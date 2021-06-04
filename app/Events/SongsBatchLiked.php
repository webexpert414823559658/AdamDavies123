<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class SongsBatchLiked extends Event
{
    use SerializesModels;

    public $songs;
    public $user;

    public function __construct(Collection $songs, User $user)
    {
        $this->songs = $songs;
        $this->user = $user;
    }
}
