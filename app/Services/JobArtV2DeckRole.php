<?php

namespace App\Services;

enum JobArtV2DeckRole: string
{
    case MAIN = 'main';
    case SECONDARY = 'secondary';
    case TECH = 'tech';
}
