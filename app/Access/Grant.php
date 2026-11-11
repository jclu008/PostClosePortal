<?php

namespace App\Access;

/** Per-document, per-company access level (P5). No row at all is represented as null. */
enum Grant
{
    case NoAccess;
    case ReadOnly;
    case ReadAndDownload;
}
