<?php

namespace Consys\Ecosystem\Directory;

/**
 * Which organizations a member reaches in an application.
 */
enum AccessScope: string
{
    case Own = 'own';
    case Selected = 'selected';
    case All = 'all';
}
