<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

enum ProfileActivityType: string
{
    case JOINED = 'joined';
    case FIRST_IMPORT = 'first_import';
    case IMPORT_MILESTONE = 'import_milestone';
    case POST_PUBLISHED = 'post_published';
    case COMMENT_CREATED = 'comment_created';
    case HOSPITAL_ASSOCIATED = 'hospital_associated';
    case HOSPITAL_DISASSOCIATED = 'hospital_disassociated';
    case HOSPITAL_OWNER_GRANTED = 'hospital_owner_granted';
    case HOSPITAL_OWNER_REVOKED = 'hospital_owner_revoked';
}
