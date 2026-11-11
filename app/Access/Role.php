<?php

namespace App\Access;

/** The role a participant company holds in one portal. */
enum Role
{
    case Administrator;
    case Manager;
    case Viewer;
}
