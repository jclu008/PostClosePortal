<?php

namespace App\Access;

enum PortalState
{
    case Published;
    case Suspended;
    case Closed;
    case Cancelled;
    case Revoked;
}
