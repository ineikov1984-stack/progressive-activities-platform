<?php

namespace App\Domain\Catalog\Enums;

enum LocationType: string
{
    case Physical = 'physical';
    case Online = 'online';
    case ToBeConfirmed = 'tbc';
}
