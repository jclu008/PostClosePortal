<?php

namespace App\Access;

/** Public/Private information type, carried by a membership (P3) and by a document (P4). */
enum InfoType
{
    case PublicSide;
    case PrivateSide;
}
